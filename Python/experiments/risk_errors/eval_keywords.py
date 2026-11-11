"""Ψευδείς συναγερμοί και νέα «Ναι» των λέξεων-κλειδιών του CrisisSupport.php, πριν και μετά από μια αλλαγή.

Από τον φάκελο Python/:
    .venv/bin/python experiments/risk_errors/eval_keywords.py              # πριν = HEAD του git, μετά = το αρχείο τώρα
    .venv/bin/python experiments/risk_errors/eval_keywords.py --before f1e50b0

Τα patterns διαβάζονται από το ίδιο το CrisisSupport.php και τρέχουν σε Python, με το normalize() του PHP.
Αν υπάρχει PHP (PHP_BIN, προεπιλογή η XAMPP), το «μετά» ελέγχεται και με το ίδιο το PHP (risk_kw.php),
ώστε να φανεί αν η Python διαφωνεί κάπου με το preg_match.

- Τα «Ναι» του validation ΔΕΝ είναι μέτρηση βελτίωσης: κάποιες φράσεις βγήκαν από αυτά (κανόνας 2 του README).
- Οι ψευδείς συναγερμοί μετριούνται σε όλα τα «Όχι» (train και validation), που δεν χρησιμοποιήθηκαν για τις φράσεις.
- Το output/keyword_new_hits.csv έχει μόνο ids, χωρίς κείμενα.
"""
import argparse
import csv
import json
import os
import re
import subprocess

HERE = os.path.dirname(os.path.abspath(__file__))
PY = os.path.dirname(os.path.dirname(HERE))
ROOT = os.path.dirname(PY)
CRISIS = "App/Services/CrisisSupport.php"
TRAIN = os.path.join(PY, "training", "train.csv")
OUT = os.path.join(HERE, "output", "keyword_new_hits.csv")
PHP = os.getenv("PHP_BIN", "/Applications/XAMPP/xamppfiles/bin/php")

ACCENTS = str.maketrans({"ά": "α", "έ": "ε", "ή": "η", "ί": "ι", "ό": "ο", "ύ": "υ", "ώ": "ω",
                         "ϊ": "ι", "ϋ": "υ", "ΐ": "ι", "ΰ": "υ", "ς": "σ"})
GROUPS = ["train risk=1", "val risk=1", "train risk=0", "val risk=0", "train χωρίς label", "val χωρίς label"]


def normalize(text: str) -> str:
    return re.sub(r"\s+", " ", text.lower().translate(ACCENTS)).strip()


def patterns(source: str) -> list[re.Pattern]:
    start = source.index("PATTERNS = [")
    block = source[start:source.index("];", start)]
    return [re.compile(p) for p in re.findall(r"'/(.*?)/u'", block)]


def source_at(ref: str) -> str:
    return subprocess.run(["git", "show", f"{ref}:{CRISIS}"], cwd=ROOT, capture_output=True, text=True,
                          check=True).stdout


def php_hits(rows: list[dict]) -> dict | None:
    if not os.path.exists(PHP):
        return None
    payload = json.dumps([{"id": r["id"], "text": r["text"]} for r in rows], ensure_ascii=False)
    result = subprocess.run([PHP, os.path.join(HERE, "risk_kw.php")], input=payload, capture_output=True,
                            text=True, check=True)
    return json.loads(result.stdout)


def group(row: dict) -> str:
    label = {"1": "risk=1", "0": "risk=0"}.get(row["risk"], "χωρίς label")
    return f"{row['split']} {label}"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--before", default="HEAD", help="git ref για το «πριν»")
    args = ap.parse_args()

    before = patterns(source_at(args.before))
    after = patterns(open(os.path.join(ROOT, CRISIS), encoding="utf-8").read())
    with open(TRAIN, encoding="utf-8") as f:
        rows = [r for r in csv.DictReader(f) if r["split"] in ("train", "val")]
    print(f"[*] {len(rows)} κείμενα | patterns: {len(before)} ({args.before}) -> {len(after)} (τώρα)\n")

    hit = lambda ps, t: any(p.search(t) for p in ps)
    stats = {g: {"n": 0, "before": 0, "after": 0} for g in GROUPS}
    new_hits, after_py = [], {}
    for r in rows:
        t, g = normalize(r["text"]), group(r)
        b, a = hit(before, t), hit(after, t)
        after_py[r["id"]] = a
        stats[g]["n"] += 1
        stats[g]["before"] += b
        stats[g]["after"] += a
        if a != b:
            new_hits.append({"id": r["id"], "group": g, "source": r["source"], "change": "+" if a else "-"})

    print(f"{'ομάδα':<20}{'N':>7}{'πριν':>7}{'μετά':>7}{'διαφορά':>9}")
    for g in GROUPS:
        s = stats[g]
        print(f"{g:<20}{s['n']:>7}{s['before']:>7}{s['after']:>7}{s['after'] - s['before']:>+9}")
    false_new = sum(1 for h in new_hits if h["change"] == "+" and "risk=0" in h["group"])
    print(f"\nνέοι ψευδείς συναγερμοί στα «Όχι»: {false_new}")
    print("τα «Ναι» του validation δεν μετρούν ως βελτίωση (κάποιες φράσεις βγήκαν από αυτά)")

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=["id", "group", "source", "change"])
        w.writeheader()
        w.writerows(new_hits)
    print(f"[+] -> {OUT}")

    php = php_hits(rows)
    if php is None:
        print(f"[!] Δεν βρέθηκε PHP ({PHP}): το «μετά» δεν ελέγχθηκε με το preg_match. Όρισε PHP_BIN.")
    else:
        diff = [i for i, v in after_py.items() if bool(php.get(i)) != v]
        print(f"[{'+' if not diff else '!'}] PHP και Python διαφωνούν σε {len(diff)} κείμενα" + (f": {diff[:10]}" if diff else ""))


if __name__ == "__main__":
    main()
