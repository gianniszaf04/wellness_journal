# data/prepare_xed.py
# XED, ελληνικες προβολες (github.com/Helsinki-NLP/XED, Öhman et al. 2020, CC BY 4.0) -> interim/xed.csv
# Γεμιζει emotion και, οπου βγαινει καθαρα, sentiment. Ηδη ελληνικα.
# Υποτιτλοι ταινιων με labels προβεβλημενα απο τα αγγλικα -> πιο θορυβωδη απο το GoEmotions.
import os
import re
from collections import Counter

from common import RAW, clean, idx, multi, summary, words, write

SRC = os.path.join(RAW, "xed", "el-projections.tsv")
MIN_WORDS = 3
_LABELS = re.compile(r"^\d(, ?\d)*$")

# Plutchik (XED) -> labels.py. anticipation, disgust, trust δεν αντιστοιχιζονται.
NAMES = {1: "anger", 2: "anticipation", 3: "disgust", 4: "fear", 5: "joy", 6: "sadness", 7: "surprise", 8: "trust"}
EMOTION_MAP = {"anger": "Θυμός", "fear": "Φόβος", "joy": "Χαρά", "sadness": "Λύπη", "surprise": "Έκπληξη"}
POSITIVE = {"joy"}
NEGATIVE = {"anger", "fear", "sadness"}
COVERED = multi("emotion", EMOTION_MAP.values())


def sentiment_of(emo: set) -> str:
    if emo <= POSITIVE:
        return idx("sentiment", "Θετικό")
    if emo <= NEGATIVE:
        return idx("sentiment", "Αρνητικό")
    return ""  # surprise ή μικτο -> χωρις sentiment


def main():
    rows, skipped = [], Counter()
    with open(SRC, encoding="utf-8") as f:
        for n, line in enumerate(f):
            parts = line.rstrip("\n").split("\t")
            # το αρχειο εχει λιγες χαλασμενες γραμμες (ανοιχτα εισαγωγικα, γραμμες με metadata ταινιας)
            if len(parts) != 2 or not _LABELS.match(parts[1].strip()):
                skipped["χαλασμένες"] += 1
                continue
            text = clean(parts[0])
            if words(text) < MIN_WORDS:
                skipped["μικρές"] += 1
                continue
            emo = {NAMES[int(i)] for i in parts[1].split(",")}
            # οπως στο GoEmotions: μονο οταν ολα αντιστοιχιζονται, αλλιως θα διδασκαμε λαθος "κανενα"
            if not emo <= EMOTION_MAP.keys():
                skipped["μη αντιστοιχίσιμα"] += 1
                continue
            rows.append({
                "id": f"xed-{n}",
                "source": "xed",
                "group": f"xed-{n}",
                "lang": "el",
                "text": text,
                "emotion": multi("emotion", [EMOTION_MAP[e] for e in emo]),
                "emotion_covered": COVERED,
                "sentiment": sentiment_of(emo),
                "meta": ",".join(sorted(emo)),
            })
    print(f"[xed] παραλειφθηκαν {dict(skipped)}")
    summary("xed", rows)
    print("->", write("xed", rows))


if __name__ == "__main__":
    main()
