import os
import sys
import time
from collections import Counter

import pandas as pd

HERE = os.path.dirname(os.path.abspath(__file__))
PY = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, PY)
from ner_service import extract_entities, load_ner, truecase
from spelling_service import WORD, final_sigma, normalize

TRAIN = os.path.join(PY, "training", "train.csv")
PERSON = "Άτομο"
F1_GAIN, PERSON_GAIN, MIN_PERSONS, MIN_PRECISION, CLEAN_F1 = 0.10, 0.20, 50, 0.70, 0.95


def key(entity: dict) -> tuple[str, str]:
    return normalize(entity["name"]).rstrip("ς"), entity["type"]


def score(reference: list[set], found: list[set]) -> dict:
    tp = sum(len(r & f) for r, f in zip(reference, found))
    fp = sum(len(f - r) for r, f in zip(reference, found))
    fn = sum(len(r - f) for r, f in zip(reference, found))
    persons = sum(1 for r in reference for _, t in r if t == PERSON)
    person_tp = sum(1 for r, f in zip(reference, found) for k in r & f if k[1] == PERSON)
    return {"p": tp / (tp + fp) if tp + fp else 0.0, "r": tp / (tp + fn) if tp + fn else 0.0,
            "f1": 2 * tp / (2 * tp + fp + fn) if tp else 0.0, "person_r": person_tp / persons if persons else 0.0}


def run(texts: list[str], truecased: bool) -> list[set]:
    return [{key(e) for e in extract_entities(t, truecased)} for t in texts]


def main():
    load_ner()
    df = pd.read_csv(TRAIN, dtype=str, keep_default_na=False)
    df = df[(df.split == "val") & ((df.sentiment != "") | (df.emotion != "") | (df.distortion != "")
                                   | (df.distortion_types != "") | (df.risk != ""))].reset_index(drop=True)
    texts = {"Σ": df.text.tolist()}
    texts["Μ"] = [t.lower() for t in texts["Σ"]]
    texts["Π"] = [normalize(t) for t in texts["Σ"]]

    start = time.perf_counter()
    reference = run(texts["Σ"], False)
    print(f"validation: {len(df)} κειμενα | αναφορα: {sum(map(len, reference))} ονοματα, "
          f"{dict(Counter(t for r in reference for _, t in r))} | {time.perf_counter() - start:.0f}s")

    m = {}
    for name in ("Π", "Μ", "Σ"):
        for on in (False, True):
            found = reference if name == "Σ" and not on else run(texts[name], on)
            m[name, on] = score(reference, found)
    print(f"\n{'':<10}{'ακριβεια':>12}{'ανακληση':>12}{'F1':>8}{'Ατομο ανακληση':>18}")
    for name in ("Π", "Μ", "Σ"):
        for on in (False, True):
            s = m[name, on]
            print(f"{name} {'με' if on else 'χωρις':<7}{s['p']:>12.3f}{s['r']:>12.3f}{s['f1']:>8.3f}{s['person_r']:>18.3f}")

    changed = Counter()
    for t in texts["Π"]:
        for w in WORD.findall(t):
            new = truecase(w)
            if new != w:
                changed[(w, new)] += 1
    print("\nοι 30 πιο συχνες αλλαγες στο Π (για ελεγχο απο ανθρωπο):")
    print("   " + ", ".join(f"{a}->{b} ({c})" for (a, b), c in changed.most_common(30)))

    persons = sum(1 for r in reference for _, t in r if t == PERSON)
    off, on = m["Π", False], m["Π", True]
    checks = [
        (f"1. Π: F1 +{F1_GAIN}" + (f", Ατομο ανακληση +{PERSON_GAIN}" if persons >= MIN_PERSONS else " (λιγα Ατομο, μονο F1)"),
         on["f1"] - off["f1"] >= F1_GAIN and (persons < MIN_PERSONS or on["person_r"] - off["person_r"] >= PERSON_GAIN),
         f"F1 {off['f1']:.3f} -> {on['f1']:.3f}, Ατομο {off['person_r']:.3f} -> {on['person_r']:.3f}"),
        (f"2. Π: ακριβεια με την επαναφορα >= {MIN_PRECISION}", on["p"] >= MIN_PRECISION, f"{on['p']:.3f}"),
        (f"3. Σ: F1 με την επαναφορα >= {CLEAN_F1}", m["Σ", True]["f1"] >= CLEAN_F1, f"{m['Σ', True]['f1']:.3f}"),
    ]
    print("\n=== Αποφαση κατα το πρωτοκολλο (Β47, Β48)")
    for title, ok, values in checks:
        print(f"   {title:<52} {values:<44} {'ναι' if ok else 'ΟΧΙ'}")
    print(f"   -> {'ΑΝΟΙΓΕΙ απο προεπιλογη' if all(ok for _, ok, _ in checks) else 'μενει κλειστη'}")


if __name__ == "__main__":
    main()
