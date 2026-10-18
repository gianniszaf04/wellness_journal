# data/common.py
# Κοινα εργαλεια για τα prepare_*.py: κοινη μορφη εξοδου και αντιστοιχιση labels με βαση το ονομα
# (οχι hardcoded indices, ωστε αν αλλαξει η σειρα στο labels.py να μη χαλασει τιποτα).
import csv
import html
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
PY_ROOT = os.path.dirname(HERE)
RAW = os.path.join(HERE, "raw")
INTERIM = os.path.join(HERE, "interim")
sys.path.append(PY_ROOT)

from labels import LABEL_SETS, MULTI_LABEL_TASKS, TASKS  # noqa: E402

# Κοινη μορφη ολων των interim αρχειων.
#   group: δειγματα με ιδιο group πανε ολα μαζι σε train ή validation (π.χ. ιδιος χρηστης / ιδια ερωτηση)
#   lang:  "en" -> θελει μεταφραση, "el" -> ετοιμο
#   κενο label = χωρις annotation για αυτο το task (αγνοειται στο loss)
#   <task>_covered: τα labels ενος multi-label task που καλυπτει η πηγη, κενο = ολα.
#     Τα υπολοιπα αγνοουνται στο loss (π.χ. η Κούραση στο GoEmotions δεν ειναι "οχι", ειναι "αγνωστο")
COVERED = [f"{t}_covered" for t in TASKS if t in MULTI_LABEL_TASKS]
COLUMNS = ["id", "source", "group", "lang", "text"] + TASKS + COVERED + ["meta"]

SEED = 42


def idx(task: str, label: str) -> int:
    return LABEL_SETS[task].index(label)


def multi(task: str, labels) -> str:
    # "1|4" οπως το περιμενει το train_mtl.py, ταξινομημενα και χωρις διπλα
    return "|".join(str(i) for i in sorted({idx(task, l) for l in labels}))


_URL = re.compile(r"https?://\S+")
_SPACES = re.compile(r"\s+")
_MENTION = re.compile(r"@\w+")


def clean(text: str) -> str:
    text = html.unescape(text)
    text = _URL.sub("", text)
    text = _MENTION.sub("@user", text)  # ανωνυμοποιηση λογαριασμων
    return _SPACES.sub(" ", text).strip().strip('"').strip()


def words(text: str) -> int:
    return len(text.split())


def write(name: str, rows: list[dict]) -> str:
    os.makedirs(INTERIM, exist_ok=True)
    path = os.path.join(INTERIM, f"{name}.csv")
    with open(path, "w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=COLUMNS, extrasaction="raise")
        w.writeheader()
        for r in rows:
            w.writerow({c: r.get(c, "") for c in COLUMNS})
    return path


def summary(name: str, rows: list[dict]) -> None:
    from collections import Counter
    print(f"[{name}] {len(rows)} γραμμες, {len({r['group'] for r in rows})} groups")
    for t in TASKS:
        vals = [r.get(t, "") for r in rows if r.get(t, "") != ""]
        if not vals:
            continue
        c = Counter()
        for v in vals:
            if v == "none":  # ρητα "κανενα label" σε multi-label task
                c["(κανένα)"] += 1
                continue
            for i in str(v).split("|"):
                c[LABEL_SETS[t][int(i)]] += 1
        print(f"   {t}: {len(vals)} με annotation -> {dict(c.most_common())}")
