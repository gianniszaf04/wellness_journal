import argparse
import json
import os
import sys
import time

import numpy as np
import pandas as pd
import torch
from transformers import AutoTokenizer

PY = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, PY)
sys.path.insert(0, os.path.join(PY, "training"))
import main
from eval_check_set import NEGATIVE, NO, YES, load as load_check_set
from labels import DISTORTION_TYPE_LABELS, EMOTION_LABELS, LABEL_SETS, MULTI_LABEL_THRESHOLD, SENTIMENT_LABELS
from model_architecture import MODEL_NAME, MODEL_REVISION, WellnessMTLModel

TRAIN = os.path.join(PY, "training", "train.csv")
MODELS = {"s42": "runs/2026-11-01_183329", "s43": "runs/2026-11-02_131743"}
BASE, ENSEMBLE = "s43", "μαζι"
RISK_THRESHOLD = 0.3
TASKS = ("sentiment", "emotion", "distortion", "types", "risk")
TASK_NAMES = {"sentiment": "sentiment macro-F1", "emotion": "συναισθηματα micro-F1",
              "distortion": "διαστρεβλωση F1 (CODIPAD)", "types": "τυποι micro-F1", "risk": "κινδυνος F1 στο 0,3"}
FATIGUE_THRESHOLDS = (0.5, 0.3, 0.2, 0.1)


def indices(value: str) -> list[int]:
    return [] if value in ("", "none") else [int(i) for i in value.split("|")]


def collect(name: str, texts: list[str], tok) -> tuple[list[dict], dict, float]:
    with open(os.path.join(PY, MODELS[name], "mtl_config.json"), encoding="utf-8") as f:
        config = json.load(f)
    if config.get("labels") != LABEL_SETS:
        sys.exit(f"[!] {name}: τα labels του config διαφερουν απο το labels.py")
    model = WellnessMTLModel().to(main.device)
    model.load_state_dict(torch.load(os.path.join(PY, MODELS[name], "mtl_model.pt"), map_location=main.device,
                                     weights_only=True))
    model.eval()
    main.ml.update(tokenizer=tok, model=model, trained_tasks=set(config.get("trained_tasks", [])),
                   trained_labels=config.get("trained_labels", {}), thresholds=config.get("thresholds", {}))
    start = time.perf_counter()
    out = []
    for text in texts:
        d = main.run_mtl(text)["tasks"]
        out.append({"sentiment": np.array([d["sentiment"]["probabilities"][l] for l in SENTIMENT_LABELS]),
                    "emotion": d["emotion"]["probabilities"], "distortion": d["distortion"]["probability"],
                    "types": d["distortion"]["type_probabilities"],
                    "risk": d["risk"]["probability"] if d["risk"] else None,
                    "app": {"sentiment": SENTIMENT_LABELS.index(d["sentiment"]["label"]),
                            "emotion": set(d["emotion"]["labels"]), "distortion": d["distortion"]["detected"],
                            "types": set(d["distortion"]["types"])}})
    seconds = time.perf_counter() - start
    main.ml.clear()
    return out, config, seconds


def average(a: dict, b: dict) -> dict:
    return {"sentiment": (a["sentiment"] + b["sentiment"]) / 2,
            "emotion": {l: (p + b["emotion"][l]) / 2 for l, p in a["emotion"].items() if l in b["emotion"]},
            "distortion": (a["distortion"] + b["distortion"]) / 2,
            "types": {l: (p + b["types"][l]) / 2 for l, p in a["types"].items() if l in b["types"]},
            "risk": None if a["risk"] is None or b["risk"] is None else (a["risk"] + b["risk"]) / 2}


def mean_thresholds(a: dict, b: dict) -> dict:
    out = {}
    for task in set(a) | set(b):
        x, y = a.get(task), b.get(task)
        if isinstance(x, dict) or isinstance(y, dict):
            x, y = x or {}, y or {}
            out[task] = {l: (x.get(l, MULTI_LABEL_THRESHOLD) + y.get(l, MULTI_LABEL_THRESHOLD)) / 2 for l in set(x) | set(y)}
        elif isinstance(x, (int, float)) and isinstance(y, (int, float)):
            out[task] = (x + y) / 2
    return out


def decide(r: dict, th: dict) -> dict:
    limit = th.get("distortion")
    detected = r["distortion"] > (limit if isinstance(limit, (int, float)) else 0.5)
    emo, types = th.get("emotion", {}), th.get("distortion_types", {})
    return {"sentiment": int(np.argmax(r["sentiment"])),
            "emotion": {l for l, p in r["emotion"].items() if p >= emo.get(l, MULTI_LABEL_THRESHOLD)},
            "distortion": bool(detected),
            "types": {l for l, p in r["types"].items() if p >= types.get(l, MULTI_LABEL_THRESHOLD)} if detected else set(),
            "risk": r["risk"] is not None and r["risk"] >= RISK_THRESHOLD}


def f1(tp: float, fp: float, fn: float) -> float:
    return 2 * tp / (2 * tp + fp + fn) if 2 * tp + fp + fn else 0.0


def score(task: str, v: np.ndarray) -> float:
    if task == "sentiment":
        return float(np.mean([f1(*v[3 * k:3 * k + 3]) for k in range(3) if v[3 * k] + v[3 * k + 2] > 0]))
    return f1(*v)


def matrices(df: pd.DataFrame, decisions: list[dict], group: np.ndarray, n_groups: int, emotions: set,
             types: set) -> dict:
    m = {t: np.zeros((n_groups, 9 if t == "sentiment" else 3)) for t in TASKS}

    def add(task, g, truth, pred, labels):
        m[task][g] += [len(truth & pred & labels), len((pred - truth) & labels), len((truth - pred) & labels)]

    for i, r in enumerate(df.itertuples(index=False)):
        g, d = group[i], decisions[i]
        if r.sentiment:
            y, p = int(r.sentiment), d["sentiment"]
            for k in range(3):
                m["sentiment"][g, 3 * k:3 * k + 3] += [p == k and y == k, p == k and y != k, p != k and y == k]
        if r.emotion:
            truth = {EMOTION_LABELS[k] for k in indices(r.emotion)}
            covered = set(EMOTION_LABELS) if not r.emotion_covered else {EMOTION_LABELS[k] for k in indices(r.emotion_covered)}
            add("emotion", g, truth, d["emotion"], (covered | truth) & emotions)
        if r.distortion:
            add("distortion", g, {1} if r.distortion == "1" else set(), {1} if d["distortion"] else set(), {1})
        if r.distortion_types:
            truth = {DISTORTION_TYPE_LABELS[k] for k in indices(r.distortion_types)}
            covered = set(DISTORTION_TYPE_LABELS) if not r.distortion_types_covered else {
                DISTORTION_TYPE_LABELS[k] for k in indices(r.distortion_types_covered)}
            add("types", g, truth, d["types"], (covered | truth) & types)
        if r.risk:
            add("risk", g, {1} if r.risk == "1" else set(), {1} if d["risk"] else set(), {1})
    return m


def auc(pos: np.ndarray, neg: np.ndarray) -> float:
    return float(((pos[:, None] > neg[None, :]).sum() + 0.5 * (pos[:, None] == neg[None, :]).sum()) / (len(pos) * len(neg)))


def main_():
    ap = argparse.ArgumentParser()
    ap.add_argument("--samples", type=int, default=1000)
    args = ap.parse_args()

    df = pd.read_csv(TRAIN, dtype=str, keep_default_na=False)
    df = df[(df.split == "val") & ((df.sentiment != "") | (df.emotion != "") | (df.distortion != "")
                                   | (df.distortion_types != "") | (df.risk != ""))].reset_index(drop=True)
    check = load_check_set()
    texts = df.text.tolist() + [r["text"] for r in check]
    print(f"validation: {len(df)} κειμενα σε {df.group.nunique()} groups | σετ ελεγχου: {len(check)}")

    tok = AutoTokenizer.from_pretrained(MODEL_NAME, revision=MODEL_REVISION)
    raw, configs, seconds = {}, {}, {}
    for name in MODELS:
        raw[name], configs[name], seconds[name] = collect(name, texts, tok)
    th = {name: configs[name].get("thresholds", {}) for name in MODELS}
    th[ENSEMBLE] = mean_thresholds(th["s42"], th["s43"])
    probs = {**raw, ENSEMBLE: [average(a, b) for a, b in zip(raw["s42"], raw["s43"])]}
    decisions = {n: [decide(r, th[n]) for r in probs[n]] for n in probs}
    for name in MODELS:
        diff = {k: sum(d[k] != r["app"][k] for d, r in zip(decisions[name], raw[name])) for k in raw[name][0]["app"]}
        print(f"ελεγχος {name}: διαφορες με το run_mtl " + ", ".join(f"{k} {v}" for k, v in diff.items()))
    print(f"χρονος για {len(texts)} κειμενα: " + ", ".join(f"{n} {s:.0f}s" for n, s in seconds.items())
          + f" -> μαζι ~{sum(seconds.values()):.0f}s")

    nv = len(df)
    group_ids = {g: i for i, g in enumerate(sorted(df.group.unique()))}
    group = np.array([group_ids[g] for g in df.group])
    emotions = set(raw[BASE][0]["emotion"])
    types = set(raw[BASE][0]["types"])
    mats = {n: matrices(df, decisions[n][:nv], group, len(group_ids), emotions, types) for n in probs}
    scores = {n: {t: score(t, mats[n][t].sum(axis=0)) for t in TASKS} for n in probs}

    rng = np.random.default_rng(0)
    diffs = {(o, t): [] for o in MODELS for t in TASKS}
    for _ in range(args.samples):
        w = np.bincount(rng.integers(0, len(group_ids), len(group_ids)), minlength=len(group_ids))
        s = {n: {t: score(t, w @ mats[n][t]) for t in TASKS} for n in probs}
        for o in MODELS:
            for t in TASKS:
                diffs[(o, t)].append(s[ENSEMBLE][t] - s[o][t])
    ci = {k: np.percentile(v, [2.5, 97.5]) for k, v in diffs.items()}

    print(f"\n=== Validation")
    print(f"   {'':<28}{'s42':>7}{'s43':>7}{'μαζι':>7}   {'μαζι − s43 [95%]':<26}μαζι − s42 [95%]")
    for t in TASKS:
        print(f"   {TASK_NAMES[t]:<28}" + "".join(f"{scores[n][t]:>7.3f}" for n in ("s42", "s43", ENSEMBLE)) + "   "
              + "".join(f"{f'{scores[ENSEMBLE][t] - scores[o][t]:+.3f} [{ci[(o, t)][0]:+.3f}, {ci[(o, t)][1]:+.3f}]':<26}"
                        for o in ("s43", "s42")))
    risk = {n: mats[n]["risk"].sum(axis=0) for n in probs}
    positives = int(risk[BASE][0] + risk[BASE][2])
    negatives = int((df.risk == "0").sum())
    print("   κινδυνος στο 0,3: " + " | ".join(
        f"{n} πιανει {int(risk[n][0])}/{positives}, λαθος συναγερμοι {int(risk[n][1])}/{negatives}" for n in probs))

    worse = [t for t in TASKS if scores[ENSEMBLE][t] - scores[BASE][t] < -0.01]
    risk_ok = risk[ENSEMBLE][0] >= risk[BASE][0]
    better = [t for t in TASKS if ci[(BASE, t)][0] > 0]
    print(f"\n=== Αποφαση κατα το πρωτοκολλο (Β36), μαζι απεναντι στο {BASE}")
    print(f"   1. χειροτερο κατα > 0,01: {', '.join(worse) or 'πουθενα'} -> {'οχι' if worse else 'ναι'}")
    print(f"   2. ο κινδυνος δεν πιανει λιγοτερα: {'ναι' if risk_ok else 'οχι'}")
    print(f"   3. καθαρα καλυτερο: {', '.join(better) or 'πουθενα'} -> {'ναι' if better else 'οχι'}")
    print(f"   -> {'ΠΕΡΝΑΕΙ' if not worse and risk_ok and better else 'δεν περναει'}")

    print(f"\n=== Σετ ελεγχου ({len(check)})")
    user = [r["distortion"] == YES for r in check]
    negative = [r["stratum"] in NEGATIVE for r in check]
    for n in probs:
        said = [d["distortion"] for d in decisions[n][nv:]]
        tp = sum(s and u for s, u in zip(said, user))
        fp_neg = sum(s and not u and g for s, u, g in zip(said, user, negative))
        print(f"   {n:<5} διαστρεβλωση: Ναι {sum(said)}, σωστα {tp}/{sum(said)}, βρηκε {tp}/{sum(user)}, "
              f"Ναι σε Οχι {sum(said) - tp}/{len(check) - sum(user)}, σε αρνητικα Οχι {fp_neg}/"
              f"{sum(g and not u for u, g in zip(user, negative))}")

    fatigue = np.array([r["fatigue"] == YES for r in check])
    print(f"\n   Κουραση: {fatigue.sum()} Ναι, {(~fatigue).sum()} Οχι του χρηστη")
    for n in probs:
        p = np.array([r["emotion"].get("Κούραση", 0.0) for r in probs[n][nv:]])
        pos, neg = p[fatigue], p[~fatigue]
        rng = np.random.default_rng(0)
        boot = [auc(rng.choice(pos, len(pos)), rng.choice(neg, len(neg))) for _ in range(args.samples)]
        lo, hi = np.percentile(boot, [2.5, 97.5])
        value = auc(pos, neg)
        verdict = "αξιζει χαμηλοτερο οριο" if value >= 0.70 and lo > 0.5 else "ενα οριο δεν βοηθα"
        print(f"   {n:<5} AUC {value:.2f} [{lo:.2f}, {hi:.2f}] -> {verdict} | διαμεσος P: Ναι {np.median(pos):.3f}, "
              f"Οχι {np.median(neg):.3f}")
        print("         " + " | ".join(f"οριο {t}: βρισκει {int((pos >= t).sum())}/{len(pos)}, λαθος {int((neg >= t).sum())}/{len(neg)}"
                                    for t in FATIGUE_THRESHOLDS))


if __name__ == "__main__":
    main_()
