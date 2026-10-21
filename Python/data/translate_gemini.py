# data/translate_gemini.py
# interim/*.csv (lang=en) -> processed/*.csv στα ελληνικα, με το Gemini API.
# Για το C-SSRS βαζει στο ιδιο βημα και label κινδυνου ανα post (risk), οπου ειναι κενο.
# Τα ελληνικα αρχεια (lang=el) αντιγραφονται ως εχουν.
#
# Εκτελεση (απο τον φακελο Python/, με GEMINI_API_KEY στο .env):
#   .venv/bin/python data/translate_gemini.py --dry-run          # δειχνει τι θα σταλει, χωρις κληση
#   .venv/bin/python data/translate_gemini.py --limit 800        # πιλοτος: 800 ανα αγγλικη πηγη
# Αν σταματησει (Ctrl+C, οριο, σφαλμα), το ξανατρεχεις και συνεχιζει απο εκει που εμεινε.
#
# ΠΡΟΣΟΧΗ: στο δωρεαν tier η Google μπορει να χρησιμοποιει οτι στελνεις για να βελτιωνει τα προιοντα της.
# Εντάξει για δημοσια datasets. ΠΟΤΕ για εγγραφες πραγματικων χρηστων της εφαρμογης.
import argparse
import csv
import http.client
import json
import os
import random
import sys
import time
import urllib.error
import urllib.request

from common import COLUMNS, INTERIM, PY_ROOT, SEED, idx

from labels import LABEL_SETS, MULTI_LABEL_TASKS, TASKS

HERE = os.path.dirname(os.path.abspath(__file__))
PROCESSED = os.path.join(HERE, "processed")
OUT_COLUMNS = COLUMNS + ["text_src", "translator", "label_check", "check_reason"]
ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"

PROMPT = """Είσαι επαγγελματίας μεταφραστής από τα αγγλικά στα ελληνικά.
Μετάφρασε κάθε κείμενο σε φυσικά, καθημερινά ελληνικά, όπως θα τα έγραφε ένας Έλληνας σε προσωπικό ημερολόγιο ή στο διαδίκτυο.

Κανόνες:
- Κράτα ακριβώς το ίδιο νόημα, συναίσθημα και ένταση. Μην απαλύνεις και μην ενισχύεις τίποτα.
- Κράτα τον ίδιο τρόπο σκέψης του συγγραφέα (π.χ. υπερβολές, "πρέπει", γενικεύσεις, ταμπέλες), γιατί αυτά μελετάμε.
- Μην προσθέτεις και μην αφαιρείς περιεχόμενο. Μη λογοκρίνεις, ακόμη και για δύσκολα θέματα όπως αυτοκτονία.
- Ιδιωματισμούς απόδωσέ τους με αντίστοιχη ελληνική έκφραση, όχι κατά λέξη.
- Τα [NAME] αντικατέστησέ τα με ένα συνηθισμένο ελληνικό όνομα. Τα υπόλοιπα [...] άφησέ τα ως έχουν.
{risk_rule}{check_rule}
Θα λάβεις JSON λίστα με αντικείμενα {{"i": αριθμός, "text": κείμενο}}. Επίστρεψε για ΚΑΘΕ αντικείμενο το ίδιο "i" και τη μετάφραση στο "el".
"""

CHECK_RULE = """- Κάποια αντικείμενα έχουν και "labels": όσα έβαλαν άνθρωποι στο αγγλικό κείμενο. Βάλε "check": "no" μόνο όταν κάποιο από αυτά είναι ξεκάθαρα λάθος για το κείμενο, και γράψε στο "reason" μια σύντομη εξήγηση στα ελληνικά.
  Σε κάθε άλλη περίπτωση (σωστά, αμφίβολα ή χωρίς "labels") βάλε "check": "yes". Μην κρίνεις labels που λείπουν, μόνο όσα δίνονται.
"""

TASK_NAMES = {
    "sentiment": "πολικότητα",
    "emotion": "συναισθήματα",
    "irony": "ειρωνεία",
    "distortion": "γνωστική διαστρέβλωση",
    "distortion_types": "τύποι διαστρέβλωσης (Burns)",
    "risk": "κίνδυνος αυτοτραυματισμού/αυτοκτονίας",
}

CSSRS_RULE = """- Σε αυτά τα κείμενα κάποιες λέξεις έχουν αντικατασταθεί αυτόματα από ιατρικούς όρους στα αγγλικά (π.χ. "Pain", "Ache", "Hyperactive behavior", "Depressed mood", "Suicidal"), συχνά σε σημεία όπου δεν ταιριάζουν γραμματικά. Απόδωσέ τα με την απλή, καθημερινή λέξη που ταιριάζει στο νόημα της πρότασης.
"""

RISK_RULE = """- Επιπλέον, για κάθε κείμενο βάλε "risk": "yes" αν ο ΙΔΙΟΣ ο συγγραφέας δείχνει αυτοκτονικό ιδεασμό, σκέψεις ή σχέδια αυτοκτονίας, αυτοτραυματισμό ή προηγούμενη απόπειρα (ρητά ή έμμεσα), αλλιώς "no".
  Όταν μιλάει για κάποιον άλλο, δίνει συμβουλές ή στηρίζει άλλους χωρίς να δείχνει ο ίδιος κίνδυνο, βάλε "no"."""


def schema(with_risk: bool, check: bool) -> dict:
    props = {"i": {"type": "INTEGER"}, "el": {"type": "STRING"}}
    req = ["i", "el"]
    if with_risk:
        props["risk"] = {"type": "STRING", "enum": ["yes", "no"]}
        req.append("risk")
    if check:
        props["check"] = {"type": "STRING", "enum": ["yes", "no"]}
        props["reason"] = {"type": "STRING"}
        req.append("check")
    return {"type": "ARRAY", "items": {"type": "OBJECT", "properties": props, "required": req}}


def describe(r: dict) -> str:
    parts = []
    for t in TASKS:
        v = str(r.get(t, "")).strip()
        if not v:
            continue
        if t in MULTI_LABEL_TASKS:
            names = "κανένα" if v == "none" else ", ".join(LABEL_SETS[t][int(i)] for i in v.split("|"))
        else:
            names = LABEL_SETS[t][int(float(v))]
        parts.append(f"{TASK_NAMES[t]}: {names}")
    return "; ".join(parts)


def item(i: int, r: dict, check: bool) -> dict:
    labels = describe(r) if check else ""
    return {"i": i, "text": r["text"], "labels": labels} if labels else {"i": i, "text": r["text"]}


def load_key() -> str:
    key = os.environ.get("GEMINI_API_KEY", "")
    for env in (os.path.join(PY_ROOT, ".env"), os.path.join(os.path.dirname(PY_ROOT), ".env")):
        if key or not os.path.exists(env):
            continue
        for line in open(env, encoding="utf-8"):
            if line.strip().startswith("GEMINI_API_KEY="):
                key = line.split("=", 1)[1].strip().strip('"').strip("'")
    return key


def prompt_for(cssrs: bool, with_risk: bool, check: bool) -> str:
    extra = (CSSRS_RULE if cssrs else "") + (RISK_RULE if with_risk else "")
    return PROMPT.format(risk_rule=extra, check_rule=CHECK_RULE if check else "")


def call(model: str, key: str, items: list[dict], cssrs: bool, with_risk: bool, check: bool,
         retries: int = 6) -> list[dict]:
    body = {
        "systemInstruction": {"parts": [{"text": prompt_for(cssrs, with_risk, check)}]},
        "contents": [{"role": "user", "parts": [{"text": json.dumps(items, ensure_ascii=False)}]}],
        "generationConfig": {
            "temperature": 0.2,
            "responseMimeType": "application/json",
            "responseSchema": schema(with_risk, check),
        },
        # χωρις αυτα, κειμενα για αυτοκτονια μπλοκαρονται και χανεται ακριβως το risk set
        "safetySettings": [{"category": c, "threshold": "BLOCK_NONE"} for c in (
            "HARM_CATEGORY_HARASSMENT", "HARM_CATEGORY_HATE_SPEECH",
            "HARM_CATEGORY_SEXUALLY_EXPLICIT", "HARM_CATEGORY_DANGEROUS_CONTENT")],
    }
    req = urllib.request.Request(
        ENDPOINT.format(model=model), data=json.dumps(body).encode("utf-8"),
        headers={"Content-Type": "application/json", "x-goog-api-key": key}, method="POST")
    wait = 10
    for attempt in range(retries):
        try:
            with urllib.request.urlopen(req, timeout=300) as resp:
                data = json.load(resp)
            cand = (data.get("candidates") or [{}])[0]
            parts = cand.get("content", {}).get("parts", [])
            if not parts:
                raise ValueError(f"κενη απαντηση (finishReason={cand.get('finishReason')}, "
                                 f"block={data.get('promptFeedback', {}).get('blockReason')})")
            return json.loads("".join(p.get("text", "") for p in parts))
        except urllib.error.HTTPError as e:
            full = e.read().decode("utf-8", "replace")
            msg = full[:300]
            if e.code == 429 and ("PerDay" in full or "per day" in full.lower()):
                sys.exit("[!] Τελειωσε το ΗΜΕΡΗΣΙΟ οριο του δωρεαν tier για το " + model + ".\n"
                         "    Οτι εγινε εχει σωθει. Ξανατρεξε την ιδια εντολη μετα το reset (μεσανυχτα ωρα Καλιφορνιας,\n"
                         "    δηλ. 10:00 ωρα Ελλαδας) ή δοκιμασε αλλο μοντελο με --model.")
            if e.code in (429, 500, 503) and attempt < retries - 1:
                print(f"   HTTP {e.code}, αναμονη {wait}s...")
                time.sleep(wait)
                wait = min(wait * 2, 300)
                continue
            if e.code in (400, 401, 403):
                sys.exit(f"[!] HTTP {e.code}: {msg}\n    Ελεγξε το GEMINI_API_KEY και το --model.")
            raise RuntimeError(f"HTTP {e.code}: {msg}")
        except (OSError, http.client.HTTPException, ValueError) as e:
            if attempt < retries - 1:
                print(f"   {e} -> ξαναδοκιμη σε {wait}s")
                time.sleep(wait)
                wait = min(wait * 2, 300)
                continue
            raise RuntimeError(str(e))


def read(path: str) -> list[dict]:
    with open(path, encoding="utf-8") as f:
        return list(csv.DictReader(f))


def sample(rows: list[dict], limit: int) -> list[dict]:
    if not limit or len(rows) <= limit:
        return rows
    rng = random.Random(SEED)
    return rng.sample(rows, limit)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--sources", nargs="*", default=None, help="π.χ. codipad goemotions (default: ολα)")
    ap.add_argument("--limit", type=int, default=0, help="μεγιστος αριθμος κειμενων ανα αγγλικη πηγη (0 = ολα)")
    ap.add_argument("--model", default="gemini-3.8-flash")
    ap.add_argument("--batch", type=int, default=80, help="κειμενα ανα κληση (μεγαλυτερο = λιγοτερες κλησεις απο το ημερησιο οριο)")
    ap.add_argument("--rpm", type=float, default=8, help="μεγιστες κλησεις ανα λεπτο")
    ap.add_argument("--no-check", action="store_true", help="χωρις ελεγχο των labels")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()
    check = not args.no_check

    key = "" if args.dry_run else load_key()
    if not args.dry_run and not key:
        sys.exit("[!] Δεν βρεθηκε GEMINI_API_KEY (ουτε στο περιβαλλον ουτε στο .env).")
    os.makedirs(PROCESSED, exist_ok=True)

    names = args.sources or sorted(f[:-4] for f in os.listdir(INTERIM) if f.endswith(".csv"))
    for name in names:
        rows = read(os.path.join(INTERIM, f"{name}.csv"))
        out_path = os.path.join(PROCESSED, f"{name}.csv")
        if rows and rows[0]["lang"] == "el":
            if not args.dry_run:
                with open(out_path, "w", encoding="utf-8", newline="") as f:
                    w = csv.DictWriter(f, fieldnames=OUT_COLUMNS)
                    w.writeheader()
                    for r in rows:
                        w.writerow({**r, "text_src": "", "translator": "original"})
            print(f"[{name}] ελληνικα, αντιγραφη {len(rows)} γραμμων")
            continue

        if os.path.exists(out_path) and os.path.getsize(out_path) > 0:
            with open(out_path, encoding="utf-8") as f:
                header = next(csv.reader(f), [])
            if header != OUT_COLUMNS:
                sys.exit(f"[!] Το {out_path} εχει παλια μορφη στηλων. Σβησε το και ξανατρεξε.")
        todo = sample(rows, args.limit)
        done = {r["id"] for r in read(out_path)} if os.path.exists(out_path) else set()
        todo = [r for r in todo if r["id"] not in done]
        print(f"[{name}] {len(done)} ηδη ετοιμα, {len(todo)} για μεταφραση")
        if not todo:
            continue

        if args.dry_run:
            cssrs = todo[0]["source"] == "cssrs"
            print(prompt_for(cssrs, cssrs and any(r["risk"] == "" for r in todo), check))
            print(json.dumps([item(i, r, check) for i, r in enumerate(todo[:3])], ensure_ascii=False, indent=1), "...")
            continue
        new = not os.path.exists(out_path) or os.path.getsize(out_path) == 0
        out = open(out_path, "a", encoding="utf-8", newline="")
        w = csv.DictWriter(out, fieldnames=OUT_COLUMNS)
        if new:
            w.writeheader()
        failed = 0
        try:
            for b in range(0, len(todo), args.batch):
                batch = todo[b:b + args.batch]
                cssrs = batch[0]["source"] == "cssrs"
                with_risk = cssrs and any(r["risk"] == "" for r in batch)
                items = [item(i, r, check) for i, r in enumerate(batch)]
                t0 = time.time()
                try:
                    result = call(args.model, key, items, cssrs, with_risk, check)
                except RuntimeError as e:
                    print(f"   [!] παρτιδα {b // args.batch} απετυχε: {e}")
                    failed += len(batch)
                    continue
                got = {x.get("i"): x for x in result if isinstance(x, dict)}
                for i, r in enumerate(batch):
                    x = got.get(i)
                    if not x or not str(x.get("el", "")).strip():
                        failed += 1  # θα ξαναδοκιμαστει στο επομενο τρεξιμο
                        continue
                    row = {**r, "text": x["el"].strip(), "lang": "el", "text_src": r["text"],
                           "translator": args.model}
                    if r["source"] == "cssrs" and r["risk"] == "" and x.get("risk") in ("yes", "no"):
                        row["risk"] = idx("risk", "Ναι" if x["risk"] == "yes" else "Όχι")
                        row["meta"] = f"{r['meta']};risk_by={args.model}"
                    if check and "labels" in items[i]:
                        rejected = x.get("check") == "no"
                        row["label_check"] = "rejected" if rejected else "ok"
                        row["check_reason"] = str(x.get("reason", "")).strip() if rejected else ""
                    w.writerow(row)
                out.flush()
                print(f"   {min(b + args.batch, len(todo))}/{len(todo)}")
                time.sleep(max(0.0, 60.0 / args.rpm - (time.time() - t0)))
        except KeyboardInterrupt:
            print("\n[!] Διακοπη. Οτι εγινε εχει σωθει, ξανατρεξε για να συνεχισεις.")
            out.close()
            sys.exit(1)
        out.close()
        if check:
            checked = [r for r in read(out_path) if r["label_check"]]
            rejected = sum(r["label_check"] == "rejected" for r in checked)
            if checked:
                print(f"   ελεγχος labels: {rejected}/{len(checked)} rejected ({rejected / len(checked):.0%})")
        if failed:
            print(f"   [!] {failed} δεν μεταφραστηκαν, ξανατρεξε την ιδια εντολη για να ξαναδοκιμασει μονο αυτα")


if __name__ == "__main__":
    main()
