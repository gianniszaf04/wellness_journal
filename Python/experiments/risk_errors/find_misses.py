import argparse
import json
import os
import subprocess
import sys

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
CSSRS = os.path.join(PY, "data", "processed", "cssrs.csv")
OUT = os.path.join(HERE, "output", "misses.csv")
PHP = os.getenv("PHP_BIN", "/Applications/XAMPP/xamppfiles/bin/php")
RISK_THRESHOLD = 0.3


@torch.no_grad()
def risk_probs(text: str, tok, model) -> tuple[float, float, int]:
    enc = tok(text, truncation=True, padding="max_length", max_length=main.MAX_LEN, stride=main.CHUNK_STRIDE,
              return_overflowing_tokens=True, return_tensors="pt")
    ids = enc["input_ids"][:main.MAX_CHUNKS].to(main.device)
    mask = enc["attention_mask"][:main.MAX_CHUNKS].to(main.device)
    p = F.softmax(model(ids, mask)["risk"], dim=-1)[:, 1].cpu()
    return float(p[0]), float(p.max()), int(p.shape[0])


def keywords(rows: pd.DataFrame) -> list[bool]:
    payload = json.dumps([{"id": i, "text": t} for i, t in zip(rows.id, rows.text)], ensure_ascii=False)
    result = subprocess.run([PHP, os.path.join(HERE, "risk_kw.php")], input=payload, capture_output=True, text=True,
                            check=True)
    found = json.loads(result.stdout)
    return [bool(found[i]) for i in rows.id]


def main_():
    ap = argparse.ArgumentParser()
    ap.add_argument("--model", default="runs/2026-11-02_131743")
    ap.add_argument("--threshold", type=float, default=RISK_THRESHOLD)
    args = ap.parse_args()

    df = pd.read_csv(TRAIN, dtype=str, keep_default_na=False)
    df = df[(df.split == "val") & (df.risk != "")].reset_index(drop=True)

    tok = AutoTokenizer.from_pretrained(MODEL_NAME, revision=MODEL_REVISION)
    model = WellnessMTLModel().to(main.device)
    model.load_state_dict(torch.load(os.path.join(PY, args.model, "mtl_model.pt"), map_location=main.device,
                                     weights_only=True))
    model.eval()
    print(f"[*] {len(df)} κειμενα κινδυνου στο validation | {args.model} | {main.device}")

    probs = [risk_probs(t, tok, model) for t in df.text]
    df["p_first"] = [round(p[0], 4) for p in probs]
    df["p_app"] = [round(p[1], 4) for p in probs]
    df["chunks"] = [p[2] for p in probs]

    yes = df.risk == "1"
    for name, col in (("1ο κομματι (οπως στο training)", "p_first"), ("σπασιμο της εφαρμογης", "p_app")):
        hit = df[col] >= args.threshold
        print(f"[*] {name}: πιανει {int((hit & yes).sum())}/{int(yes.sum())}, "
              f"ψευδεις συναγερμοι {int((hit & ~yes).sum())}/{int((~yes).sum())}")

    miss = df[yes & (df.p_first < args.threshold)].copy()
    src = pd.read_csv(CSSRS, dtype=str, keep_default_na=False).set_index("id")
    miss["user_label"] = [dict(x.split("=", 1) for x in src.loc[i, "meta"].split(";"))["user_label"] for i in miss.id]
    miss["text_src"] = [src.loc[i, "text_src"] for i in miss.id]
    miss["keyword"] = keywords(miss)

    by_app = miss.p_app >= args.threshold
    print(f"[*] χανει: {len(miss)} | πιανει το σπασιμο: {int(by_app.sum())} | πιανουν οι λεξεις-κλειδια: "
          f"{int(miss.keyword.sum())} | πιανει η εφαρμογη: {int((by_app | miss.keyword).sum())}")
    print(f"[*] label χρηστη: {miss.user_label.value_counts().to_dict()}")
    print(f"[*] χρηστες: {miss.id.str.split('-').str[2].nunique()}")

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    cols = ["id", "user_label", "p_first", "p_app", "chunks", "keyword", "text", "text_src"]
    miss[cols].to_csv(OUT, index=False, encoding="utf-8")
    print(f"[+] -> {OUT}")


if __name__ == "__main__":
    main_()
