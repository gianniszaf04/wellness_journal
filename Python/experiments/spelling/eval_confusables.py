import os
import random
import sys
import time
from collections import Counter

import pandas as pd

HERE = os.path.dirname(os.path.abspath(__file__))
PY = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, PY)
sys.path.insert(0, os.path.join(PY, "training"))
sys.path.insert(0, os.path.join(PY, "experiments", "ensemble"))
sys.path.insert(0, HERE)
import eval_ensemble as ens
from eval_spelling import SEED, add_typos
from spelling_service import MAX_SUGGESTIONS, WORD, Corrector, final_sigma, normalize

RATIOS = (30, 100, 300)
MAX_TEXTS, MIN_CAUGHT = 0.10, 0.30


def main():
    corrector = Corrector()
    df = pd.read_csv(ens.TRAIN, dtype=str, keep_default_na=False)
    df = df[(df.split == "val") & ((df.sentiment != "") | (df.emotion != "") | (df.distortion != "")
                                   | (df.distortion_types != "") | (df.risk != ""))].reset_index(drop=True)
    texts = df.text.tolist()
    rng = random.Random(SEED)
    noisy = [add_typos(t, rng) for t in texts]
    pairs = [p for _, ps in noisy for p in ps]
    real = [(k, n, t) for k, n, t in pairs if final_sigma(n) in corrector.known and final_sigma(n) != t]
    words = sum(len(WORD.findall(t)) for t in texts)
    print(f"validation: {len(texts)} κειμενα, {words} λεξεις | λαθη: {len(pairs)}, απο αυτα φτιαχνουν σωστη λεξη: {len(real)}"
          f" ({Counter(k for k, _, _ in real).most_common()})")

    base = [corrector.suggest(t) for t in texts]
    print(f"χωρις τον κανονα (Β44): προταση σε {sum(1 for s in base if s) / len(texts):.1%} των σωστων κειμενων")

    results = {}
    for ratio in RATIOS:
        top1 = top3 = 0
        for _, n, t in real:
            options = corrector.confusable(final_sigma(n), ratio)[:MAX_SUGGESTIONS]
            top1 += bool(options) and options[0] == t
            top3 += t in options
        start = time.perf_counter()
        found = [corrector.suggest(t, confusables=ratio) for t in texts]
        ms = (time.perf_counter() - start) / len(texts) * 1000
        extra = [[s for s in f if final_sigma(normalize(s["word"])) in corrector.known] for f in found]
        burden = sum(1 for f in found if f) / len(texts)
        flagged = noise = 0
        for (text, ps) in noisy:
            injected = {final_sigma(n) for _, n, _ in ps}
            for s in corrector.suggest(text, confusables=ratio, limit=10 ** 6):
                w = final_sigma(normalize(s["word"]))
                if w in corrector.known:
                    flagged += 1
                    noise += w in injected
        results[ratio] = (top3 / len(real), burden)
        print(f"\nratio {ratio}: πιανει {top3}/{len(real)} ({top3 / len(real):.0%}) στις 3, 1η {top1 / len(real):.0%}"
              f" | σωστα κειμενα: προταση σε {burden:.1%} (μαζι με Β44), του κανονα {sum(map(len, extra)) / words * 1000:.1f}"
              f" ανα 1.000 λεξεις | στα κειμενα με λαθη: {noise}/{flagged} επισημανσεις ειναι λαθη που βαλαμε"
              f" | {ms:.2f} ms ανα κειμενο")
        common = Counter((normalize(s["word"]), s["suggestions"][0]) for f in extra for s in f)
        print("   οι 30 πιο συχνες στα σωστα κειμενα (για ελεγχο απο ανθρωπο):")
        print("   " + ", ".join(f"{w}->{first} ({c})" for (w, first), c in common.most_common(30)))

    allowed = [r for r in RATIOS if results[r][1] <= MAX_TEXTS]
    best = max(allowed, key=lambda r: (results[r][0], r)) if allowed else None
    print("\n=== Αποφαση κατα το πρωτοκολλο (Β45)")
    print(f"   1. τιμες με βαρος <= {MAX_TEXTS:.0%}: {allowed or 'καμια'} -> {best if best else 'καμια'}")
    on = best is not None and results[best][0] >= MIN_CAUGHT
    print(f"   2. πιανει >= {MIN_CAUGHT:.0%}: " + (f"{results[best][0]:.0%} -> " if best else "")
          + (f"ΑΝΟΙΓΕΙ με ratio {best}" if on else "μενει κλειστος"))


if __name__ == "__main__":
    main()
