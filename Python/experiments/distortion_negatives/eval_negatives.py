import argparse
import os
import sys

import numpy as np
import pandas as pd
from transformers import AutoTokenizer

PY = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, PY)
sys.path.insert(0, os.path.join(PY, "training"))
sys.path.insert(0, os.path.join(PY, "experiments", "ensemble"))
import eval_ensemble as ens
from eval_check_set import NEGATIVE, YES, load as load_check_set, mcnemar
from model_architecture import MODEL_NAME, MODEL_REVISION

BASE = {"s42": "runs/2026-11-01_183329", "s43": "runs/2026-11-02_131743"}
PAIRS = (("s42", "n42"), ("s43", "n43"))
SOURCES = ("goemotions", "sentimp")
MAIN_DROP, CODIPAD_DROP, FOUND_DROP, F1_DROP, RISK_DROP = 0.10, 0.03, 3, 0.02, 10


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--n42", required=True, help="φακελος του run με seed 42, π.χ. runs/2026-11-06_...")
    ap.add_argument("--n43", required=True, help="φακελος του run με seed 43")
    args = ap.parse_args()
    ens.MODELS = {**BASE, "n42": args.n42, "n43": args.n43}
    for name, folder in ens.MODELS.items():
        if not os.path.exists(os.path.join(PY, folder, "mtl_model.pt")):
            sys.exit(f"[!] {name}: δεν υπαρχει {folder}/mtl_model.pt")

    df = pd.read_csv(ens.TRAIN, dtype=str, keep_default_na=False)
    df = df[(df.split == "val") & ((df.sentiment != "") | (df.emotion != "") | (df.distortion != "")
                                   | (df.distortion_types != "") | (df.risk != ""))].reset_index(drop=True)
    check = load_check_set()
    texts = df.text.tolist() + [r["text"] for r in check]
    nv = len(df)
    print(f"validation: {nv} κειμενα | σετ ελεγχου: {len(check)}")

    tok = AutoTokenizer.from_pretrained(MODEL_NAME, revision=MODEL_REVISION)
    raw, decisions = {}, {}
    for name in ens.MODELS:
        raw[name], config, seconds = ens.collect(name, texts, tok)
        decisions[name] = [ens.decide(r, config.get("thresholds", {})) for r in raw[name]]
        diff = {k: sum(d[k] != r["app"][k] for d, r in zip(decisions[name], raw[name])) for k in raw[name][0]["app"]}
        print(f"{name} ({ens.MODELS[name]}): {seconds:.0f}s | διαφορες με το run_mtl "
              + ", ".join(f"{k} {v}" for k, v in diff.items()))

    emotions = set.intersection(*(set(raw[n][0]["emotion"]) for n in raw))
    types = set.intersection(*(set(raw[n][0]["types"]) for n in raw))
    group = np.zeros(nv, dtype=int)
    m = {}
    for name in raw:
        mats = ens.matrices(df, decisions[name][:nv], group, 1, emotions, types)
        m[name] = {t: ens.score(t, mats[t].sum(axis=0)) for t in ens.TASKS}
        m[name]["risk_caught"] = int(mats["risk"].sum(axis=0)[0])

    user = np.array([r["distortion"] == YES for r in check])
    negative = np.array([r["stratum"] in NEGATIVE for r in check])
    neg_no = negative & ~user
    said = {n: np.array([d["distortion"] for d in decisions[n][nv:]]) for n in raw}
    for n in raw:
        m[n]["fp_neg"] = int((said[n] & neg_no).sum())
        m[n]["found"] = int((said[n] & user).sum())
        m[n]["yes"] = int(said[n].sum())
    val_neg = (df.source.isin(SOURCES) & (df.sentiment == "0")).to_numpy()
    for n in raw:
        m[n]["val_neg_yes"] = int(sum(d["distortion"] for d, v in zip(decisions[n][:nv], val_neg) if v))

    n_no, n_user, n_val_neg = int(neg_no.sum()), int(user.sum()), int(val_neg.sum())
    rows = [("Σετ ελεγχου: Ναι σε αρνητικα οπου ο χρηστης ειπε Οχι", lambda x: f"{x['fp_neg']}/{n_no} {x['fp_neg'] / n_no:.0%}"),
            ("Σετ ελεγχου: βρισκει απο τα Ναι του χρηστη", lambda x: f"{x['found']}/{n_user}"),
            ("Σετ ελεγχου: ακριβεια", lambda x: f"{x['found']}/{x['yes']}"),
            ("Validation: διαστρεβλωση F1 (CODIPAD)", lambda x: f"{x['distortion']:.3f}"),
            ("Validation: τυποι micro-F1", lambda x: f"{x['types']:.3f}"),
            ("Validation: sentiment macro-F1", lambda x: f"{x['sentiment']:.3f}"),
            ("Validation: συναισθηματα micro-F1", lambda x: f"{x['emotion']:.3f}"),
            ("Validation: κινδυνος στο 0,3, πιανει", lambda x: f"{x['risk_caught']}"),
            (f"Validation: Ναι σε αρνητικα GoEmotions/SentImp ({n_val_neg})", lambda x: f"{x['val_neg_yes'] / n_val_neg:.0%}")]
    names = ["s42", "n42", "s43", "n43"]
    print(f"\n{'':<58}" + "".join(f"{n:>12}" for n in names))
    for title, fmt in rows:
        print(f"{title:<58}" + "".join(f"{fmt(m[n]):>12}" for n in names))

    print("\nMcNemar στα αρνητικα οπου ο χρηστης ειπε Οχι:")
    for base, new in PAIRS:
        b = int((said[base] & ~said[new] & neg_no).sum())
        c = int((~said[base] & said[new] & neg_no).sum())
        print(f"   {base} -> {new}: Ναι μονο στο {base} {b}, μονο στο {new} {c}, p = {mcnemar(b, c):.3f}")

    def mean(names_, key):
        return float(np.mean([m[n][key] for n in names_]))

    old, new = [p[0] for p in PAIRS], [p[1] for p in PAIRS]
    fp_old, fp_new = mean(old, "fp_neg") / n_no, mean(new, "fp_neg") / n_no
    checks = [
        ("1. Ναι σε αρνητικα Οχι: πεφτει και στα δυο seeds, μεσος ορος -10 μοναδες",
         all(m[b]["fp_neg"] > m[n]["fp_neg"] for b, n in PAIRS) and fp_old - fp_new >= MAIN_DROP,
         f"{fp_old:.0%} -> {fp_new:.0%}"),
        ("2. διαστρεβλωση F1 (CODIPAD) το πολυ -0,03", mean(new, "distortion") >= mean(old, "distortion") - CODIPAD_DROP,
         f"{mean(old, 'distortion'):.3f} -> {mean(new, 'distortion'):.3f}"),
        ("3. βρισκει απο τα Ναι του χρηστη το πολυ -3", mean(new, "found") >= mean(old, "found") - FOUND_DROP,
         f"{mean(old, 'found'):.1f} -> {mean(new, 'found'):.1f}"),
        ("4α. sentiment το πολυ -0,02", mean(new, "sentiment") >= mean(old, "sentiment") - F1_DROP,
         f"{mean(old, 'sentiment'):.3f} -> {mean(new, 'sentiment'):.3f}"),
        ("4β. συναισθηματα το πολυ -0,02", mean(new, "emotion") >= mean(old, "emotion") - F1_DROP,
         f"{mean(old, 'emotion'):.3f} -> {mean(new, 'emotion'):.3f}"),
        ("5. κινδυνος στο 0,3 το πολυ -10", mean(new, "risk_caught") >= mean(old, "risk_caught") - RISK_DROP,
         f"{mean(old, 'risk_caught'):.1f} -> {mean(new, 'risk_caught'):.1f}"),
    ]
    print("\n=== Αποφαση κατα το πρωτοκολλο (Β42), μεσοι οροι n42+n43 απεναντι σε s42+s43")
    for title, ok, values in checks:
        print(f"   {title:<72} {values:<18} {'ναι' if ok else 'ΟΧΙ'}")
    print(f"   -> {'ΠΕΡΝΑΕΙ' if all(ok for _, ok, _ in checks) else 'δεν περναει'}")


if __name__ == "__main__":
    main()
