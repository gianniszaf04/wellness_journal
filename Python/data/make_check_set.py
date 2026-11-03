import argparse
import csv
import os
import random
import re
import sys
import unicodedata
from collections import Counter

from common import PY_ROOT, SEED

from labels import LABEL_SETS

TRAIN = os.path.join(PY_ROOT, "training", "train.csv")
ANNOTATION = os.path.join(os.path.dirname(os.path.abspath(__file__)), "annotation")
OUT = os.path.join(ANNOTATION, "check_set.csv")
KEY = os.path.join(ANNOTATION, "check_set_key.csv")
OUT_COLUMNS = ["id", "text", "distortion", "distortion_types", "fatigue", "annotator", "notes"]
KEY_COLUMNS = ["id", "row_id", "source", "group", "stratum", "sentiment", "emotion", "risk", "fatigue_keyword"]
SENTIMENT_SOURCES = {"goemotions", "sentimp"}
NEGATIVE_EMOTIONS = {"Λύπη", "Θυμός", "Φόβος", "Άγχος", "Ντροπή", "Ενοχή"}
FATIGUE = re.compile(r"κουρα[σζ]|εξαντλ|εξουθεν|ξεθεω|ξενυχτ|νυστ|κοπωσ|ενεργεια|κουραγιο|ρακος|αυπν|δεν κοιμ|κοιμηθηκα|υπνο")


def plain(text: str) -> str:
    text = unicodedata.normalize("NFD", text.casefold())
    return "".join(c for c in text if unicodedata.category(c) != "Mn")


def names(task: str, value: str) -> list[str]:
    return [] if value in ("", "none") else [LABEL_SETS[task][int(i)] for i in value.split("|")]


def fatigue_keyword(r: dict) -> bool:
    return bool(FATIGUE.search(plain(r["text"])))


STRATA = [
    ("κούραση", 20, fatigue_keyword),
    ("αρνητικό", 100, lambda r: r["source"] in SENTIMENT_SOURCES and r["sentiment"] == "0"),
    ("αρνητικό xed", 20, lambda r: r["source"] == "xed" and set(names("emotion", r["emotion"])) and
     set(names("emotion", r["emotion"])) <= NEGATIVE_EMOTIONS),
    ("ουδέτερο", 20, lambda r: r["source"] in SENTIMENT_SOURCES and r["sentiment"] == "1"),
    ("θετικό", 20, lambda r: r["source"] in SENTIMENT_SOURCES and r["sentiment"] == "2"),
    ("cssrs", 20, lambda r: r["source"] == "cssrs"),
]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--force", action="store_true", help="σβηνει το υπαρχον check_set.csv, μαζι με τα labels του")
    args = ap.parse_args()
    if os.path.exists(OUT) and not args.force:
        sys.exit(f"[!] Υπαρχει ηδη το {OUT}. Δεν το αλλαζω, για να μη χαθουν labels (--force για να ξαναφτιαχτει).")

    with open(TRAIN, encoding="utf-8", newline="") as f:
        rows = [r for r in csv.DictReader(f) if r["split"] == "val" and r["source"] in {"goemotions", "xed", "cssrs", "sentimp"}]
    rows.sort(key=lambda r: r["id"])
    rng = random.Random(SEED)
    used, chosen = set(), []
    for stratum, n, match in STRATA:
        pool = [r for r in rows if r["group"] not in used and match(r)]
        rng.shuffle(pool)
        if stratum == "κούραση":
            pool.sort(key=lambda r: r["source"] == "cssrs")
        picked = []
        for r in pool:
            if len(picked) == n:
                break
            if r["group"] not in used:
                used.add(r["group"])
                picked.append({**r, "stratum": stratum})
        if len(picked) < n:
            print(f"[!] {stratum}: μονο {len(picked)} απο {n}")
        chosen += picked

    rng.shuffle(chosen)
    os.makedirs(ANNOTATION, exist_ok=True)
    with open(OUT, "w", encoding="utf-8-sig", newline="") as f, open(KEY, "w", encoding="utf-8-sig", newline="") as k:
        out = csv.DictWriter(f, fieldnames=OUT_COLUMNS, delimiter=";")
        key = csv.DictWriter(k, fieldnames=KEY_COLUMNS, delimiter=";")
        out.writeheader()
        key.writeheader()
        for i, r in enumerate(chosen, 1):
            cid = f"C{i:03d}"
            out.writerow({"id": cid, "text": r["text"]})
            key.writerow({"id": cid, "row_id": r["id"], "source": r["source"], "group": r["group"], "stratum": r["stratum"],
                          "sentiment": "|".join(names("sentiment", r["sentiment"])),
                          "emotion": "|".join(names("emotion", r["emotion"])),
                          "risk": "|".join(names("risk", r["risk"])),
                          "fatigue_keyword": "Ναι" if fatigue_keyword(r) else "Όχι"})

    words = [len(r["text"].split()) for r in chosen]
    print(f"{len(chosen)} κειμενα, {sum(words)} λεξεις (διαμεσος {sorted(words)[len(words) // 2]})")
    for stratum, _, _ in STRATA:
        part = [r for r in chosen if r["stratum"] == stratum]
        print(f"  {stratum:<14}{len(part):>4}  " + ", ".join(f"{s}={c}" for s, c in Counter(r["source"] for r in part).most_common()))
    print(f"\n-> {OUT}\n-> {KEY}")


if __name__ == "__main__":
    main()
