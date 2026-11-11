import argparse
import json
import os
import re
import sys
import unicodedata

import pandas as pd
import torch
import torch.nn.functional as F
from transformers import AutoTokenizer

PY = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, PY)
import main
from model_architecture import MODEL_NAME, MODEL_REVISION, WellnessMTLModel

TRAIN = os.path.join(PY, "training", "train.csv")
OUT = os.path.join(HERE, "output", "train_fit.csv")
SUMMARY = os.path.join(HERE, "output", "train_fit.json")
RISK_THRESHOLD = 0.3
EXPLICIT = re.compile(r"αυτοκτον|πεθ[αα]ν|πεθαιν|θανατ|\bκοβ|\bκοπ|χαρακ|αυτοτραυμ|σκοτω|τελος στη ζωη|τελειωσ")
NOT_LEARNED = 0.60
LEARNED = 0.85
GAP = 0.30


def normalize(text: str) -> str:
    return "".join(c for c in unicodedata.normalize("NFD", text.lower()) if unicodedata.category(c) != "Mn")


@torch.no_grad()
def risk_prob(text: str, tok, model) -> float:
    enc = tok(text, truncation=True, padding="max_length", max_length=main.MAX_LEN, return_tensors="pt")
    out = model(enc["input_ids"].to(main.device), enc["attention_mask"].to(main.device))["risk"]
    return float(F.softmax(out, dim=-1)[0, 1].cpu())


def recall(rows: pd.DataFrame, threshold: float) -> dict:
    hit = rows.p >= threshold
    return {"n": len(rows), "hit": int(hit.sum()), "recall": round(float(hit.mean()), 3) if len(rows) else None,
            "median_p": round(float(rows.p.median()), 4) if len(rows) else None}


def main_():
    ap = argparse.ArgumentParser()
    ap.add_argument("--model", default="runs/2026-11-02_131743")
    ap.add_argument("--threshold", type=float, default=RISK_THRESHOLD)
    ap.add_argument("--limit", type=int, default=0)
    args = ap.parse_args()

    df = pd.read_csv(TRAIN, dtype=str, keep_default_na=False)
    df = df[(df.risk == "1") & df.split.isin(["train", "val"])].reset_index(drop=True)
    if args.limit:
        df = df.groupby("split").head(args.limit).reset_index(drop=True)
    df["explicit"] = df.text.map(lambda t: bool(EXPLICIT.search(normalize(t))))

    tok = AutoTokenizer.from_pretrained(MODEL_NAME, revision=MODEL_REVISION)
    model = WellnessMTLModel().to(main.device)
    model.load_state_dict(torch.load(os.path.join(PY, args.model, "mtl_model.pt"), map_location=main.device,
                                     weights_only=True))
    model.eval()
    print(f"[*] {len(df)} κειμενα κινδυνου (train {int((df.split == 'train').sum())}, "
          f"val {int((df.split == 'val').sum())}) | {args.model} | {main.device}")

    df["p"] = [round(risk_prob(t, tok, model), 4) for t in df.text]

    groups = {}
    for split in ("train", "val"):
        for name, mask in (("ρητα", df.explicit), ("εμμεσα", ~df.explicit), ("ολα", df.explicit.notna())):
            groups[f"{split} {name}"] = recall(df[(df.split == split) & mask], args.threshold)

    print(f"\n{'ομαδα':<16}{'πληθος':>8}{'πιανει':>8}{'ποσοστο':>10}{'διαμεσος P':>12}")
    for k, g in groups.items():
        if g["n"]:
            print(f"{k:<16}{g['n']:>8}{g['hit']:>8}{g['recall']:>10.1%}{g['median_p']:>12.4f}")

    r_train, r_val = groups["train εμμεσα"]["recall"], groups["val εμμεσα"]["recall"]
    if r_train < NOT_LEARNED:
        verdict = "Α: δεν τα μαθαινει ουτε στο train -> εχει νοημα πειραμα με βαρος / focal loss"
    elif r_train >= LEARNED and r_train - r_val >= GAP:
        verdict = "Β: τα μαθαινει στο train αλλα δεν γενικευει -> το βαρος δεν βοηθα, χρειαζονται καλυτερα labels"
    else:
        verdict = "Γ: ενδιαμεσο -> κανενα απο τα δυο δεν στηριζεται"
    print(f"\n=== Αποφαση κατα το πρωτοκολλο (Β50), εμμεσα: train {r_train:.1%}, val {r_val:.1%}")
    print(f"   {verdict}")
    if args.limit:
        print("   (--limit: δοκιμη του script, τα νουμερα δεν μετρανε)")

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    df[["id", "split", "explicit", "p"]].to_csv(OUT, index=False, encoding="utf-8")
    with open(SUMMARY, "w", encoding="utf-8") as f:
        json.dump({"model": args.model, "threshold": args.threshold, "limit": args.limit, "groups": groups,
                   "verdict": verdict}, f, ensure_ascii=False, indent=2)
    print(f"[+] -> {OUT}\n[+] -> {SUMMARY}")


if __name__ == "__main__":
    main_()
