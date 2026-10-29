import argparse
import csv
import os
import random
import sys
import time

from common import SEED
from labels import LABEL_SETS
from translate_gemini import HERE, OUT_COLUMNS, PROCESSED, describe, generate, load_key, read

RECHECK = os.path.join(HERE, "recheck")
FIELDS = ["id", "check", "reason"]

DISTORTION_PROMPT = """Ελέγχεις labels γνωστικής διαστρέβλωσης σε αγγλικά κείμενα. Τα labels τα έβαλαν άνθρωποι.

Διαστρέβλωση είναι ένας υπερβολικός ή άκαμπτος τρόπος ερμηνείας της πραγματικότητας από τον συγγραφέα, όχι το γεγονός ή το συναίσθημα. Υπάρχει μόνο όταν υπάρχει συγκεκριμένη φράση-σκέψη που μπορείς να δείξεις.
- Λύπη δεν είναι διαστρέβλωση: «Χωρίσαμε και πονάω πολύ» -> Όχι. «Χωρίσαμε, κανείς δεν θα με αγαπήσει ποτέ» -> Ναι.
- Ρεαλιστική ανησυχία δεν είναι διαστρέβλωση: «Φοβάμαι μήπως κοπώ, δεν διάβασα αρκετά» -> Όχι.
- Αν η σκέψη αμφισβητείται μέσα στο κείμενο, η διαστρέβλωση πάλι υπάρχει: η σκέψη εμφανίστηκε.

Τύποι:
- Καταστροφολογία: το χειρότερο σενάριο ή μεγέθυνση ενός προβλήματος.
- Όλα ή τίποτα: σε άσπρο-μαύρο, χωρίς ενδιάμεσο.
- Υπεργενίκευση: ένα γεγονός γίνεται κανόνας («πάντα», «ποτέ», «όλοι»).
- Ανάγνωση σκέψης: βεβαιότητα για το τι σκέφτονται οι άλλοι, χωρίς στοιχεία.
- Προφητεία: βεβαιότητα για το (αρνητικό) μέλλον.
- Συναισθηματική συλλογιστική: «το νιώθω, άρα ισχύει».
- Πρέπει: άκαμπτοι κανόνες για τον εαυτό ή τους άλλους, με συναισθηματικό βάρος. «Πρέπει να ψωνίσω γάλα» δεν μετράει.
- Ταμπέλες: ολική ετικέτα σε άνθρωπο αντί για συμπεριφορά.
- Προσωποποίηση: ευθύνη για κάτι που δεν ελέγχεις.
- Νοητικό φίλτρο: εστίαση μόνο στο αρνητικό, αγνοείται το θετικό.

Θα λάβεις JSON λίστα με αντικείμενα {"i": αριθμός, "text": κείμενο, "labels": labels}. Για ΚΑΘΕ αντικείμενο επίστρεψε το ίδιο "i" και:
- "check": "no" μόνο όταν κάποιο label είναι ξεκάθαρα λάθος με βάση τους παραπάνω ορισμούς: διαστρέβλωση Ναι χωρίς καμία φράση-σκέψη, Όχι ενώ υπάρχει ξεκάθαρη διαστρέβλωση, ή τύπος που δεν ταιριάζει καθόλου στο κείμενο. Τότε γράψε στο "reason" μια σύντομη εξήγηση στα ελληνικά.
- "check": "yes" σε κάθε άλλη περίπτωση, και όταν είναι αμφίβολο. Μην προτείνεις τύπους που λείπουν, κρίνε μόνο όσους δίνονται.
"""

RISK_PROMPT = """Ελέγχεις labels κινδύνου σε αγγλικά κείμενα από το Reddit. Κάθε κείμενο είναι κομμάτι από post ή σχόλιο.

Κίνδυνος Ναι μόνο όταν ο ΙΔΙΟΣ ο συγγραφέας εκφράζει σκέψεις αυτοκτονίας, θανάτου ή αυτοτραυματισμού για τον εαυτό του, τώρα ή στο παρελθόν, ρητά ή έμμεσα. Μετράνε και σχέδια, μέσα, προηγούμενη απόπειρα ή αυτοτραυματισμός.
- Μιλάει για κάποιον άλλο, δίνει συμβουλές ή στηρίζει άλλους χωρίς να δείχνει ο ίδιος κίνδυνο -> Όχι.
- Γενική συζήτηση για την αυτοκτονία, χωρίς αναφορά στον εαυτό του -> Όχι.
- Λύπη, απελπισία ή κατάθλιψη χωρίς σκέψεις θανάτου ή αυτοτραυματισμού -> Όχι.
- Υπερβολές της καθημερινότητας («πέθανα στα γέλια») -> Όχι.

Θα λάβεις JSON λίστα με αντικείμενα {"i": αριθμός, "text": κείμενο, "labels": labels}. Για ΚΑΘΕ αντικείμενο επίστρεψε το ίδιο "i" και:
- "check": "no" μόνο όταν το label είναι ξεκάθαρα λάθος με βάση τον παραπάνω ορισμό: Ναι χωρίς καμία τέτοια σκέψη του συγγραφέα για τον εαυτό του, ή Όχι ενώ υπάρχει. Τότε γράψε στο "reason" μια σύντομη εξήγηση στα ελληνικά.
- "check": "yes" σε κάθε άλλη περίπτωση, και όταν είναι αμφίβολο.
"""

EMOTION_PROMPT = """Ελέγχεις labels πολικότητας και συναισθήματος σε σύντομα αγγλικά σχόλια από το Reddit. Τα labels τα έβαλαν άνθρωποι, με άλλη λίστα συναισθημάτων που αντιστοιχίστηκε στη δική μας.

Τα labels αφορούν τον συγγραφέα, όχι άλλους που αναφέρει. Μετράει ό,τι εκφράζεται ή εννοείται καθαρά, όχι υποθέσεις. Κρίνε το νόημα, όχι τις λέξεις: σε ειρωνικό ή σαρκαστικό σχόλιο, ένα «sorry» δεν είναι Ενοχή.

Πολικότητα (μία):
- Θετικό: η συνολική διάθεση είναι καλή.
- Ουδέτερο: περιγραφή χωρίς ουσιαστικό συναίσθημα, ή θετικά και αρνητικά ισορροπούν.
- Αρνητικό: η συνολική διάθεση είναι κακή.

Συναισθήματα (όσα ισχύουν):
- Χαρά: ευχαρίστηση, ικανοποίηση, κέφι.
- Λύπη: στεναχώρια, απογοήτευση, πένθος.
- Θυμός: εκνευρισμός, οργή, αδικία.
- Φόβος: φόβος για συγκεκριμένο κίνδυνο ή απειλή.
- Άγχος: ανησυχία ή ένταση για κάτι που έρχεται ή είναι αβέβαιο.
- Ηρεμία: γαλήνη, ανακούφιση, χαλαρότητα.
- Αγάπη: τρυφερότητα, στοργή, ευγνωμοσύνη για πρόσωπα.
- Έκπληξη: κάτι απρόσμενο, θετικό ή αρνητικό.
- Ενθουσιασμός: έντονη, ενεργητική προσμονή ή χαρά.
- Ντροπή: αίσθηση ότι «εκτέθηκα», ότι κάτι δεν πάει καλά με μένα.
- Ενοχή: αίσθηση ότι έκανα κάτι λάθος σε κάποιον ή κάτι.
- κανένα: δεν φαίνεται κανένα συναίσθημα του συγγραφέα.

Θα λάβεις JSON λίστα με αντικείμενα {"i": αριθμός, "text": κείμενο, "labels": labels}. Για ΚΑΘΕ αντικείμενο επίστρεψε το ίδιο "i" και:
- "check": "no" μόνο όταν κάποιο label είναι ξεκάθαρα λάθος με βάση τους παραπάνω ορισμούς: συναίσθημα που δεν φαίνεται καθόλου στο κείμενο, «κανένα» ενώ υπάρχει ξεκάθαρο συναίσθημα, ή πολικότητα αντίθετη από τη διάθεση του κειμένου. Τότε γράψε στο "reason" μια σύντομη εξήγηση στα ελληνικά.
- "check": "yes" σε κάθε άλλη περίπτωση, και όταν είναι αμφίβολο. Μην προτείνεις συναισθήματα που λείπουν, κρίνε μόνο όσα δίνονται.
"""

CHECKS = {
    "codipad": (DISTORTION_PROMPT, "distortion"),
    "cssrs": (RISK_PROMPT, "risk"),
    "goemotions": (EMOTION_PROMPT, "sentiment"),
}

SCHEMA = {"type": "ARRAY", "items": {"type": "OBJECT", "properties": {
    "i": {"type": "INTEGER"},
    "check": {"type": "STRING", "enum": ["yes", "no"]},
    "reason": {"type": "STRING"},
}, "required": ["i", "check"]}}


def run_path(source: str, run: str) -> str:
    return os.path.join(RECHECK, f"{source}_{run}.csv")


def load_run(source: str, run: str) -> dict:
    path = run_path(source, run)
    if not os.path.exists(path):
        sys.exit(f"[!] Δεν υπαρχει το {path}")
    return {r["id"]: r for r in read(path)}


def check(args, rows: list[dict]):
    prompt, task = CHECKS[args.source]
    todo = random.Random(SEED).sample(rows, args.sample) if args.sample else rows
    out_path = run_path(args.source, args.run)
    done = set(load_run(args.source, args.run)) if os.path.exists(out_path) else set()
    todo = [r for r in todo if r["id"] not in done]
    print(f"[{args.source}/{args.run}] {len(done)} ηδη ελεγμενα, {len(todo)} για ελεγχο, {-(-len(todo) // args.batch)} κλησεις")
    if args.dry_run:
        print(prompt)
        print([{"i": i, "text": r["text_src"], "labels": describe(r)} for i, r in enumerate(todo[:3])])
        return
    key = load_key()
    if not key:
        sys.exit("[!] Δεν βρεθηκε GEMINI_API_KEY (ουτε στο περιβαλλον ουτε στο .env).")
    os.makedirs(RECHECK, exist_ok=True)
    new = not os.path.exists(out_path)
    with open(out_path, "a", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=FIELDS)
        if new:
            w.writeheader()
        try:
            for b in range(0, len(todo), args.batch):
                batch = todo[b:b + args.batch]
                t0 = time.time()
                items = [{"i": i, "text": r["text_src"], "labels": describe(r)} for i, r in enumerate(batch)]
                try:
                    result = generate(args.model, key, prompt, items, SCHEMA, 0.0)
                except RuntimeError as e:
                    print(f"   [!] παρτιδα {b // args.batch} απετυχε: {e}")
                    continue
                got = {x.get("i"): x for x in result if isinstance(x, dict)}
                for i, r in enumerate(batch):
                    x = got.get(i)
                    if x and x.get("check") in ("yes", "no"):
                        w.writerow({"id": r["id"], "check": "rejected" if x["check"] == "no" else "ok",
                                    "reason": str(x.get("reason", "")).strip() if x["check"] == "no" else ""})
                f.flush()
                print(f"   {min(b + args.batch, len(todo))}/{len(todo)}")
                time.sleep(max(0.0, 60.0 / args.rpm - (time.time() - t0)))
        except KeyboardInterrupt:
            print("\n[!] Διακοπη. Οτι εγινε εχει σωθει, ξανατρεξε για να συνεχισεις.")
            sys.exit(1)
    summary(rows, load_run(args.source, args.run), task)


def summary(rows: list[dict], result: dict, task: str):
    by_id = {r["id"]: r for r in rows}
    for v, name in [(str(i), n) for i, n in reversed(list(enumerate(LABEL_SETS[task])))] + [("", "χωρις label")]:
        part = [r for i, r in result.items() if by_id[i][task] == v]
        if part:
            n = sum(r["check"] == "rejected" for r in part)
            print(f"   {task} {name}: {n}/{len(part)} rejected ({n / len(part):.0%})")


def compare(args):
    a, b = load_run(args.source, args.compare[0]), load_run(args.source, args.compare[1])
    ids = sorted(set(a) & set(b))
    if not ids:
        sys.exit("[!] Δεν υπαρχουν κοινα κειμενα")
    x = [a[i]["check"] == "rejected" for i in ids]
    y = [b[i]["check"] == "rejected" for i in ids]
    agree = sum(p == q for p, q in zip(x, y)) / len(ids)
    pa, pb = sum(x) / len(ids), sum(y) / len(ids)
    expected = pa * pb + (1 - pa) * (1 - pb)
    kappa = (agree - expected) / (1 - expected) if expected < 1 else 1.0
    both = sum(p and q for p, q in zip(x, y))
    print(f"   κοινα: {len(ids)} | rejected: {sum(x)} και {sum(y)}, και στα δυο {both}")
    print(f"   συμφωνια {agree:.1%} | Cohen's kappa {kappa:.2f}")


def apply(args, rows: list[dict]):
    result = load_run(args.source, args.apply)
    missing = [r["id"] for r in rows if r["id"] not in result]
    if missing:
        sys.exit(f"[!] Λειπουν {len(missing)} κειμενα απο το run '{args.apply}'. Τρεξε πρωτα τον ελεγχο για ολα.")
    backup = os.path.join(RECHECK, f"{args.source}_translation_check.csv")
    if not os.path.exists(backup):
        with open(backup, "w", encoding="utf-8", newline="") as f:
            w = csv.DictWriter(f, fieldnames=FIELDS)
            w.writeheader()
            w.writerows({"id": r["id"], "check": r["label_check"], "reason": r["check_reason"]} for r in rows)
    with open(os.path.join(PROCESSED, f"{args.source}.csv"), "w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=OUT_COLUMNS)
        w.writeheader()
        w.writerows({**r, "label_check": result[r["id"]]["check"], "check_reason": result[r["id"]]["reason"]}
                    for r in rows)
    print(f"[{args.source}] label_check απο το run '{args.apply}'. Ο παλιος ελεγχος: {backup}")
    summary(rows, result, CHECKS[args.source][1])


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--source", default="codipad", choices=sorted(CHECKS))
    ap.add_argument("--run", default="full")
    ap.add_argument("--sample", type=int, default=0)
    ap.add_argument("--model", default="gemini-3.8-flash")
    ap.add_argument("--batch", type=int, default=150)
    ap.add_argument("--rpm", type=float, default=10)
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--compare", nargs=2, metavar=("RUN_A", "RUN_B"))
    ap.add_argument("--apply", metavar="RUN")
    args = ap.parse_args()

    rows = read(os.path.join(PROCESSED, f"{args.source}.csv"))
    if args.compare:
        compare(args)
    elif args.apply:
        apply(args, rows)
    else:
        check(args, rows)


if __name__ == "__main__":
    main()
