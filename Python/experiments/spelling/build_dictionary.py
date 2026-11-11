import hashlib
import os
import re
import resource
import sys
import time
from collections import Counter

from symspellpy import SymSpell

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(os.path.dirname(HERE)))
from spelling_service import MAX_DISTANCE, PREFIX_LENGTH, SPELLING_DIR, Corrector, normalize

DIC = os.path.join(HERE, "data", "elspell", "el_GR.dic")
FREQ = os.path.join(HERE, "data", "frequencywords", "el_full.txt")
SHA256 = {DIC: "e5b9b9c2cf05bbc59e03fe302b462dae85968f822f4fc219a8ed2879d6943720",
          FREQ: "7cad3ec81acd202137b6aa997e642b9456e353c3f27b29b6deffdad22ea5d6f5"}
KNOWN_MIN = 100
GREEK = re.compile(r"^[α-ως]+$")
EXAMPLES = ["σήμερα πήγα για πεπατημα", "νιωθω κουρασμενοσ και θυμωμενοσ", "ΕΙΜΑΙ ΠΟΛΥ ΚΟΥΡΑΣΜΕΝΟΣ",
            "πηγασπιτι νωρις", "θελω να βελτιωσουμετην κατασταση", "σ' αγαπώ πολύ", "δώσ' μου λίγο χρόνο",
            "Βγήκα με τον Τζιμ και τη Μαρία", "Ζαφείρης ήρθε αργα", "ειμαι χαρουμενοσ σημερα", "ok ηταν καλα, 2ος γυρος"]


def mb() -> float:
    return resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024 ** 2


def main():
    for path, expected in SHA256.items():
        with open(path, "rb") as f:
            digest = hashlib.sha256(f.read()).hexdigest()
        if digest != expected:
            sys.exit(f"[!] αλλο αρχειο απο αυτο που ελεγχθηκε: {path}")
    print("[*] sha256 ιδια με τη ληψη της 6/11")

    with open(DIC, encoding="iso-8859-7") as f:
        forms = [w for w in f.read().split("\n")[1:] if w]
    elspell = {normalize(w) for w in forms}
    freq, exact = Counter(), Counter()
    with open(FREQ, encoding="utf-8") as f:
        for line in f:
            raw, c = line.rstrip("\n").rsplit(" ", 1)
            exact[raw] += int(c)
            w = normalize(raw)
            if GREEK.match(w):
                freq[w] += int(c)
    total = sum(freq.values())

    candidates = {w: freq[w] for w in elspell if w in freq and not w.endswith("σ")}
    frequent = {w for w, c in freq.items() if c >= KNOWN_MIN and w not in elspell and not w.endswith("σ")}
    known = {w for w in elspell if not w.endswith("σ")} | frequent
    print(f"[*] elspell: {len(elspell)} μορφες (πεζα, χωρις τονους)")
    print(f"[*] υποτιτλοι: {len(freq)} ελληνικες μορφες, {total} εμφανισεις")
    print(f"[*] υποψηφιες διορθωσεις (elspell που εμφανιζονται στους υποτιτλους): {len(candidates)}, "
          f"{sum(candidates.values()) / total:.1%} των εμφανισεων")
    print(f"[*] γνωστες λεξεις: {len(known)} (elspell + {len(frequent)} συχνες των υποτιτλων με >= {KNOWN_MIN})")
    print(f"[*] εξω απο τις γνωστες: τελικο σ {sorted(w for w in elspell if w.endswith('σ'))}")

    accents = {}
    for form in forms:
        n = normalize(form)
        best = accents.get(n)
        if n in candidates and (best is None or (exact[form.lower()], form.islower()) > (exact[best.lower()], best.islower())):
            accents[n] = form
    common = {normalize(form) for form in forms if not form[:1].isupper()}
    propers = {}
    for form in forms:
        n = normalize(form)
        best = propers.get(n)
        if form[:1].isupper() and n not in common and (best is None or exact[form.lower()] > exact[best.lower()]):
            propers[n] = form
    print(f"[*] κυρια ονοματα χωρις κοινη λεξη με την ιδια γραφη: {len(propers)} | "
          + ", ".join(f"{n} -> {propers.get(n, '-')}" for n in ("μαρια", "ελενη", "νικο", "κωστα", "αθηνα", "χαρα", "νικη", "κυριακη")))
    print(f"[*] μορφη με τονους για {len(accents)} υποψηφιες: "
          + ", ".join(f"{n} -> {accents[n]}" for n in ("πηγα", "ποτε", "αλλα", "ελπιδα", "παρα", "αθηνα") if n in accents))

    os.makedirs(SPELLING_DIR, exist_ok=True)
    with open(os.path.join(SPELLING_DIR, "known.txt"), "w", encoding="utf-8") as f:
        f.write("\n".join(sorted(known)) + "\n")
    with open(os.path.join(SPELLING_DIR, "accents.tsv"), "w", encoding="utf-8") as f:
        f.write("".join(f"{n}\t{a}\n" for n, a in sorted(accents.items())))
    with open(os.path.join(SPELLING_DIR, "frequent.txt"), "w", encoding="utf-8") as f:
        f.write("\n".join(sorted(frequent)) + "\n")
    with open(os.path.join(SPELLING_DIR, "propers.tsv"), "w", encoding="utf-8") as f:
        f.write("".join(f"{n}\t{p}\n" for n, p in sorted(propers.items())))
    start, before = time.perf_counter(), mb()
    sym = SymSpell(MAX_DISTANCE, PREFIX_LENGTH)
    for w, c in candidates.items():
        sym.create_dictionary_entry(w, c)
    print(f"[*] SymSpell: {time.perf_counter() - start:.0f}s, μνημη +{mb() - before:.0f} MB")
    sym.save_pickle(os.path.join(SPELLING_DIR, "symspell.pickle"))
    del sym

    start = time.perf_counter()
    corrector = Corrector()
    print(f"[*] φορτωμα του Corrector: {time.perf_counter() - start:.1f}s, μνημη της διεργασιας {mb():.0f} MB")
    for text in EXAMPLES:
        fixed, changes = corrector.correct(text)
        print(f"    {text!r:44} -> {fixed!r}")
        for s in corrector.suggest(text):
            print(f"        προταση: {s['word']} ({s['start']}-{s['end']}) -> {s['suggestions']}")
    print(f"[+] -> {SPELLING_DIR}/known.txt, accents.tsv, frequent.txt, propers.tsv, symspell.pickle")


if __name__ == "__main__":
    main()
