# data/prepare_goemotions.py
# GoEmotions (github.com/google-research/google-research/tree/master/goemotions, Apache 2.0)
# -> interim/goemotions.csv. Γεμιζει emotion και, οπου βγαινει καθαρα, sentiment. Αγγλικα -> θελει μεταφραση.
import csv
import os
import random
from collections import defaultdict

from common import RAW, SEED, clean, idx, multi, summary, words, write

DIR = os.path.join(RAW, "goemotions")
FILES = ["train.tsv", "dev.tsv", "test.tsv"]  # ολα: η αξιολογηση γινεται στο δικο μας test set
PER_EMOTION = 500   # μεχρι τοσα δειγματα ανα συναισθημα του labels.py
NEUTRAL = 500       # + τοσα ουδετερα (κανενα συναισθημα, sentiment=Ουδέτερο)
MIN_WORDS = 3

# GoEmotions -> labels.py. Η Κούραση δεν υπαρχει στο GoEmotions.
EMOTION_MAP = {
    "joy": "Χαρά", "amusement": "Χαρά",
    "sadness": "Λύπη", "grief": "Λύπη", "disappointment": "Λύπη",
    "anger": "Θυμός", "annoyance": "Θυμός",
    "fear": "Φόβος",
    "nervousness": "Άγχος",
    "relief": "Ηρεμία",
    "love": "Αγάπη", "caring": "Αγάπη",
    "surprise": "Έκπληξη",
    "excitement": "Ενθουσιασμός",
    "embarrassment": "Ντροπή",
    "remorse": "Ενοχή",
}

# Ομαδοποιηση sentiment απο το paper (Demszky et al. 2020, Πινακας/Εικ. sentiment grouping).
# Τα "ambiguous" (confusion, curiosity, realization, surprise) δεν δινουν sentiment.
POSITIVE = {"admiration", "amusement", "approval", "caring", "desire", "excitement", "gratitude",
            "joy", "love", "optimism", "pride", "relief"}
NEGATIVE = {"anger", "annoyance", "disappointment", "disapproval", "disgust", "embarrassment",
            "fear", "grief", "nervousness", "remorse", "sadness"}


def sentiment_of(names: set) -> str:
    if names == {"neutral"}:
        return idx("sentiment", "Ουδέτερο")
    if names <= POSITIVE:
        return idx("sentiment", "Θετικό")
    if names <= NEGATIVE:
        return idx("sentiment", "Αρνητικό")
    return ""  # μικτο ή ambiguous -> χωρις sentiment


def main():
    names = open(os.path.join(DIR, "emotions.txt"), encoding="utf-8").read().split()
    candidates = []
    for fn in FILES:
        with open(os.path.join(DIR, fn), encoding="utf-8") as f:
            for line in f:
                text, ids, cid = line.rstrip("\n").split("\t")
                emo = {names[int(i)] for i in ids.split(",")}
                text = clean(text)
                if words(text) < MIN_WORDS:
                    continue
                # Κραταμε μονο οταν ΟΛΑ τα συναισθηματα αντιστοιχιζονται (ή ειναι σκετο neutral).
                # Αλλιως π.χ. ενα "gratitude" θα διδασκε στο μοντελο "κανενα απο τα 10", που ειναι λαθος.
                if emo != {"neutral"} and not emo <= EMOTION_MAP.keys():
                    continue
                candidates.append((cid, text, emo))

    # Ισορροπημενο δειγμα: μεχρι PER_EMOTION ανα συναισθημα + NEUTRAL ουδετερα
    rng = random.Random(SEED)
    rng.shuffle(candidates)
    by_label = defaultdict(list)
    for c in candidates:
        cid, text, emo = c
        if emo == {"neutral"}:
            by_label["neutral"].append(c)
        else:
            for e in {EMOTION_MAP[x] for x in emo}:
                by_label[e].append(c)

    chosen = {}
    for label, items in sorted(by_label.items()):
        limit = NEUTRAL if label == "neutral" else PER_EMOTION
        for c in items[:limit]:
            chosen[c[0]] = c

    rows = []
    for cid, text, emo in chosen.values():
        mapped = [EMOTION_MAP[x] for x in emo if x != "neutral"]
        rows.append({
            "id": f"goemo-{cid}",
            "source": "goemotions",
            "group": f"goemo-{cid}",
            "lang": "en",
            "text": text,
            # κενο multi-label ("") θα σημαινε "χωρις annotation" -> για neutral θελουμε ρητα "κανενα",
            # οποτε το γραφουμε ως "none" και το merge/train το μετατρεπει σε μηδενικο διανυσμα
            "emotion": multi("emotion", mapped) if mapped else "none",
            "sentiment": sentiment_of(emo),
            "meta": ",".join(sorted(emo)),
        })
    rng.shuffle(rows)
    print(f"[goemotions] {len(candidates)} υποψηφια μετα το φιλτραρισμα")
    summary("goemotions", rows)
    print("->", write("goemotions", rows))


if __name__ == "__main__":
    main()
