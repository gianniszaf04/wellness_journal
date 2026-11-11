import os
import random
import resource
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
from eval_spelling import KINDS, SEED, add_typos
from spelling_service import MAX_SUGGESTIONS, WORD, Corrector, final_sigma, normalize

TOP1_GAIN, TOP3_LOSS, MIN_TOP3, MAX_TEXTS = 0.01, 0.01, 0.70, 0.10
RANKINGS = {"α": False, "β": True}


def mb() -> float:
    return resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024 ** 2


def main():
    before = mb()
    start = time.perf_counter()
    corrector = Corrector()
    print(f"Corrector: φορτωμα {time.perf_counter() - start:.1f}s, +{mb() - before:.0f} MB")

    df = pd.read_csv(ens.TRAIN, dtype=str, keep_default_na=False)
    df = df[(df.split == "val") & ((df.sentiment != "") | (df.emotion != "") | (df.distortion != "")
                                   | (df.distortion_types != "") | (df.risk != ""))].reset_index(drop=True)
    texts = df.text.tolist()
    rng = random.Random(SEED)
    pairs = [p for t in texts for p in add_typos(t, rng)[1]]
    print(f"validation: {len(texts)} κειμενα | λαθη: {len(pairs)}")

    scores = {}
    for name, phonetic in RANKINGS.items():
        per = {k: Counter() for k in KINDS}
        for kind, noisy, target in pairs:
            options = corrector.candidates(final_sigma(noisy), phonetic)[:MAX_SUGGESTIONS]
            per[kind]["n"] += 1
            per[kind]["top1"] += bool(options) and options[0] == target
            per[kind]["top3"] += target in options
            per[kind]["none"] += not options
        per["ολα"] = sum(per.values(), Counter())
        scores[name] = per

    print(f"\n{'':<12}{'':>6}" + "".join(f"{f'({n}) top-1 / top-3 / καμια':>30}" for n in RANKINGS))
    for kind in (*KINDS, "ολα"):
        row = f"{kind:<12}{scores['α'][kind]['n']:>6}"
        for name in RANKINGS:
            c = scores[name][kind]
            row += f"{c['top1'] / c['n']:>14.0%} / {c['top3'] / c['n']:.0%} / {c['none'] / c['n']:.0%}"
        print(row)

    top1 = {n: scores[n]["ολα"]["top1"] / scores[n]["ολα"]["n"] for n in RANKINGS}
    top3 = {n: scores[n]["ολα"]["top3"] / scores[n]["ολα"]["n"] for n in RANKINGS}
    chosen = "β" if top1["β"] - top1["α"] >= TOP1_GAIN and top3["β"] >= top3["α"] - TOP3_LOSS else "α"

    words = sum(len(WORD.findall(t)) for t in texts)
    burden = {}
    for name, phonetic in RANKINGS.items():
        start = time.perf_counter()
        found = [corrector.suggest(t, phonetic=phonetic) for t in texts]
        ms = (time.perf_counter() - start) / len(texts) * 1000
        flagged = sum(len(s) for s in found)
        burden[name] = sum(1 for s in found if s) / len(texts)
        print(f"\n({name}) σωστα κειμενα: προταση σε {sum(1 for s in found if s)}/{len(texts)} ({burden[name]:.1%}), "
              f"{flagged / len(texts):.2f} ανα κειμενο, {flagged / words * 1000:.1f} ανα 1.000 λεξεις, {ms:.2f} ms ανα κειμενο")
        if name == chosen:
            common = Counter((normalize(s["word"]), s["suggestions"][0]) for f in found for s in f)
            print("   οι 30 πιο συχνες (για ελεγχο απο ανθρωπο):")
            for (w, first), c in common.most_common(30):
                print(f"   {c:>4}  {w} -> {first}")

    ship = top3[chosen] >= MIN_TOP3 and burden[chosen] <= MAX_TEXTS
    print("\n=== Αποφαση κατα το πρωτοκολλο (Β44)")
    print(f"   1. σειρα: (β) top-1 {top1['β']:.1%} απεναντι σε (α) {top1['α']:.1%}, top-3 {top3['β']:.1%} απεναντι σε "
          f"{top3['α']:.1%} -> κραταμε ({chosen})")
    print(f"   2. με το ({chosen}): top-3 {top3[chosen]:.1%} (>= {MIN_TOP3:.0%}), προταση σε {burden[chosen]:.1%} των σωστων "
          f"κειμενων (<= {MAX_TEXTS:.0%}) -> {'ΑΝΟΙΓΕΙ απο προεπιλογη' if ship else 'μενει κλειστη απο προεπιλογη'}")


if __name__ == "__main__":
    main()
