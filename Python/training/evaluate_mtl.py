import argparse
import json
import os
import sys

import pandas as pd
import torch
from sklearn.metrics import confusion_matrix, precision_recall_fscore_support
from transformers import AutoTokenizer

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from train_mtl import CONFIG_OUT, MERGED_PATH, WEIGHTS_OUT, JournalDataset, make_loader, task_mask
from model_architecture import MODEL_NAME, MODEL_REVISION, WellnessMTLModel, pick_device
from labels import LABEL_SETS, MULTI_LABEL_TASKS, MULTI_LABEL_THRESHOLD, TASKS

APP_RISK_THRESHOLD = 0.3


@torch.no_grad()
def collect(model, loader, device) -> dict:
    model.eval()
    out = {t: {"probs": [], "y": [], "covered": []} for t in TASKS}
    for batch in loader:
        logits = model(batch["input_ids"].to(device), batch["attention_mask"].to(device))
        for t in TASKS:
            mask = task_mask(batch, t, device)
            if mask.sum() == 0:
                continue
            if t in MULTI_LABEL_TASKS:
                out[t]["probs"].append(torch.sigmoid(logits[t][mask]).cpu())
                out[t]["covered"].append(batch[f"{t}_mask"].to(device)[mask].cpu())
            else:
                out[t]["probs"].append(torch.softmax(logits[t][mask], dim=-1).cpu())
            out[t]["y"].append(batch[t].to(device)[mask].cpu())
    return {t: {k: torch.cat(v) for k, v in o.items() if v} for t, o in out.items() if o["probs"]}


def score_row(label: str, p: float, r: float, f: float, n: int, threshold=None) -> dict:
    row = {"label": label, "precision": round(float(p), 3), "recall": round(float(r), 3),
           "f1": round(float(f), 3), "support": int(n)}
    if threshold is not None:
        row["threshold"] = threshold
    return row


def single(t: str, o: dict, threshold: float | None = None, inclusive: bool = False) -> dict:
    y = o["y"].tolist()
    if threshold is None:
        pred = o["probs"].argmax(dim=-1).tolist()
    else:
        p1 = o["probs"][:, 1]
        pred = ((p1 >= threshold) if inclusive else (p1 > threshold)).long().tolist()
    ids = list(range(len(LABEL_SETS[t])))
    p, r, f, n = precision_recall_fscore_support(y, pred, labels=ids, zero_division=0)
    present = [f[i] for i in ids if n[i]]
    return {
        "threshold": "argmax" if threshold is None else threshold,
        "macro_f1": round(sum(present) / len(present), 3) if present else 0.0,
        "accuracy": round(sum(a == b for a, b in zip(y, pred)) / len(y), 3),
        "labels": [score_row(LABEL_SETS[t][i], p[i], r[i], f[i], n[i]) for i in ids],
        "confusion": confusion_matrix(y, pred, labels=ids).tolist(),
    }


def multi(t: str, o: dict, thresholds: dict, trained: list[str]) -> dict:
    rows, tp_all, fp_all, fn_all = [], 0.0, 0.0, 0.0
    for i, label in enumerate(LABEL_SETS[t]):
        if label not in trained:
            continue
        keep = o["covered"][:, i] > 0
        y = o["y"][keep, i]
        th = thresholds.get(label, MULTI_LABEL_THRESHOLD)
        pred = (o["probs"][keep, i] >= th).float()
        tp, fp, fn = float((pred * y).sum()), float((pred * (1 - y)).sum()), float(((1 - pred) * y).sum())
        p = tp / (tp + fp) if tp + fp else 0.0
        r = tp / (tp + fn) if tp + fn else 0.0
        f = 2 * p * r / (p + r) if p + r else 0.0
        rows.append(score_row(label, p, r, f, y.sum(), th))
        tp_all, fp_all, fn_all = tp_all + tp, fp_all + fp, fn_all + fn
    denom = 2 * tp_all + fp_all + fn_all
    return {"micro_f1": round(2 * tp_all / denom, 3) if denom else 1.0, "labels": rows}


def show(title: str, res: dict) -> None:
    head = ", ".join(f"{k}={v}" for k, v in res.items() if k in ("threshold", "macro_f1", "micro_f1", "accuracy"))
    print(f"\n{title}  ({head})")
    print(f"   {'label':<30}{'P':>7}{'R':>7}{'F1':>7}{'N':>7}{'όριο':>7}")
    for r in res["labels"]:
        print(f"   {r['label']:<30}{r['precision']:>7.2f}{r['recall']:>7.2f}{r['f1']:>7.2f}{r['support']:>7}"
              f"{r.get('threshold', ''):>7}")
    if "confusion" in res:
        print("   confusion (γραμμες = σωστο, στηλες = προβλεψη): " + str(res["confusion"]))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--data", default=MERGED_PATH)
    ap.add_argument("--split", default="val", help="val, train ή all")
    ap.add_argument("--weights", default=WEIGHTS_OUT)
    ap.add_argument("--config", default=CONFIG_OUT)
    ap.add_argument("--batch-size", type=int, default=32)
    ap.add_argument("--limit", type=int, default=0)
    ap.add_argument("--out", default=None, help="αρχειο json για τα αποτελεσματα")
    args = ap.parse_args()

    with open(args.config, encoding="utf-8") as f:
        config = json.load(f)
    if config.get("labels") != LABEL_SETS:
        sys.exit(f"[!] Τα labels του {args.config} διαφερουν απο το labels.py")
    trained_tasks = config.get("trained_tasks", [])
    trained_labels = config.get("trained_labels", {})
    thresholds = config.get("thresholds", {})

    df = pd.read_csv(args.data, dtype=str, keep_default_na=False)
    if args.split != "all" and "split" in df.columns:
        df = df[df["split"] == args.split]
    if args.limit:
        df = df.head(args.limit)
    if df.empty:
        sys.exit("[!] Δεν υπαρχουν γραμμες για αξιολογηση")

    device = pick_device()
    tokenizer = AutoTokenizer.from_pretrained(MODEL_NAME, revision=MODEL_REVISION)
    model = WellnessMTLModel().to(device)
    model.load_state_dict(torch.load(args.weights, map_location=device, weights_only=True))
    loader = make_loader(JournalDataset(df, tokenizer), args.batch_size, shuffle=False)
    print(f"[*] {args.data} ({args.split}): {len(df)} γραμμες | {args.weights} | {device}")
    out = collect(model, loader, device)

    results = {"data": args.data, "split": args.split, "rows": len(df), "weights": args.weights, "tasks": {}}
    for t in TASKS:
        if t not in trained_tasks or t not in out:
            continue
        if t in MULTI_LABEL_TASKS:
            res = multi(t, out[t], thresholds.get(t, {}), trained_labels.get(t, LABEL_SETS[t]))
            results["tasks"][t] = res
            show(t, res)
        elif t == "risk":
            results["tasks"][t] = {}
            for name, th in (("argmax", None), ("app", APP_RISK_THRESHOLD)):
                res = single(t, out[t], th, inclusive=True)
                results["tasks"][t][name] = res
                show(f"{t} [{name}]", res)
                yes = res["labels"][1]
                caught = round(yes["recall"] * yes["support"])
                print(f"   -> πιανει {caught} απο τα {yes['support']} κειμενα κινδυνου")
        else:
            th = thresholds.get(t) if isinstance(thresholds.get(t), (int, float)) else None
            res = single(t, out[t], th)
            results["tasks"][t] = res
            show(t, res)

    if args.out:
        with open(args.out, "w", encoding="utf-8") as f:
            json.dump(results, f, ensure_ascii=False, indent=2)
        print(f"\n[+] -> {args.out}")


if __name__ == "__main__":
    main()
