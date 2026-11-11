import argparse
import os
import random
import resource
import sys
import time
from collections import Counter

import numpy as np
import pandas as pd
from transformers import AutoTokenizer

HERE = os.path.dirname(os.path.abspath(__file__))
PY = os.path.dirname(os.path.dirname(HERE))
sys.path.insert(0, PY)
sys.path.insert(0, os.path.join(PY, "training"))
sys.path.insert(0, os.path.join(PY, "experiments", "ensemble"))
sys.path.insert(0, HERE)
import eval_ensemble as ens
from spelling_service import WORD, Corrector, final_sigma, normalize
from model_architecture import MODEL_NAME, MODEL_REVISION

MODEL = "s43"
RATE, SEED, MIN_LETTERS = 0.10, 42, 4
CLEAN_DROP, RISK_DROP, TYPO_RESTORE, SIGMA_RESTORE, MIN_FLIPS = 0.005, 1, 0.50, 0.90, 30
KINDS = ("λειπει", "παραπανω", "γειτονικο", "αναποδα", "φωνηεν", "κολλημενη")
KEYBOARD = ((None, "ς", "ε", "ρ", "τ", "υ", "θ", "ι", "ο", "π"),
            ("α", "σ", "δ", "φ", "γ", "η", "ξ", "κ", "λ"),
            ("ζ", "χ", "ψ", "ω", "β", "ν", "μ"))
VOWELS = {"ι": ("η", "υ"), "η": ("ι", "υ"), "υ": ("ι", "η"), "ο": ("ω",), "ω": ("ο",), "ε": ("αι",)}
DIGRAPHS = {"ει": "ι", "οι": "ι", "αι": "ε"}
FIXED_PAIRS = ("ου", "αυ", "ευ")
DECISIONS = ("sentiment", "emotion", "distortion", "risk")
F1_TASKS = ("sentiment", "emotion", "distortion", "types")
CONDITIONS = ("Α", "Β1", "Γ1", "Β2", "Γ2", "Δ")


def mb() -> float:
    return resource.getrusage(resource.RUSAGE_SELF).ru_maxrss / 1024 ** 2


def neighbors(c: str) -> list[str]:
    for r, row in enumerate(KEYBOARD):
        if c in row:
            k = row.index(c)
            near = ((r, k - 1), (r, k + 1), (r - 1, k), (r - 1, k + 1), (r + 1, k - 1), (r + 1, k))
            return [KEYBOARD[i][j] for i, j in near
                    if 0 <= i < len(KEYBOARD) and 0 <= j < len(KEYBOARD[i]) and KEYBOARD[i][j] not in (None, "ς")]
    return []


def typo(w: str, kind: str, rng: random.Random) -> str | None:
    i = rng.randrange(len(w))
    if kind == "λειπει":
        return w[:i] + w[i + 1:]
    if kind == "παραπανω":
        return w[:i + 1] + rng.choice([w[i]] + neighbors(w[i])) + w[i + 1:]
    if kind == "γειτονικο":
        near = neighbors(w[i])
        return w[:i] + rng.choice(near) + w[i + 1:] if near else None
    if kind == "αναποδα":
        i = rng.randrange(len(w) - 1)
        return w[:i] + w[i + 1] + w[i] + w[i + 2:] if w[i] != w[i + 1] else None
    inside = {j + d for j in range(len(w) - 1) if w[j:j + 2] in DIGRAPHS or w[j:j + 2] in FIXED_PAIRS for d in (0, 1)}
    options = [(j, 2, DIGRAPHS[w[j:j + 2]]) for j in range(len(w) - 1) if w[j:j + 2] in DIGRAPHS]
    options += [(j, 1, s) for j, c in enumerate(w) if c in VOWELS and j not in inside for s in VOWELS[c]]
    if not options:
        return None
    j, n, s = rng.choice(options)
    return w[:j] + s + w[j + n:]


def add_typos(text: str, rng: random.Random) -> tuple[str, list[tuple[str, str, str]]]:
    matches = list(WORD.finditer(text))
    out, pairs, last, skip = [], [], 0, False
    for k, m in enumerate(matches):
        if skip:
            skip = False
            continue
        w = normalize(m.group(0))
        if len(w) < MIN_LETTERS or rng.random() >= RATE:
            continue
        kind, end, target, noisy = rng.choice(KINDS), m.end(), w, None
        if kind == "κολλημενη":
            nxt = matches[k + 1] if k + 1 < len(matches) else None
            if nxt and text[m.end():nxt.start()] == " ":
                second = normalize(nxt.group(0))
                noisy, end, target, skip = w + second, nxt.end(), f"{w} {second}", True
        else:
            noisy = typo(w, kind, rng)
        if not noisy or noisy == w:
            kind, noisy = "λειπει", typo(w, "λειπει", rng)
        out += [text[last:m.start()], noisy]
        last = end
        pairs.append((kind, noisy, target))
    out.append(text[last:])
    return "".join(out), pairs


def key(d: dict, task: str):
    return frozenset(d[task]) if task == "emotion" else d[task]


def restore(base: list, noisy: list, fixed: list) -> tuple[int, int, int]:
    flipped = restored = new = 0
    for a, b, c in zip(base, noisy, fixed):
        for t in DECISIONS:
            if key(b, t) != key(a, t):
                flipped += 1
                restored += key(c, t) == key(a, t)
            elif key(c, t) != key(a, t):
                new += 1
    return flipped, restored, new


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--limit", type=int, default=0, help="μονο τα πρωτα N κειμενα (δοκιμη του script)")
    args = ap.parse_args()

    before = mb()
    start = time.perf_counter()
    corrector = Corrector()
    load_seconds, corrector_mb = time.perf_counter() - start, mb() - before

    df = pd.read_csv(ens.TRAIN, dtype=str, keep_default_na=False)
    df = df[(df.split == "val") & ((df.sentiment != "") | (df.emotion != "") | (df.distortion != "")
                                   | (df.distortion_types != "") | (df.risk != ""))].reset_index(drop=True)
    if args.limit:
        df = df.head(args.limit)
        print(f"[!] ΔΟΚΙΜΗ ΤΟΥ SCRIPT με {len(df)} κειμενα: τα νουμερα δεν μετρανε")
    texts = {"Α": df.text.tolist()}
    texts["Β1"] = [t.replace("ς", "σ") for t in texts["Α"]]
    rng = random.Random(SEED)
    noisy = [add_typos(t, rng) for t in texts["Α"]]
    texts["Β2"] = [t for t, _ in noisy]
    pairs = [p for _, ps in noisy for p in ps]

    changes = {}
    for name, source in (("Γ1", "Β1"), ("Γ2", "Β2"), ("Δ", "Α")):
        start = time.perf_counter()
        fixed = [corrector.correct(t) for t in texts[source]]
        if name == "Δ":
            per_text = (time.perf_counter() - start) / len(fixed) * 1000
        texts[name] = [t for t, _ in fixed]
        changes[name] = [c for _, cs in fixed for c in cs]
    print(f"validation: {len(df)} κειμενα | Corrector: φορτωμα {load_seconds:.1f}s, +{corrector_mb:.0f} MB, "
          f"{per_text:.2f} ms ανα κειμενο")

    tok = AutoTokenizer.from_pretrained(MODEL_NAME, revision=MODEL_REVISION)
    ids = {name: tok(t)["input_ids"] for name, t in texts.items()}
    raw_a, config, seconds = ens.collect(MODEL, texts["Α"], tok)
    print(f"Α: {seconds:.0f}s")
    raw = {"Α": raw_a}
    for name in CONDITIONS[1:]:
        diff = [i for i, x in enumerate(ids[name]) if x != ids["Α"][i]]
        raw[name] = list(raw_a)
        if diff:
            sub, _, seconds = ens.collect(MODEL, [texts[name][i] for i in diff], tok)
            for i, r in zip(diff, sub):
                raw[name][i] = r
            print(f"{name}: ξανα {len(diff)} κειμενα, {seconds:.0f}s")

    thresholds = config.get("thresholds", {})
    decisions = {n: [ens.decide(r, thresholds) for r in raw[n]] for n in CONDITIONS}
    emotions, types = set(raw_a[0]["emotion"]), set(raw_a[0]["types"])
    group = np.zeros(len(df), dtype=int)
    m = {}
    for n in CONDITIONS:
        mats = ens.matrices(df, decisions[n], group, 1, emotions, types)
        m[n] = {t: ens.score(t, mats[t].sum(axis=0)) for t in ens.TASKS}
        m[n]["caught"], m[n]["false"] = (int(x) for x in mats["risk"].sum(axis=0)[:2])
        m[n]["flips"] = sum(key(d, t) != key(a, t) for a, d in zip(decisions["Α"], decisions[n]) for t in DECISIONS)
    n_pos, n_neg = int((df.risk == "1").sum()), int((df.risk == "0").sum())

    rows = [("sentiment macro-F1", lambda x: f"{x['sentiment']:.3f}"),
            ("συναισθηματα micro-F1", lambda x: f"{x['emotion']:.3f}"),
            ("διαστρεβλωση F1 (CODIPAD)", lambda x: f"{x['distortion']:.3f}"),
            ("τυποι micro-F1", lambda x: f"{x['types']:.3f}"),
            (f"κινδυνος στο 0,3: πιανει απο {n_pos}", lambda x: f"{x['caught']}"),
            (f"κινδυνος στο 0,3: λαθος συναγερμοι απο {n_neg}", lambda x: f"{x['false']}"),
            ("αποφασεις διαφορετικες απο το Α", lambda x: f"{x['flips']}")]
    print(f"\n{'':<44}" + "".join(f"{n:>9}" for n in CONDITIONS))
    for title, fmt in rows:
        print(f"{title:<44}" + "".join(f"{fmt(m[n]):>9}" for n in CONDITIONS))

    words = sum(len(WORD.findall(t)) for t in texts["Α"])
    d_words = Counter(c for c in changes["Δ"] if c[1] != final_sigma(normalize(c[0])))
    print(f"\nΔ: η διορθωση αλλαζει {len(changes['Δ'])} απο {words} ελληνικες λεξεις ({len(changes['Δ']) / words:.2%})"
          f" | τελικο σ ή κεφαλαια {len(changes['Δ']) - sum(d_words.values())}, αλλη λεξη {sum(d_words.values())}")
    print("   οι 30 πιο συχνες αλλαγες (για ελεγχο απο ανθρωπο):")
    for (a, b), c in d_words.most_common(30):
        print(f"   {c:>4}  {a} -> {b}")

    print(f"\nΒ2: {len(pairs)} λαθη ({len(pairs) / words:.1%} των λεξεων). Ανα ειδος: ακριβως σωστα / αλλη λεξη / χωρις αλλαγη")
    for kind in KINDS:
        items = [(n, t, corrector.fix(n) or n) for k, n, t in pairs if k == kind]
        if items:
            exact, same = sum(o == t for _, t, o in items), sum(o == n for n, _, o in items)
            print(f"   {kind:<10} {len(items):>5}: {exact / len(items):.0%} / {(len(items) - exact - same) / len(items):.0%}"
                  f" / {same / len(items):.0%}")

    f2, s2, n2 = restore(decisions["Α"], decisions["Β2"], decisions["Γ2"])
    f1, s1, n1 = restore(decisions["Α"], decisions["Β1"], decisions["Γ1"])
    print(f"\nΕπαναφορα: Β2 -> Γ2 {s2}/{f2}, νεες διαφορες απο τη διορθωση {n2}"
          f" | Β1 -> Γ1 {s1}/{f1}, νεες {n1}")
    rule3 = f1 < MIN_FLIPS or s1 / f1 >= SIGMA_RESTORE
    checks = [
        ("1. Δ: κανενα F1 κατω απο το Α πανω απο 0,005, κινδυνος το πολυ -1",
         all(m["Δ"][t] >= m["Α"][t] - CLEAN_DROP for t in F1_TASKS) and m["Δ"]["caught"] >= m["Α"]["caught"] - RISK_DROP,
         " ".join(f"{m['Δ'][t] - m['Α'][t]:+.3f}" for t in F1_TASKS) + f" | {m['Δ']['caught'] - m['Α']['caught']:+d}"),
        (f"2. Γ2: επαναφορα >= 50% (τουλαχιστον {MIN_FLIPS} αλλαγες), F1 και κινδυνος οχι κατω απο το Β2",
         f2 >= MIN_FLIPS and s2 / f2 >= TYPO_RESTORE
         and all(m["Γ2"][t] >= m["Β2"][t] - CLEAN_DROP for t in F1_TASKS) and m["Γ2"]["caught"] >= m["Β2"]["caught"] - RISK_DROP,
         f"{s2 / f2:.0%}" if f2 else "0 αλλαγες"),
        ("3. Γ1: επαναφορα >= 90%" + (" (δεν μετριεται, δεν μπλοκαρει)" if f1 < MIN_FLIPS else ""), rule3,
         f"{s1 / f1:.0%}" if f1 else "0 αλλαγες"),
    ]
    print("\n=== Αποφαση κατα το πρωτοκολλο (Β43)")
    for title, ok, values in checks:
        print(f"   {title:<84} {values:<32} {'ναι' if ok else 'ΟΧΙ'}")
    print(f"   -> {'ΠΕΡΝΑΕΙ' if all(ok for _, ok, _ in checks) else 'δεν περναει'}")


if __name__ == "__main__":
    main()
