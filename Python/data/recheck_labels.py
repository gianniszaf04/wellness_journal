import argparse
import csv
import os
import random
import sys
import time

from common import SEED
from translate_gemini import HERE, PROCESSED, call, item, load_key, read

RECHECK = os.path.join(HERE, "recheck")
FIELDS = ["id", "set", "before", "now", "reason", "distortion_types", "text_src"]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--source", default="codipad")
    ap.add_argument("--since", type=int, default=550)
    ap.add_argument("--only-old", action="store_true")
    ap.add_argument("--model", default="gemini-3.8-flash")
    ap.add_argument("--batch", type=int, default=75)
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    rows = read(os.path.join(PROCESSED, f"{args.source}.csv"))
    old = [r for r in rows[:args.since] if r["label_check"] == "rejected"]
    new = [] if args.only_old else [r for r in rows[args.since:] if r["distortion"] == "1"]
    sets = {r["id"]: "old_rejected" for r in old} | {r["id"]: "new" for r in new}
    todo = [{**r, "text": r["text_src"]} for r in old + new]
    random.Random(SEED).shuffle(todo)
    calls = -(-len(todo) // args.batch)
    print(f"[{args.source}] {len(old)} παλια rejected + {len(new)} νεα με διαστρεβλωση = {len(todo)} κειμενα, {calls} κλησεις")
    if args.dry_run:
        print(item(0, todo[0], True))
        return

    key = load_key()
    if not key:
        sys.exit("[!] Δεν βρεθηκε GEMINI_API_KEY (ουτε στο περιβαλλον ουτε στο .env).")
    os.makedirs(RECHECK, exist_ok=True)
    out_path = os.path.join(RECHECK, f"{args.source}.csv")
    results = []
    try:
        for b in range(0, len(todo), args.batch):
            batch = todo[b:b + args.batch]
            result = call(args.model, key, [item(i, r, True) for i, r in enumerate(batch)], False, False, True)
            got = {x.get("i"): x for x in result if isinstance(x, dict)}
            for i, r in enumerate(batch):
                x = got.get(i, {})
                results.append({
                    "id": r["id"], "set": sets[r["id"]], "before": r["label_check"],
                    "now": {"no": "rejected", "yes": "ok"}.get(x.get("check"), ""),
                    "reason": str(x.get("reason", "")).strip(),
                    "distortion_types": r["distortion_types"], "text_src": r["text_src"],
                })
            print(f"   {len(results)}/{len(todo)}")
            if b + args.batch < len(todo):
                time.sleep(15)
    except (RuntimeError, KeyboardInterrupt) as e:
        print(f"[!] Σταματησε: {e}")
    finally:
        with open(out_path, "w", encoding="utf-8", newline="") as f:
            w = csv.DictWriter(f, fieldnames=FIELDS)
            w.writeheader()
            w.writerows(results)

    for s in ("old_rejected", "new"):
        part = [r for r in results if r["set"] == s]
        if part:
            n = sum(r["now"] == "rejected" for r in part)
            print(f"   {s}: {n}/{len(part)} rejected τωρα")
    print(f"   αποτελεσματα: {out_path}")


if __name__ == "__main__":
    main()
