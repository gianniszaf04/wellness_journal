import argparse
import hashlib
import os
import sys

import pandas as pd

PY = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
HERE = os.path.dirname(os.path.abspath(__file__))
TRAIN = os.path.join(PY, "training", "train.csv")
OUT = os.path.join(HERE, "output", "train.csv")
SOURCES = ("goemotions", "sentimp")
NEGATIVE, NO, NONE = "0", "0", "none"


def md5(path: str) -> str:
    with open(path, "rb") as f:
        return hashlib.md5(f.read()).hexdigest()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default=OUT)
    args = ap.parse_args()

    df = pd.read_csv(TRAIN, dtype=str, keep_default_na=False)
    before = df.copy()
    target = (df.split == "train") & df.source.isin(SOURCES) & (df.sentiment == NEGATIVE) & (df.distortion == "")
    if (df.loc[target, "distortion_types"] != "").any():
        sys.exit("[!] Υπαρχουν γραμμες με τυπους διαστρεβλωσης χωρις label διαστρεβλωσης")

    df.loc[target, "distortion"] = NO
    df.loc[target, "distortion_types"] = NONE

    changed = (df != before).any(axis=1)
    if changed.sum() != target.sum() or (df.split[changed] != "train").any():
        sys.exit("[!] Αλλαξαν γραμμες εκτος απο τις αρνητικες του train")
    if not df[df.split == "val"].equals(before[before.split == "val"]):
        sys.exit("[!] Αλλαξε το validation")

    train = df[df.split == "train"]
    print(f"[*] {TRAIN} (md5 {md5(TRAIN)})")
    print(f"[*] νεα Οχι: {int(target.sum())} | ανα πηγη: {df[target].source.value_counts().to_dict()}")
    print(f"[*] διαστρεβλωση στο train: πριν {before[before.split == 'train'].distortion.value_counts().to_dict()}, "
          f"μετα {train.distortion.value_counts().to_dict()}")
    print(f"[*] validation: ιδιο ({int((df.split == 'val').sum())} γραμμες)")

    os.makedirs(os.path.dirname(args.out), exist_ok=True)
    df.to_csv(args.out, index=False, encoding="utf-8")
    print(f"[+] -> {args.out}")


if __name__ == "__main__":
    main()
