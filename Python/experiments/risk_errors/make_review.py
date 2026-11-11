import argparse
import os
import random
import sys

import pandas as pd

HERE = os.path.dirname(os.path.abspath(__file__))
MISSES = os.path.join(HERE, "output", "misses.csv")
FIRST_PASS = os.path.join(HERE, "first_pass.csv")
REVIEW = os.path.join(HERE, "output", "review.csv")
KEY = os.path.join(HERE, "output", "review_key.csv")
DOUBTFUL = "Γ"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--sample", type=int, default=10)
    ap.add_argument("--seed", type=int, default=42)
    ap.add_argument("--force", action="store_true")
    args = ap.parse_args()

    if os.path.exists(REVIEW) and not args.force:
        sys.exit(f"[!] Υπαρχει ηδη το {REVIEW}. Με --force γραφεται απο την αρχη και χανονται οι κατηγοριες σου.")

    misses = pd.read_csv(MISSES, dtype=str, keep_default_na=False)
    first = pd.read_csv(FIRST_PASS, dtype=str, keep_default_na=False).set_index("id")
    if set(misses.id) != set(first.index):
        sys.exit("[!] Τα id του first_pass.csv διαφερουν απο το misses.csv")

    order = misses.id.tolist()
    doubtful = [i for i in order if first.loc[i, "category"] == DOUBTFUL]
    others = [i for i in order if first.loc[i, "category"] != DOUBTFUL]
    rng = random.Random(args.seed)
    picked = doubtful + rng.sample(others, args.sample)
    rng.shuffle(picked)

    text = misses.set_index("id").text
    ids = [f"R{k:02d}" for k in range(1, len(picked) + 1)]
    review = pd.DataFrame({"id": ids, "text": [text[i] for i in picked], "category": "", "notes": ""})
    review.to_csv(REVIEW, sep=";", index=False, encoding="utf-8-sig", lineterminator="\r\n")
    pd.DataFrame({"review_id": ids, "id": picked}).to_csv(KEY, index=False, encoding="utf-8")
    print(f"[+] {len(review)} κειμενα ({len(doubtful)} + {args.sample} τυχαια) -> {REVIEW}")


if __name__ == "__main__":
    main()
