# data/prepare_cssrs.py
# Reddit C-SSRS Suicide Dataset (Gaur et al. 2019, Zenodo 10.5281/zenodo.2667859, CC BY 4.0)
# -> interim/cssrs.csv, μια γραμμη ανα post (ή κομματι post). Γεμιζει μονο risk. Αγγλικα -> θελει μεταφραση.
#
# ΠΡΟΣΟΧΗ: το label του dataset ειναι ανα ΧΡΗΣΤΗ, οχι ανα post. Γι' αυτο:
#   - Supportive χρηστες (στηριζουν αλλους) -> risk = Όχι σε ολα τα posts τους
#   - ολοι οι αλλοι (Indicator / Ideation / Behavior / Attempt) -> risk ΚΕΝΟ εδω.
#     Θα μπει ανα post σε ξεχωριστο βημα (label με LLM + ελεγχος δειγματος απο ανθρωπο).
#   Το label του χρηστη κρατιεται στο meta για εκεινο το βημα.
import ast
import csv
import os
import re
import sys

from common import RAW, clean, idx, summary, words, write

SRC = os.path.join(RAW, "cssrs", "500_Reddit_users_posts_labels.csv")
CHUNK_WORDS = 80  # μεγαλα posts σπανε σε κομματια (το μοντελο βλεπει μονο 128 tokens)
MIN_WORDS = 5
_SENT = re.compile(r"(?<=[.!?])\s+")


def posts_of(cell: str) -> list[str]:
    try:
        v = ast.literal_eval(cell)
        return [str(p) for p in v] if isinstance(v, list) else [cell]
    except (ValueError, SyntaxError):
        return [cell]


def chunks(text: str) -> list[str]:
    if words(text) <= CHUNK_WORDS:
        return [text]
    out, cur = [], []
    for sent in _SENT.split(text):
        if cur and words(" ".join(cur + [sent])) > CHUNK_WORDS:
            out.append(" ".join(cur))
            cur = []
        cur.append(sent)
    if cur:
        out.append(" ".join(cur))
    # μια τεραστια προταση χωρις τελεια -> κοψιμο ανα λεξεις
    final = []
    for c in out:
        w = c.split()
        final += [" ".join(w[i:i + CHUNK_WORDS]) for i in range(0, len(w), CHUNK_WORDS)]
    return final


def main():
    csv.field_size_limit(sys.maxsize)
    rows, seen = [], set()
    with open(SRC, encoding="utf-8", errors="replace") as f:
        for r in csv.DictReader(f):
            user, label = r["User"], r["Label"]
            for p_i, post in enumerate(posts_of(r["Post"])):
                for c_i, text in enumerate(chunks(clean(post))):
                    if words(text) < MIN_WORDS or text in seen:
                        continue
                    seen.add(text)
                    rows.append({
                        "id": f"cssrs-{user}-{p_i}-{c_i}",
                        "source": "cssrs",
                        "group": f"cssrs-{user}",  # ολα τα posts ενος χρηστη μαζι σε train ή validation
                        "lang": "en",
                        "text": text,
                        "risk": idx("risk", "Όχι") if label == "Supportive" else "",
                        "meta": f"user_label={label}",
                    })
    summary("cssrs", rows)
    from collections import Counter
    print("   ανα user_label:", dict(Counter(r["meta"] for r in rows).most_common()))
    print("->", write("cssrs", rows))


if __name__ == "__main__":
    main()
