import argparse
import csv
import json
import math
import os
import sys

import torch
from transformers import AutoTokenizer

PY = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, PY)
import main
from labels import DISTORTION_TYPE_LABELS, LABEL_SETS
from model_architecture import MODEL_NAME, MODEL_REVISION, WellnessMTLModel

ANNOTATION = os.path.join(PY, "data", "annotation")
LABELS = os.path.join(ANNOTATION, "check_set.csv")
KEY = os.path.join(ANNOTATION, "check_set_key.csv")
MODELS = {"3ο": ("runs/2026-10-31_142942", "mtl_config_1se.json"),
          "s42": ("runs/2026-11-01_183329", "mtl_config.json"),
          "s43": ("runs/2026-11-02_131743", "mtl_config.json")}
MAIN_MODEL = "s43"
NEGATIVE = ("αρνητικό", "αρνητικό xed")
STRATA = ("αρνητικό", "αρνητικό xed", "κούραση", "ουδέτερο", "θετικό", "cssrs")
SOURCES = ("goemotions", "sentimp", "xed", "cssrs")
YES, NO = "Ναι", "Όχι"


def wilson(k: int, n: int, z: float = 1.96) -> tuple[float, float]:
    p = k / n
    centre = (p + z * z / (2 * n)) / (1 + z * z / n)
    half = z * math.sqrt(p * (1 - p) / n + z * z / (4 * n * n)) / (1 + z * z / n)
    return max(0.0, centre - half), min(1.0, centre + half)


def rate(k: int, n: int) -> str:
    if n == 0:
        return f"{'-':<28}"
    lo, hi = wilson(k, n)
    return f"{f'{k}/{n} {k / n:.2f} [{lo:.2f}, {hi:.2f}]':<28}"


def mcnemar(b: int, c: int) -> float:
    n = b + c
    if n == 0:
        return 1.0
    return min(1.0, 2 * sum(math.comb(n, i) for i in range(min(b, c) + 1)) / 2 ** n)


def load() -> list[dict]:
    with open(KEY, encoding="utf-8-sig", newline="") as f:
        key = {r["id"]: r for r in csv.DictReader(f, delimiter=";")}
    with open(LABELS, encoding="utf-8-sig", newline="") as f:
        rows = list(csv.DictReader(f, delimiter=";"))
    return [{**r, "source": key[r["id"]]["source"], "stratum": key[r["id"]]["stratum"],
             "types": set(filter(None, r["distortion_types"].split("|")))} for r in rows]


def predict(name: str, texts: list[str], tok) -> tuple[list[dict], bool]:
    folder, config_file = MODELS[name]
    with open(os.path.join(PY, folder, config_file), encoding="utf-8") as f:
        config = json.load(f)
    if config.get("labels") != LABEL_SETS:
        sys.exit(f"[!] {name}: τα labels του {config_file} διαφερουν απο το labels.py")
    model = WellnessMTLModel().to(main.device)
    model.load_state_dict(torch.load(os.path.join(PY, folder, "mtl_model.pt"), map_location=main.device,
                                     weights_only=True))
    model.eval()
    trained = config.get("trained_labels", {})
    main.ml.update(tokenizer=tok, model=model, trained_tasks=set(config.get("trained_tasks", [])),
                   trained_labels=trained, thresholds=config.get("thresholds", {}))
    out = []
    for text in texts:
        d = main.run_mtl(text)["tasks"]
        out.append({"distortion": d["distortion"]["detected"], "p": d["distortion"]["probability"],
                    "types": set(d["distortion"]["types"]), "fatigue": "Κούραση" in d["emotion"]["labels"]})
    main.ml.clear()
    return out, "Κούραση" in trained.get("emotion", LABEL_SETS["emotion"])


def binary(rows: list[dict], pred: list[dict], task: str, groups: list[tuple[str, list[int]]]) -> None:
    print(f"      {'':<24}{'n':>4}{'Ναι χρηστη':>12}{'Ναι μοντελου':>14}   {'ακριβεια':<28}{'ανακληση':<28}Ναι εκει που ο χρηστης ειπε Οχι")
    for label, idx in groups:
        idx = [i for i in idx if rows[i][task] in (YES, NO)]
        user = {i for i in idx if rows[i][task] == YES}
        said = {i for i in idx if pred[i][task]}
        no = [i for i in idx if i not in user]
        print(f"      {label:<24}{len(idx):>4}{len(user):>12}{len(said):>14}   {rate(len(user & said), len(said))}"
              f"{rate(len(user & said), len(user))}{rate(len(said & set(no)), len(no))}")


def paired(rows: list[dict], preds: dict, a: str, b: str, task: str) -> None:
    for label, truth in (("εκει που ο χρηστης ειπε Οχι", NO), ("εκει που ο χρηστης ειπε Ναι", YES)):
        idx = [i for i, r in enumerate(rows) if r[task] == truth]
        only_a = sum(preds[a][i][task] and not preds[b][i][task] for i in idx)
        only_b = sum(preds[b][i][task] and not preds[a][i][task] for i in idx)
        print(f"      {a} -> {b}, {label} ({len(idx)}): Ναι μονο στο {a} {only_a}, μονο στο {b} {only_b}, "
              f"McNemar p = {mcnemar(only_a, only_b):.3f}")


def main_():
    ap = argparse.ArgumentParser()
    ap.add_argument("--models", nargs="*", default=list(MODELS))
    ap.add_argument("--out", default=None, help="csv με τις προβλεψεις ανα κειμενο (χωρις τα κειμενα)")
    args = ap.parse_args()

    rows = load()
    every = list(range(len(rows)))
    negative = [i for i in every if rows[i]["stratum"] in NEGATIVE]
    groups = [("ολα", every), ("αρνητικα (ολα)", negative)]
    groups += [(f"ομαδα {s}", [i for i in every if rows[i]["stratum"] == s]) for s in STRATA]
    groups += [(f"πηγη {s}", [i for i in every if rows[i]["source"] == s]) for s in SOURCES]
    fatigue_groups = [("ολα", every), ("ομαδα κούραση", [i for i in every if rows[i]["stratum"] == "κούραση"]),
                      ("εκτος ομαδας κουρασης", [i for i in every if rows[i]["stratum"] != "κούραση"])]
    print(f"Σετ ελεγχου: {len(rows)} κειμενα | διαστρεβλωση Ναι {sum(r['distortion'] == YES for r in rows)}"
          f" | Κουραση Ναι {sum(r['fatigue'] == YES for r in rows)} | κενα: διαστρεβλωση "
          f"{sum(r['distortion'] not in (YES, NO) for r in rows)}, Κουραση {sum(r['fatigue'] not in (YES, NO) for r in rows)}")

    tok = AutoTokenizer.from_pretrained(MODEL_NAME, revision=MODEL_REVISION)
    preds, fatigue_trained = {}, {}
    for name in args.models:
        preds[name], fatigue_trained[name] = predict(name, [r["text"] for r in rows], tok)
        pred = preds[name]
        print(f"\n=== {name}")
        print("   Διαστρεβλωση (οριο του config, οπως στην εφαρμογη)")
        binary(rows, pred, "distortion", groups)
        both = [i for i in every if rows[i]["distortion"] == YES and pred[i]["distortion"]]
        print(f"   Τυποι: κοινος τυπος σε {sum(bool(rows[i]['types'] & pred[i]['types']) for i in both)}/{len(both)}"
              f" κειμενα με Ναι και απο τους δυο")
        print("      " + ", ".join(f"{t} {sum(t in r['types'] for r in rows)}/{sum(t in p['types'] for p in pred)}"
                                   for t in DISTORTION_TYPE_LABELS) + "   (χρηστης/μοντελο)")
        missed = [rows[i]["id"] for i in every if rows[i]["distortion"] == YES and not pred[i]["distortion"]]
        print(f"   Ναι του χρηστη που δεν βρηκε ({len(missed)}): {', '.join(missed) or '-'}")
        if fatigue_trained[name]:
            print("   Κουραση")
            binary(rows, pred, "fatigue", fatigue_groups)
            missed = [rows[i]["id"] for i in every if rows[i]["fatigue"] == YES and not pred[i]["fatigue"]]
            print(f"   Κουραση του χρηστη που δεν βρηκε ({len(missed)}): {', '.join(missed) or '-'}")
        else:
            print("   Κουραση: δεν εκπαιδευτηκε σε αυτο το μοντελο")

    if MAIN_MODEL in preds:
        print(f"\n=== Συγκριση στα ιδια κειμενα")
        for other in (m for m in preds if m != MAIN_MODEL):
            paired(rows, preds, other, MAIN_MODEL, "distortion")
        idx = [i for i in negative if rows[i]["distortion"] in (YES, NO)]
        said = [i for i in idx if preds[MAIN_MODEL][i]["distortion"]]
        tp = sum(rows[i]["distortion"] == YES for i in said)
        print(f"\n=== Αποφαση κατα το πρωτοκολλο (Β35), μοντελο {MAIN_MODEL}, αρνητικα ({len(idx)})")
        if not said:
            print("   το μοντελο δεν ειπε Ναι σε κανενα αρνητικο: η ακριβεια δεν οριζεται")
        else:
            lo, hi = wilson(tp, len(said))
            verdict = ("2ος γυρος hard_no δικαιολογειται" if hi < 0.5 else
                       "δεν χρειαζεται 2ος γυρος hard_no για την ακριβεια" if lo > 0.5 else
                       "αβεβαιο: δευτερη παρτιδα κειμενων")
            print(f"   ακριβεια {tp}/{len(said)} = {tp / len(said):.2f} [{lo:.2f}, {hi:.2f}] -> {verdict}")

    if args.out:
        columns = ["id", "stratum", "source", "distortion", "distortion_types", "fatigue"]
        columns += [f"{m}_{c}" for m in preds for c in ("p", "distortion", "types", "fatigue")]
        with open(args.out, "w", encoding="utf-8-sig", newline="") as f:
            w = csv.DictWriter(f, fieldnames=columns, delimiter=";")
            w.writeheader()
            for i, r in enumerate(rows):
                row = {c: r[c] for c in columns[:6]}
                for m, pred in preds.items():
                    row |= {f"{m}_p": pred[i]["p"], f"{m}_distortion": YES if pred[i]["distortion"] else NO,
                            f"{m}_types": "|".join(sorted(pred[i]["types"])),
                            f"{m}_fatigue": (YES if pred[i]["fatigue"] else NO) if fatigue_trained[m] else ""}
                w.writerow(row)
        print(f"\n-> {args.out}")


if __name__ == "__main__":
    main_()
