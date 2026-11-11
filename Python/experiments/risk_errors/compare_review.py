import os
import sys

import pandas as pd
from sklearn.metrics import cohen_kappa_score

HERE = os.path.dirname(os.path.abspath(__file__))
FIRST_PASS = os.path.join(HERE, "first_pass.csv")
REVIEW = os.path.join(HERE, "output", "review.csv")
KEY = os.path.join(HERE, "output", "review_key.csv")
LABELS = os.path.join(HERE, "review_labels.csv")
CATEGORIES = ["Α", "Β", "Γ", "Δ"]
LATIN = {"A": "Α", "B": "Β", "G": "Γ", "D": "Δ"}
REAL_MIN, DOUBTFUL_MAX = 2 / 3, 1 / 4


def category(value: str) -> str:
    v = value.strip().upper()
    return LATIN.get(v, v)


def rules(cats: pd.Series) -> None:
    n = len(cats)
    real = int(cats.isin(["Α", "Β"]).sum())
    doubtful = int((cats == "Γ").sum())
    counts = {c: int((cats == c).sum()) for c in CATEGORIES}
    print(f"    {counts} | Α+Β {real}/{n} = {real / n:.0%} | Γ {doubtful}/{n} = {doubtful / n:.0%}")
    ok = real / n >= REAL_MIN and doubtful / n <= DOUBTFUL_MAX
    print(f"    focal loss: {'επιτρεπεται' if ok else 'δεν επιτρεπεται'} (Α+Β >= 2/3 και Γ <= 1/4)")


def main():
    with open(REVIEW, encoding="utf-8-sig") as f:
        sep = ";" if ";" in f.readline() else ","
    review = pd.read_csv(REVIEW, sep=sep, dtype=str, keep_default_na=False, encoding="utf-8-sig")
    key = pd.read_csv(KEY, dtype=str, keep_default_na=False).set_index("review_id")
    first = pd.read_csv(FIRST_PASS, dtype=str, keep_default_na=False).set_index("id")

    review["category"] = review.category.map(category)
    bad = review[~review.category.isin(CATEGORIES)]
    if len(bad):
        sys.exit(f"[!] Χωρις σωστη κατηγορια (Α, Β, Γ ή Δ): {', '.join(bad.id)}")

    review["train_id"] = [key.loc[r, "id"] for r in review.id]
    review["first_pass"] = [first.loc[i, "category"] for i in review.train_id]
    agree = int((review.category == review.first_pass).sum())
    kappa = cohen_kappa_score(review.first_pass, review.category, labels=CATEGORIES)
    print(f"[*] συμφωνια: {agree}/{len(review)} | kappa {kappa:.2f}")
    print("[*] πινακας (γραμμες = πρωτη κατηγοριοποιηση, στηλες = ελεγχος):")
    print(pd.crosstab(review.first_pass, review.category).reindex(index=CATEGORIES, columns=CATEGORIES, fill_value=0))
    for r in review[review.category != review.first_pass].itertuples():
        print(f"    {r.id}: {r.first_pass} -> {r.category}")

    final = first.category.copy()
    final[review.train_id.tolist()] = review.category.tolist()
    print("[*] πρωτη κατηγοριοποιηση:")
    rules(first.category)
    print("[*] με τον ελεγχο:")
    rules(final)

    review[["id", "train_id", "category"]].rename(columns={"id": "review_id", "train_id": "id"}).to_csv(
        LABELS, index=False, encoding="utf-8")
    print(f"[+] -> {LABELS}")


if __name__ == "__main__":
    main()
