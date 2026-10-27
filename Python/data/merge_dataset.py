# data/merge_dataset.py
# processed/*.csv (ελληνικα, απο το translate_gemini.py) -> training/train.csv για το train_mtl.py
# Οι ελληνικες πηγες (π.χ. sentimp, xed) διαβαζονται κατευθειαν απο το interim/, χωρις μεταφραση.
#
#   - κραταει μονο ελληνικα (lang=el), τα αμεταφραστα τα μετραει και τα προσπερνα
#   - ελεγχει καθε label (ευρος, μορφη), τα λαθος γινονται κενα (= χωρις annotation)
#   - "none" στα multi-label = ρητα κανενα label (π.χ. ουδετερο GoEmotions, distortion=Όχι)
#   - διπλα κειμενα (και αναμεσα σε πηγες) -> μια γραμμη, τα labels ενωνονται οπου δεν συγκρουονται
#   - βγαζει οτι υπαρχει στο test set (data/annotation/test_*.csv), ωστε να μη μπει ποτε στο training
#   - split train/val ανα group: ολα τα δειγματα ενος group (ιδιος χρηστης / ιδια ερωτηση) πανε μαζι.
#     Το split βγαινει απο hash του group, οποτε μενει ιδιο οταν προστεθουν νεα δεδομενα.
#
# Εκτελεση (απο τον φακελο Python/):
#   .venv/bin/python data/merge_dataset.py
#   .venv/bin/python data/merge_dataset.py --sources goemotions codipad --val-ratio 0.15
import argparse
import csv
import glob
import hashlib
import os
import re
import sys
import unicodedata
from collections import Counter, defaultdict

from common import COVERED, HERE, INTERIM, PY_ROOT, SEED

from labels import LABEL_SETS, MULTI_LABEL_TASKS, TASKS

PROCESSED = os.path.join(HERE, "processed")
ANNOTATION = os.path.join(HERE, "annotation")
MANUAL = os.path.join(PY_ROOT, "training", "dataset.csv")  # τα χειρογραφα παραδειγματα
OUT = os.path.join(PY_ROOT, "training", "train.csv")
OUT_COLUMNS = ["id", "source", "group", "split", "text"] + TASKS + COVERED
NONE = "none"

_SPACES = re.compile(r"\s+")
_GREEK = re.compile(r"[Ͱ-Ͽἀ-῿]")


def key(text: str) -> str:
    # για συγκριση διπλων: πεζα, χωρις τονους, ενα κενο
    text = unicodedata.normalize("NFD", text.casefold())
    text = "".join(c for c in text if unicodedata.category(c) != "Mn")
    return _SPACES.sub(" ", text).strip()


def source_key(text: str | None) -> str:
    return "".join(c for c in (text or "").casefold() if c.isalnum())


def union(a: str, b: str) -> str:
    ids = {int(p) for v in (a, b) if v and v != NONE for p in v.split("|")}
    return "|".join(map(str, sorted(ids))) if ids else NONE


def union_covered(a: str, b: str) -> str:
    return "" if not a or not b else union(a, b)


def can_union(t: str, prev: dict, labels: dict) -> bool:
    if t == "distortion_types":
        return prev["distortion"] == labels["distortion"] == "1"
    return t in MULTI_LABEL_TASKS


def blank(value) -> bool:
    return value is None or str(value).strip() == ""


def check_single(task: str, value) -> tuple[str, bool]:
    """-> (τιμη, εγκυρη). Κενο = χωρις annotation."""
    if blank(value):
        return "", True
    try:
        i = int(float(value))
    except ValueError:
        return "", False
    return (str(i), True) if 0 <= i < len(LABEL_SETS[task]) else ("", False)


def check_multi(task: str, value) -> tuple[str, bool]:
    if blank(value):
        return "", True
    value = str(value).strip()
    if value == NONE:
        return NONE, True
    try:
        ids = sorted({int(float(p)) for p in value.split("|")})
    except ValueError:
        return "", False
    if not all(0 <= i < len(LABEL_SETS[task]) for i in ids):
        return "", False
    return "|".join(map(str, ids)), True


def clean_labels(row: dict, bad: Counter) -> dict:
    labels = {}
    for t in TASKS:
        value, ok = (check_multi if t in MULTI_LABEL_TASKS else check_single)(t, row.get(t))
        if not ok:
            bad[t] += 1
        labels[t] = value
    # distortion = Όχι χωρις τυπους -> ρητα κανενας τυπος (οχι "χωρις annotation")
    if labels["distortion"] == "0" and labels["distortion_types"] == "":
        labels["distortion_types"] = NONE
    # distortion = Ναι χωρις τυπους -> δεν ξερουμε ποιοι, αφηνεται κενο
    for t in MULTI_LABEL_TASKS:
        value, ok = check_multi(t, row.get(f"{t}_covered"))
        if not ok or value == NONE:
            bad[f"{t}_covered"] += 1
            value = ""
        # η καλυψη παει μαζι με το label, χωρις label δεν εχει νοημα
        labels[f"{t}_covered"] = value if labels[t] != "" else ""
    return labels


def read_csv(path: str) -> list[dict]:
    with open(path, encoding="utf-8", newline="") as f:
        return list(csv.DictReader(f))


def test_texts() -> set:
    keys = set()
    for path in glob.glob(os.path.join(ANNOTATION, "test*.csv")):
        for r in read_csv(path):
            if not blank(r.get("text")):
                keys.add(key(r["text"]))
    return keys


def split_of(group: str, val_ratio: float) -> str:
    h = int(hashlib.md5(f"{SEED}:{group}".encode("utf-8")).hexdigest()[:8], 16)
    return "val" if h / 0xFFFFFFFF < val_ratio else "train"


def is_greek(path: str) -> bool:
    with open(path, encoding="utf-8", newline="") as f:
        first = next(csv.DictReader(f), None)
    return first is not None and first.get("lang") == "el"


def source_paths() -> dict[str, str]:
    paths = {os.path.basename(p)[:-4]: p for p in glob.glob(os.path.join(PROCESSED, "*.csv"))}
    # ελληνικο interim αντι για το αντιγραφο του processed/, ωστε να μη μενει παλια εκδοση
    for p in glob.glob(os.path.join(INTERIM, "*.csv")):
        if is_greek(p):
            paths[os.path.basename(p)[:-4]] = p
    return paths


def load_sources(names: list[str] | None, with_manual: bool) -> list[tuple[str, list[dict]]]:
    paths = source_paths()
    if names:
        missing = set(names) - paths.keys()
        if missing:
            sys.exit(f"[!] Δεν βρεθηκαν στο {PROCESSED} ή στο {INTERIM}: {', '.join(sorted(missing))}")
        paths = {n: p for n, p in paths.items() if n in names}
    sources = [(name, read_csv(p)) for name, p in sorted(paths.items())]

    if with_manual and os.path.exists(MANUAL):
        rows = []
        for i, r in enumerate(read_csv(MANUAL)):
            rows.append({**r, "id": f"manual-{i}", "source": "manual", "group": f"manual-{i}", "lang": "el"})
        sources.append(("manual", rows))
    return sources


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--sources", nargs="*", default=None, help="π.χ. goemotions codipad (default: ολα στο processed/)")
    ap.add_argument("--val-ratio", type=float, default=0.2)
    ap.add_argument("--no-manual", action="store_true", help="χωρις τα χειρογραφα του training/dataset.csv")
    ap.add_argument("--out", default=OUT)
    args = ap.parse_args()

    sources = load_sources(args.sources, not args.no_manual)
    if not any(name != "manual" for name, _ in sources):
        sys.exit(f"[!] Δεν υπαρχουν αρχεια στο {PROCESSED} ή στο {INTERIM}. Τρεξε πρωτα τα prepare_*.py και το translate_gemini.py.")

    test = test_texts()
    merged: dict[str, dict] = {}  # key(text) -> γραμμη
    by_source_text: dict[str, str] = {}
    stats = defaultdict(Counter)
    bad = Counter()

    for name, rows in sources:
        s = stats[name]
        for r in rows:
            s["σύνολο"] += 1
            if r.get("lang", "el") != "el":
                s["αμετάφραστα"] += 1
                continue
            if r.get("label_check") == "rejected":
                s["απορρίφθηκαν (έλεγχος labels)"] += 1
                continue
            text = _SPACES.sub(" ", r.get("text") or "").strip()
            if not text:
                s["κενά"] += 1
                continue
            if not _GREEK.search(text):
                s["χωρίς ελληνικά"] += 1
                continue
            k = key(text)
            if k in test:
                s["στο test set"] += 1
                continue
            labels = clean_labels(r, bad)
            if all(v == "" for v in labels.values()):
                s["χωρίς label"] += 1
                continue
            src = source_key(r.get("text_src"))
            if src and k not in merged and src in by_source_text:
                k = by_source_text[src]

            if k in merged:
                # ιδιο κειμενο ξανα: συμπληρωνουμε μονο οσα λειπουν, σε συγκρουση κραταμε το πρωτο
                prev = merged[k]
                for t in TASKS:
                    v = labels[t]
                    if v == "":
                        continue
                    if prev[t] == "":
                        prev[t] = v
                        if t in MULTI_LABEL_TASKS:
                            prev[f"{t}_covered"] = labels[f"{t}_covered"]
                    elif prev[t] != v and can_union(t, prev, labels):
                        u = union(prev[t], v)
                        if u != prev[t]:
                            s["ενώθηκαν labels"] += 1
                        prev[t] = u
                        prev[f"{t}_covered"] = union_covered(prev[f"{t}_covered"], labels[f"{t}_covered"])
                    elif prev[t] != v:
                        s["συγκρούσεις label"] += 1
                s["διπλά"] += 1
                continue

            group = r.get("group") or r.get("id") or k
            merged[k] = {"id": r.get("id", ""), "source": name, "group": group, "text": text, **labels}
            if src:
                by_source_text.setdefault(src, k)
            s["κρατήθηκαν"] += 1

    rows = list(merged.values())
    for r in rows:
        r["split"] = split_of(r["group"], args.val_ratio)

    os.makedirs(os.path.dirname(args.out), exist_ok=True)
    with open(args.out, "w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=OUT_COLUMNS, extrasaction="ignore")
        w.writeheader()
        w.writerows(rows)

    # --- αναφορα ---
    print("Πηγές:")
    for name, s in stats.items():
        print(f"  {name}: " + ", ".join(f"{k}={v}" for k, v in s.items()))
    if bad:
        print("  [!] άκυρα labels (έγιναν κενά): " + ", ".join(f"{t}={n}" for t, n in bad.items()))
    if any(s["αμετάφραστα"] for s in stats.values()):
        print("  [!] υπάρχουν αμετάφραστα: ξανατρέξε το translate_gemini.py για να ολοκληρωθεί η μετάφραση")
    print(f"  test set: {len(test)} κείμενα εξαιρέθηκαν από το training" if test else
          "  test set: δεν βρέθηκε data/annotation/test*.csv με κείμενα (κανένας έλεγχος διαρροής)")

    by_split = Counter(r["split"] for r in rows)
    groups = {sp: len({r["group"] for r in rows if r["split"] == sp}) for sp in by_split}
    print(f"\nΣύνολο: {len(rows)} γραμμές | train={by_split['train']} ({groups.get('train', 0)} groups)"
          f" | val={by_split['val']} ({groups.get('val', 0)} groups)")

    print("\nLabels ανά task (train / val):")
    for t in TASKS:
        counts = {sp: Counter() for sp in ("train", "val")}
        n = Counter()
        covered = Counter()  # γραμμες του train που καλυπτουν καθε label
        for r in rows:
            v = r[t]
            if v == "":
                continue
            n[r["split"]] += 1
            if t in MULTI_LABEL_TASKS and r["split"] == "train":
                cov = r[f"{t}_covered"]
                for i in (cov.split("|") if cov else range(len(LABEL_SETS[t]))):
                    covered[LABEL_SETS[t][int(i)]] += 1
            if v == NONE:
                counts[r["split"]]["(κανένα)"] += 1
            else:
                for i in v.split("|"):
                    counts[r["split"]][LABEL_SETS[t][int(i)]] += 1
        if not n:
            print(f"  {t}: καμία γραμμή -> το head δεν θα εκπαιδευτεί")
            continue
        print(f"  {t}: {n['train']} / {n['val']} γραμμές")
        for label in list(LABEL_SETS[t]) + (["(κανένα)"] if t in MULTI_LABEL_TASKS else []):
            tr, va = counts["train"][label], counts["val"][label]
            if t in MULTI_LABEL_TASKS and label != "(κανένα)" and covered[label] < 50:
                print(f"      {label:<28} {tr:>6} / {va:<6}   <- το καλύπτουν {covered[label]} γραμμές, δεν θα εκπαιδευτεί")
            elif tr or va:
                warn = "   <- λίγα" if tr < 50 else ""
                print(f"      {label:<28} {tr:>6} / {va:<6}{warn}")
            elif t != "irony" and label != "(κανένα)":
                print(f"      {label:<28}      0 /  0      <- κανένα δείγμα, το μοντέλο θα μάθει να μην το προβλέπει")
    print(f"\n-> {args.out}")


if __name__ == "__main__":
    main()
