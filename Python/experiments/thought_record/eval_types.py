import argparse
import json
import os
import sys
from collections import Counter

import pandas as pd
import torch
from transformers import AutoTokenizer

PY = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, PY)
import main
from labels import DISTORTION_TYPE_LABELS, LABEL_SETS
from model_architecture import MODEL_NAME, MODEL_REVISION, WellnessMTLModel

TRAIN = os.path.join(PY, "training", "train.csv")
SUMMARY = os.path.join(HERE, "output", "eval_types.json")
KS = (1, 2, 3)
TOP2_RULE = 0.70


def gold(cell: str) -> set[str]:
    return {DISTORTION_TYPE_LABELS[int(i)] for i in cell.split("|") if i.isdigit()}


def hits(ranked: list[list[str]], golds: list[set[str]], k: int) -> int:
    return sum(bool(set(r[:k]) & g) for r, g in zip(ranked, golds))


def load_model(path: str, tok) -> None:
    with open(os.path.join(PY, path, "mtl_config.json"), encoding="utf-8") as f:
        config = json.load(f)
    if config.get("labels") != LABEL_SETS:
        sys.exit("[!] τα labels του config διαφερουν απο το labels.py")
    model = WellnessMTLModel().to(main.device)
    model.load_state_dict(torch.load(os.path.join(PY, path, "mtl_model.pt"), map_location=main.device,
                                     weights_only=True))
    model.eval()
    main.ml.update(tokenizer=tok, model=model, trained_tasks=set(config.get("trained_tasks", [])),
                   trained_labels=config.get("trained_labels", {}), thresholds=config.get("thresholds", {}))


def main_():
    ap = argparse.ArgumentParser()
    ap.add_argument("--model", default="runs/2026-11-02_131743")
    ap.add_argument("--limit", type=int, default=0)
    args = ap.parse_args()

    df = pd.read_csv(TRAIN, dtype=str, keep_default_na=False)
    cod = df[df.source == "codipad"]
    train_yes = cod[(cod.split == "train") & (cod.distortion == "1")]
    yes = cod[(cod.split == "val") & (cod.distortion == "1")].reset_index(drop=True)
    no = cod[(cod.split == "val") & (cod.distortion == "0")].reset_index(drop=True)
    if args.limit:
        yes, no = yes.head(args.limit), no.head(args.limit)

    tok = AutoTokenizer.from_pretrained(MODEL_NAME, revision=MODEL_REVISION)
    load_model(args.model, tok)
    print(f"[*] CODIPAD validation: {len(yes)} με διαστρεβλωση, {len(no)} χωρις | {args.model} | {main.device}")
    out_yes = [main.run_mtl(t)["tasks"]["distortion"] for t in yes.text]
    out_no = [main.run_mtl(t)["tasks"]["distortion"] for t in no.text]
    main.ml.clear()

    golds = [gold(c) for c in yes.distortion_types]
    model_rank = [sorted(o["type_probabilities"], key=lambda l: -o["type_probabilities"][l]) for o in out_yes]
    freq_rank = [l for l, _ in Counter(l for c in train_yes.distortion_types for l in gold(c)).most_common()]

    print(f"\n[*] τυποι ανα κειμενο: {dict(sorted(Counter(len(g) for g in golds).items()))}")
    print(f"[*] διαμεσο μηκος: {yes.text.str.split().str.len().median():.0f} λεξεις")
    print(f"[*] πιο συχνοι τυποι στο train: {', '.join(freq_rank[:3])}")
    print(f"\n{'':<8}{'μοντελο':>16}{'πιο συχνοι':>16}")
    topk = {}
    for k in KS:
        m, f = hits(model_rank, golds, k), hits([freq_rank] * len(golds), golds, k)
        topk[f"top{k}"] = {"model": m, "frequency": f, "n": len(golds)}
        print(f"top-{k:<4}{m:>8} {m / len(golds):>6.1%}{f:>9} {f / len(golds):>6.1%}")

    said_yes = sum(o["detected"] for o in out_yes)
    said_no = sum(o["detected"] for o in out_no)
    print(f"\n[*] λεει «Ναι» διαστρεβλωσης: {said_yes}/{len(yes)} στα «Ναι», {said_no}/{len(no)} στα «Οχι»")

    model2, freq2 = topk["top2"]["model"] / len(golds), topk["top2"]["frequency"] / len(golds)
    passed = model2 >= TOP2_RULE and model2 > freq2
    print(f"\n=== Κανονας: top-2 >= {TOP2_RULE:.0%} και πανω απο τους πιο συχνους -> "
          f"{'περναει' if passed else 'δεν περναει'} ({model2:.1%} εναντι {freq2:.1%})")
    if args.limit:
        print("   (--limit: δοκιμη του script, τα νουμερα δεν μετρανε)")

    os.makedirs(os.path.dirname(SUMMARY), exist_ok=True)
    with open(SUMMARY, "w", encoding="utf-8") as f:
        json.dump({"model": args.model, "limit": args.limit, "topk": topk, "frequency_rank": freq_rank,
                   "detected": {"yes": [said_yes, len(yes)], "no": [said_no, len(no)]}, "passed": passed},
                  f, ensure_ascii=False, indent=2)
    print(f"[+] -> {SUMMARY}")


if __name__ == "__main__":
    main_()
