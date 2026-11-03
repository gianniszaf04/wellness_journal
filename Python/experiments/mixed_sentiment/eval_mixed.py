import argparse
import csv
import json
import os
import sys

import numpy as np
import pandas as pd
import torch
import torch.nn.functional as F
from transformers import AutoTokenizer

PY = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, PY)
from labels import EMOTION_LABELS, SENTIMENT_LABELS
from mixed_sentiment import combine, long_enough, split
from model_architecture import MODEL_NAME, MODEL_REVISION, WellnessMTLModel, pick_device

EVAL = os.path.join(PY, "data", "synthetic", "mixed_eval.csv")
TRAIN = os.path.join(PY, "training", "train.csv")
MODELS = {"s43": "runs/2026-11-02_131743", "s42": "runs/2026-11-01_183329"}
MAX_LEN, STRIDE, MAX_CHUNKS = 128, 32, 32
MIXED = {"mixed", "balanced"}
T_GRID = np.round(np.arange(0.5, 5.0001, 0.05), 2)


@torch.no_grad()
def infer(model, tok, device, text: str) -> dict:
    enc = tok(text, truncation=True, padding="max_length", max_length=MAX_LEN, stride=STRIDE,
              return_overflowing_tokens=True, return_tensors="pt")
    ids, mask = enc["input_ids"][:MAX_CHUNKS].to(device), enc["attention_mask"][:MAX_CHUNKS].to(device)
    logits = model(ids, mask)
    return {"logits": logits["sentiment"].cpu().numpy(), "tokens": mask.sum(dim=1).cpu().numpy().astype(float),
            "emotion": torch.sigmoid(logits["emotion"]).max(dim=0).values.cpu().numpy()}


def sentiment(r: dict, t: float = 1.0) -> np.ndarray:
    z = r["logits"] / t
    p = np.exp(z - z.max(axis=1, keepdims=True))
    p /= p.sum(axis=1, keepdims=True)
    return (p * r["tokens"][:, None]).sum(axis=0) / r["tokens"].sum()


def fit_temperature(val: list[dict], y: np.ndarray) -> float:
    nll = [-np.mean(np.log(np.clip([sentiment(r, t)[k] for r, k in zip(val, y)], 1e-12, 1))) for t in T_GRID]
    return float(T_GRID[int(np.argmin(nll))])


def analyse(texts: list[str], run) -> list[dict]:
    out = []
    for text in texts:
        pieces = split(text)
        out.append({"whole": run(text), "pieces": pieces, "piece_runs": [run(p["text"]) for p in pieces]})
    return out


def label_b(a: dict, t: float) -> tuple[int, bool, float]:
    r = combine(a["pieces"], [sentiment(x, t).tolist() for x in a["piece_runs"]])
    return SENTIMENT_LABELS.index(r["label"]), r["mixed"], r["score"]


def emotions(a: dict, thresholds: dict, labels: list[str], variant: str) -> set:
    def above(p):
        return {l for l in labels if p[EMOTION_LABELS.index(l)] >= thresholds.get(l, 0.5)}
    if variant == "α":
        return above(a["whole"]["emotion"])
    keep = [x for p, x in zip(a["pieces"], a["piece_runs"]) if long_enough(p)
            and (variant == "β" or sentiment(x)[1] < 0.5)]
    return set().union(*(above(x["emotion"]) for x in keep)) if keep else above(a["whole"]["emotion"])


def micro_f1(pred: list[set], true: list[set], idx) -> float:
    tp = sum(len(pred[i] & true[i]) for i in idx)
    fp = sum(len(pred[i] - true[i]) for i in idx)
    fn = sum(len(true[i] - pred[i]) for i in idx)
    return 2 * tp / (2 * tp + fp + fn) if tp + fp + fn else 0.0


def macro_f1(y: np.ndarray, pred: np.ndarray) -> float:
    f = []
    for k in range(3):
        tp, fp, fn = ((pred == k) & (y == k)).sum(), ((pred == k) & (y != k)).sum(), ((pred != k) & (y == k)).sum()
        if (y == k).any():
            f.append(2 * tp / (2 * tp + fp + fn))
    return float(np.mean(f))


def interval(diffs: list[float]) -> str:
    lo, hi = np.percentile(diffs, [2.5, 97.5])
    return f"[{lo:+.3f}, {hi:+.3f}]"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--models", nargs="*", default=list(MODELS))
    ap.add_argument("--samples", type=int, default=1000)
    args = ap.parse_args()

    with open(EVAL, encoding="utf-8", newline="") as f:
        rows = [r for r in csv.DictReader(f) if r["status"] == "accepted"]
    truth = [json.loads(r["labels"]) for r in rows]
    y = np.array([SENTIMENT_LABELS.index(t["sentiment"]) for t in truth])
    true_emo = [set(t["emotions"]) for t in truth]
    cats = np.array([r["category"] for r in rows])
    mixed_idx, control_idx = np.flatnonzero(np.isin(cats, list(MIXED))), np.flatnonzero(~np.isin(cats, list(MIXED)))
    df = pd.read_csv(TRAIN, dtype=str, keep_default_na=False)
    val = df[(df.split == "val") & df.source.isin(["goemotions", "sentimp"]) & (df.sentiment != "")]
    val_y = val.sentiment.astype(int).values
    print(f"Σετ μικτών: {len(rows)} κειμενα ({len(mixed_idx)} μικτα/ισορροπημενα, {len(control_idx)} ελεγχου)"
          f" | validation: {len(val)} κειμενα με sentiment")

    device = pick_device()
    tok = AutoTokenizer.from_pretrained(MODEL_NAME, revision=MODEL_REVISION)
    results = {}
    for name in args.models:
        model = WellnessMTLModel().to(device)
        model.load_state_dict(torch.load(os.path.join(PY, MODELS[name], "mtl_model.pt"), map_location=device,
                                         weights_only=True))
        model.eval()
        config = json.load(open(os.path.join(PY, MODELS[name], "mtl_config.json"), encoding="utf-8"))
        thresholds = config["thresholds"]["emotion"]
        trained = config.get("trained_labels", {}).get("emotion", EMOTION_LABELS)

        def run(text):
            return infer(model, tok, device, text)

        ev = analyse([r["text"] for r in rows], run)
        va = analyse(val.text.tolist(), run)
        t = fit_temperature([a["whole"] for a in va], val_y)
        pred = {"A": np.array([int(np.argmax(sentiment(a["whole"]))) for a in ev])}
        flags = {}
        for m, temp in (("B", 1.0), ("C", t)):
            out = [label_b(a, temp) for a in ev]
            pred[m] = np.array([o[0] for o in out])
            flags[m] = np.array([o[1] for o in out])
        val_pred = {"A": np.array([int(np.argmax(sentiment(a["whole"]))) for a in va]),
                    "B": np.array([label_b(a, 1.0)[0] for a in va]), "C": np.array([label_b(a, t)[0] for a in va])}
        emo = {v: [emotions(a, thresholds, trained, v) for a in ev] for v in ("α", "β", "γ")}
        everything = np.arange(len(rows))

        rng = np.random.default_rng(0)
        boot = {k: [] for k in ("B−A", "C−A", "C−B", "β−α", "γ−α")}
        for _ in range(args.samples):
            s = rng.choice(mixed_idx, len(mixed_idx))
            acc = {m: (pred[m][s] == y[s]).mean() for m in pred}
            boot["B−A"].append(acc["B"] - acc["A"])
            boot["C−A"].append(acc["C"] - acc["A"])
            boot["C−B"].append(acc["C"] - acc["B"])
            e = rng.choice(everything, len(everything))
            base = micro_f1(emo["α"], true_emo, e)
            boot["β−α"].append(micro_f1(emo["β"], true_emo, e) - base)
            boot["γ−α"].append(micro_f1(emo["γ"], true_emo, e) - base)

        acc_m = {m: (pred[m][mixed_idx] == y[mixed_idx]).mean() for m in pred}
        ok_c = {m: int((pred[m][control_idx] == y[control_idx]).sum()) for m in pred}
        val_f1 = {m: macro_f1(val_y, val_pred[m]) for m in val_pred}
        emo_f1 = {v: micro_f1(emo[v], true_emo, everything) for v in emo}
        results[name] = {"acc": acc_m, "boot": boot, "ok_c": ok_c, "val_f1": val_f1, "emo": emo_f1}

        print(f"\n=== {name} (T = {t:.2f})")
        print(f"   ακριβεια στα μικτα/ισορροπημενα ({len(mixed_idx)}): " + " | ".join(f"{m} {acc_m[m]:.3f}" for m in pred))
        print(f"      B−A {acc_m['B'] - acc_m['A']:+.3f} {interval(boot['B−A'])} | C−A {acc_m['C'] - acc_m['A']:+.3f} "
              f"{interval(boot['C−A'])} | C−B {acc_m['C'] - acc_m['B']:+.3f} {interval(boot['C−B'])}")
        for c in ("mixed", "balanced"):
            i = np.flatnonzero(cats == c)
            print(f"      {c:<9} ({len(i)}): " + " | ".join(f"{m} {int((pred[m][i] == y[i]).sum())}/{len(i)}" for m in pred))
        for k, lab in enumerate(SENTIMENT_LABELS):
            i = mixed_idx[y[mixed_idx] == k]
            if len(i):
                print(f"      σωστο {lab:<9} ({len(i)}): " + " | ".join(
                    f"{m} " + "/".join(str(int((pred[m][i] == j).sum())) for j in range(3)) for m in pred) + "   (Αρν/Ουδ/Θετ)")
        print(f"   ελεγχου, σωστα ({len(control_idx)}): " + " | ".join(f"{m} {ok_c[m]}" for m in pred))
        print(f"   validation macro-F1: " + " | ".join(f"{m} {val_f1[m]:.3f}" for m in val_f1)
              + f" | αλλαξε η ετικετα στο B: {100 * (val_pred['B'] != val_pred['A']).mean():.1f}%")
        print(f"   «μικτη μερα»: " + " | ".join(
            f"{m} μικτα {100 * flags[m][mixed_idx].mean():.0f}%, ελεγχου {100 * flags[m][control_idx].mean():.0f}%" for m in flags))
        print(f"   συναισθηματα micro-F1: " + " | ".join(f"{v} {emo_f1[v]:.3f}" for v in emo_f1)
              + f" | β−α {interval(boot['β−α'])} | γ−α {interval(boot['γ−α'])}")
        wrong = [i for i in mixed_idx if pred["B"][i] != y[i]]
        print(f"   λαθη του B στα μικτα ({len(wrong)}): " + ", ".join(
            f"{rows[i]['id']} σωστο {SENTIMENT_LABELS[y[i]]}, B {SENTIMENT_LABELS[pred['B'][i]]} ({label_b(ev[i], 1.0)[2]:+.2f})"
            for i in wrong))
        del model

    main_model, other = args.models[0], args.models[1] if len(args.models) > 1 else None
    r = results[main_model]
    print(f"\n=== Αποφαση κατα το πρωτοκολλο (Β34), κυριο μοντελο {main_model}")
    for m in ("B", "C"):
        lo = np.percentile(r["boot"][f"{m}−A"], 2.5)
        same = other is None or results[other]["acc"][m] > results[other]["acc"]["A"]
        controls = r["ok_c"][m] >= r["ok_c"]["A"] and r["val_f1"][m] >= r["val_f1"]["A"] - 0.01
        print(f"   {m}: διαστημα πανω απο 0: {'ναι' if lo > 0 else 'οχι'} | ιδια κατευθυνση στο {other}: "
              f"{'ναι' if same else 'οχι'} | ελεγχοι: {'ναι' if controls else 'οχι'} -> "
              f"{'ΠΕΡΝΑΕΙ' if lo > 0 and same and controls else 'δεν περναει'}")
    for v in ("β", "γ"):
        lo = np.percentile(r["boot"][f"{v}−α"], 2.5)
        print(f"   συναισθηματα {v}: {'καλυτερο απο α' if lo > 0 else 'οχι καθαρα καλυτερο απο α, μενει το α'}")


if __name__ == "__main__":
    main()
