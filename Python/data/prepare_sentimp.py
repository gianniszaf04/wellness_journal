# data/prepare_sentimp.py
# SentiMP-Gr (Hugging Face rbnuria/SentiMP-Gr, CC BY-SA 4.0) -> interim/sentimp.csv
# Γεμιζει μονο sentiment. Ηδη ελληνικα.
import csv
import os

from common import RAW, clean, idx, summary, words, write

SRC = os.path.join(RAW, "sentimp_gr", "gr.csv")
GOLD = {"-1": "Αρνητικό", "0": "Ουδέτερο", "1": "Θετικό"}
MIN_WORDS = 3  # tweets που μενουν σχεδον αδεια μετα το καθαρισμα (μονο link/mention)


def main():
    rows, skipped = [], 0
    with open(SRC, encoding="utf-8") as f:
        for i, r in enumerate(csv.DictReader(f)):
            text = clean(r["full_text"])
            if words(text) < MIN_WORDS or r["gold_label"] not in GOLD:
                skipped += 1
                continue
            rows.append({
                "id": f"sentimp-{i}",
                "source": "sentimp",
                "group": f"sentimp-{i}",
                "lang": "el",
                "text": text,
                "sentiment": idx("sentiment", GOLD[r["gold_label"]]),
                # ποσοι απο τους 3 annotators συμφωνουν με το gold (για αναλυση αργοτερα)
                "meta": f"agree={sum(r[k] == r['gold_label'] for k in ('label_1', 'label_2', 'label_3'))}/3",
            })
    print(f"[sentimp] παραλειφθηκαν {skipped}")
    summary("sentimp", rows)
    print("->", write("sentimp", rows))


if __name__ == "__main__":
    main()
