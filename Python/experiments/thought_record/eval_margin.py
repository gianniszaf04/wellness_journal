import argparse
import json
import os
import statistics

import pandas as pd
from transformers import AutoTokenizer

from eval_types import HERE, TRAIN, gold, load_model
import main
from model_architecture import MODEL_NAME, MODEL_REVISION

SUMMARY = os.path.join(HERE, "output", "eval_margin.json")
PRECISION_RULE = 0.70
MIN_CONFIDENT = 50
NO_RULE = 0.10
GRID = [i / 100 for i in range(101)]


def ranked(out: dict) -> tuple[str, float]:
    probs = out["type_probabilities"]
    order = sorted(probs, key=lambda l: -probs[l])
    return order[0], probs[order[0]] - probs[order[1]]


def run(texts) -> list[dict]:
    rows = []
    for text in texts:
        out = main.run_mtl(text)["tasks"]["distortion"]
        top, margin = ranked(out)
        rows.append({"detected": bool(out["detected"]), "top": top, "margin": round(margin, 4)})
    return rows


def stats(yes: list[dict], no: list[dict], delta: float) -> dict:
    sure_yes = [r for r in yes if r["detected"] and r["margin"] >= delta]
    correct = sum(r["correct"] for r in sure_yes)
    sure_no = sum(r["detected"] and r["margin"] >= delta for r in no)
    return {"delta": delta, "confident": len(sure_yes), "correct": correct,
            "precision": correct / len(sure_yes) if sure_yes else None,
            "coverage": len(sure_yes) / len(yes), "confident_no": sure_no, "no_rate": sure_no / len(no)}


def passes(s: dict) -> bool:
    return (s["precision"] is not None and s["precision"] >= PRECISION_RULE
            and s["confident"] >= MIN_CONFIDENT and s["no_rate"] < NO_RULE)


def line(s: dict, mark: str = "") -> str:
    precision = f"{s['precision']:.1%}" if s["precision"] is not None else "-"
    return (f"{s['delta']:>5.2f}{s['confident']:>10}{s['correct']:>8}{precision:>9}{s['coverage']:>9.1%}"
            f"{s['confident_no']:>10}{s['no_rate']:>9.1%}  {mark}")


def main_():
    ap = argparse.ArgumentParser()
    ap.add_argument("--model", default="runs/2026-11-02_131743")
    ap.add_argument("--limit", type=int, default=0)
    args = ap.parse_args()

    df = pd.read_csv(TRAIN, dtype=str, keep_default_na=False)
    cod = df[(df.source == "codipad") & (df.split == "val")]
    yes = cod[cod.distortion == "1"].reset_index(drop=True)
    no = cod[cod.distortion == "0"].reset_index(drop=True)
    if args.limit:
        yes, no = yes.head(args.limit), no.head(args.limit)

    tok = AutoTokenizer.from_pretrained(MODEL_NAME, revision=MODEL_REVISION)
    load_model(args.model, tok)
    print(f"[*] CODIPAD validation: {len(yes)} με διαστρεβλωση, {len(no)} χωρις | {args.model} | {main.device}")
    rows_yes = run(yes.text)
    rows_no = run(no.text)
    main.ml.clear()
    for r, cell in zip(rows_yes, yes.distortion_types):
        r["correct"] = r["top"] in gold(cell)

    table = [stats(rows_yes, rows_no, d) for d in GRID]
    chosen = next((s for s in table if passes(s)), None)

    top1 = sum(r["correct"] for r in rows_yes)
    print(f"\n[*] top-1 σε ολα τα «Ναι»: {top1}/{len(rows_yes)} ({top1 / len(rows_yes):.1%})")
    detected = [r for r in rows_yes if r["detected"]]
    for name, group in (("σωστος", [r for r in detected if r["correct"]]),
                        ("λαθος", [r for r in detected if not r["correct"]])):
        if group:
            print(f"[*] διαφορα 1ου-2ου οταν ο 1ος ειναι {name}: διαμεσος {statistics.median(r['margin'] for r in group):.3f}"
                  f" ({len(group)} κειμενα)")

    print(f"\n{'Δ':>5}{'σιγουρα':>10}{'σωστα':>8}{'ακριβ.':>9}{'καλυψη':>9}{'σιγ. Οχι':>10}{'% Οχι':>9}")
    for s in table:
        if round(s["delta"] * 100) % 5 == 0 or s is chosen:
            print(line(s, "<- επιλεγεται" if s is chosen else ""))

    print(f"\n=== Κανονας: μικροτερο Δ με ακριβεια >= {PRECISION_RULE:.0%}, >= {MIN_CONFIDENT} σιγουρα κειμενα "
          f"και < {NO_RULE:.0%} σιγουρα στα «Οχι»")
    if chosen:
        print(f"    Δ = {chosen['delta']:.2f}: ακριβεια {chosen['precision']:.1%} σε {chosen['confident']} κειμενα, "
              f"καλυψη {chosen['coverage']:.1%}, σιγουρο σε {chosen['no_rate']:.1%} των «Οχι»")
    else:
        print("    κανενα Δ δεν περναει: το μοντελο δεν θεωρειται ποτε σιγουρο, γινονται παντα ερωτησεις")
    if args.limit:
        print("   (--limit: δοκιμη του script, τα νουμερα δεν μετρανε)")

    os.makedirs(os.path.dirname(SUMMARY), exist_ok=True)
    with open(SUMMARY, "w", encoding="utf-8") as f:
        json.dump({"model": args.model, "limit": args.limit,
                   "rule": {"precision": PRECISION_RULE, "min_confident": MIN_CONFIDENT, "no_rate": NO_RULE},
                   "top1": [top1, len(rows_yes)], "chosen": chosen, "table": table},
                  f, ensure_ascii=False, indent=2)
    print(f"[+] -> {SUMMARY}")


if __name__ == "__main__":
    main_()
