import argparse
import json
import os
import re

import pandas as pd
from transformers import AutoTokenizer

from eval_types import HERE, TRAIN, gold, load_model
import main
from model_architecture import MODEL_NAME, MODEL_REVISION

SUMMARY = os.path.join(HERE, "output", "eval_floor.json")
KEEP_RULE = 0.80
SKIP_RULE = 0.50
PHI = 0.01
PHI_GRID = [0.0, 0.01, 0.02, 0.03, 0.05, 0.10, 0.15, 0.20, 0.30]
PSI_GRID = [0.0, 0.0001, 0.0002, 0.0005, 0.001, 0.002, 0.005, 0.01, 0.02, 0.05, 0.10, 0.20, 0.30]
SIGNALS = {"phi": "p1", "psi": "pd"}


def model_text(first: str, second: str) -> str:
    first, second = first.strip(), second.strip()
    if not first or not second:
        return first or second
    end = re.sub(r"[\s»\"')\]]+$", "", first)
    if not re.search(r"[.!?;;…]$", end):
        first = re.sub(r"[\s,·:–—-]+$", "", first) + "."
    return f"{first} {second}"


def run(texts) -> list[dict]:
    rows = []
    for text in texts:
        out = main.run_mtl(text)["tasks"]["distortion"]
        probs = out["type_probabilities"]
        order = sorted(probs, key=lambda l: -probs[l])
        rows.append({"text": text, "detected": bool(out["detected"]), "top2": order[:2],
                     "p1": probs[order[0]], "pd": out["probability"]})
    return rows


def skips(r: dict, signal: str, floor: float) -> bool:
    return not r["detected"] and r[SIGNALS[signal]] < floor


def table(catchable: list[dict], realistic: list[dict], signal: str, grid: list[float]) -> list[dict]:
    rows = []
    for floor in grid:
        kept = sum(not skips(r, signal, floor) for r in catchable)
        skipped = sum(skips(r, signal, floor) for r in realistic)
        rows.append({"floor": floor, "kept": kept, "keep_rate": kept / len(catchable) if catchable else None,
                     "skipped": skipped, "skip_rate": skipped / len(realistic) if realistic else None})
    return rows


def choose(rows: list[dict]) -> tuple[dict | None, bool]:
    ok = [s for s in rows if s["keep_rate"] is not None and s["keep_rate"] >= KEEP_RULE]
    best = ok[-1] if ok else None
    return best, best is not None and best["skip_rate"] is not None and best["skip_rate"] >= SKIP_RULE


def flips(singles: list[dict], joined: list[dict], signal: str, floor: float) -> dict:
    n = len(singles)
    both = [i for i in range(n) if skips(singles[i], signal, floor) and skips(singles[(i + 1) % n], signal, floor)]
    flipped = sum(not skips(joined[i], signal, floor) for i in both)
    return {"floor": floor, "both_skip": len(both), "flipped": flipped,
            "flip_rate": flipped / len(both) if both else None}


def show(title: str, symbol: str, rows: list[dict], chosen: float | None) -> None:
    print(f"\n{title}")
    print(f"{symbol:>8}{'κρατα':>8}{'% κρατα':>10}{'γλιτωνουν':>11}{'% γλιτ.':>9}")
    for s in rows:
        mark = "<-" if chosen is not None and s["floor"] == chosen else ""
        print(f"{s['floor']:>8.4f}{s['kept']:>8}{s['keep_rate'] or 0:>10.1%}{s['skipped']:>11}"
              f"{s['skip_rate'] or 0:>9.1%}  {mark}")


def flip_line(name: str, f: dict | None) -> str:
    if f is None:
        return f"    {name}: -"
    rate = f"{f['flip_rate']:.1%}" if f["flip_rate"] is not None else "-"
    return f"    {name} = {f['floor']:.4f}: {f['flipped']}/{f['both_skip']} ζευγαρια περνουν σε ερωτησεις ({rate})"


def main_():
    ap = argparse.ArgumentParser()
    ap.add_argument("--model", default="runs/2026-11-02_131743")
    ap.add_argument("--limit", type=int, default=0)
    args = ap.parse_args()

    df = pd.read_csv(TRAIN, dtype=str, keep_default_na=False)
    cod = df[(df.source == "codipad") & (df.split == "val")]
    yes = cod[cod.distortion == "1"].reset_index(drop=True)
    no = cod[cod.distortion == "0"].reset_index(drop=True)
    if args.limit:
        yes, no = yes.head(args.limit), no.head(args.limit)

    tok = AutoTokenizer.from_pretrained(MODEL_NAME, revision=MODEL_REVISION)
    load_model(args.model, tok)
    print(f"[*] CODIPAD validation: {len(yes)} με διαστρεβλωση, {len(no)} χωρις | {args.model} | {main.device}")
    rows_yes = run(yes.text)
    rows_no = run(no.text)

    for r, cell in zip(rows_yes, yes.distortion_types):
        r["catchable"] = bool(set(r["top2"]) & gold(cell))
    missed = [r for r in rows_yes if not r["detected"]]
    catchable = [r for r in missed if r["catchable"]]
    realistic = [r for r in rows_no if not r["detected"]]
    n = len(realistic)
    joined = run(model_text(realistic[i]["text"], realistic[(i + 1) % n]["text"]) for i in range(n))
    main.ml.clear()

    print(f"\n[*] «Οχι» σε κειμενα ΜΕ διαστρεβλωση: {len(missed)}/{len(rows_yes)}")
    print(f"    απο αυτα, ο σωστος τυπος στους 2 πρωτους (τα πιανουν οι ερωτησεις): {len(catchable)}")
    print(f"[*] «Οχι» σε κειμενα ΧΩΡΙΣ διαστρεβλωση: {len(realistic)}/{len(rows_no)}")

    phi_rows = table(catchable, realistic, "phi", PHI_GRID)
    psi_rows = table(catchable, realistic, "psi", PSI_GRID)
    psi_best, psi_passed = choose(psi_rows)
    phi_flip = flips(realistic, joined, "phi", PHI)
    psi_flip = flips(realistic, joined, "psi", psi_best["floor"]) if psi_best else None
    steadier = (psi_flip is not None and psi_flip["flip_rate"] is not None and phi_flip["flip_rate"] is not None
                and psi_flip["flip_rate"] < phi_flip["flip_rate"])
    replaces = psi_passed and steadier

    show("φ: πιθανοτητα του 1ου τυπου (Β58, για συγκριση)", "φ", phi_rows, PHI)
    show("ψ: πιθανοτητα «παγιδα ναι»", "ψ", psi_rows, psi_best["floor"] if psi_best else None)

    print("\n[*] Ενωμενα ζευγαρια ρεαλιστικων: απο οσα κανενα μονο του δεν κανει ερωτησεις")
    print(flip_line("φ", phi_flip))
    print(flip_line("ψ", psi_flip))

    print(f"\n=== Κανονας: το μεγαλυτερο ψ που κραταει >= {KEEP_RULE:.0%} απο οσα πιανουν οι ερωτησεις. Αντικαθιστα το φ"
          f" μονο αν γλιτωνουν >= {SKIP_RULE:.0%} των ρεαλιστικων και στα ενωμενα περνουν λιγοτερα απο το φ")
    if replaces:
        print(f"    ψ = {psi_best['floor']:.4f}: κραταει {psi_best['kept']}/{len(catchable)} ({psi_best['keep_rate']:.1%}),"
              f" γλιτωνουν {psi_best['skipped']}/{len(realistic)} ({psi_best['skip_rate']:.1%}). Το ψ αντικαθιστα το φ")
    elif psi_best is None:
        print("    δεν περναει: κανενα ψ δεν κραταει αρκετα. Μενει το φ = 0,01")
    elif not psi_passed:
        print(f"    δεν περναει: στο ψ = {psi_best['floor']:.4f} γλιτωνουν μονο {psi_best['skip_rate']:.1%}."
              " Μενει το φ = 0,01")
    else:
        print(f"    δεν περναει: στο ψ = {psi_best['floor']:.4f} τα ενωμενα δεν ειναι πιο σταθερα απο το φ."
              " Μενει το φ = 0,01")

    if args.limit:
        print("   (--limit: δοκιμη του script, τα νουμερα δεν μετρανε, δεν γραφεται json)")
        return

    os.makedirs(os.path.dirname(SUMMARY), exist_ok=True)
    with open(SUMMARY, "w", encoding="utf-8") as f:
        json.dump({"model": args.model,
                   "rule": {"keep": KEEP_RULE, "skip": SKIP_RULE, "steadier_than_phi": PHI},
                   "missed": len(missed), "catchable": len(catchable), "realistic_no": len(realistic),
                   "phi": {"value": PHI, "table": phi_rows, "joined": phi_flip},
                   "psi": {"best": psi_best, "passed": psi_passed, "table": psi_rows, "joined": psi_flip},
                   "replaces_phi": replaces}, f, ensure_ascii=False, indent=2)
    print(f"[+] -> {SUMMARY}")


if __name__ == "__main__":
    main_()
