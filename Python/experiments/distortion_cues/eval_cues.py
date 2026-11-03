import json
import os
import re
import sys
import unicodedata

import pandas as pd
import torch
from transformers import AutoTokenizer

PY = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
sys.path.insert(0, PY)
sys.path.insert(0, os.path.join(PY, "training"))
import main
from eval_check_set import NEGATIVE, YES, load, mcnemar, rate
from labels import LABEL_SETS
from model_architecture import MODEL_NAME, MODEL_REVISION, WellnessMTLModel

TRAIN = os.path.join(PY, "training", "train.csv")
MODELS = {"s43": "runs/2026-11-02_131743", "s42": "runs/2026-11-01_183329"}
BASE = "s43"

CUES = {
    "Υπεργενίκευση": [r"\bπαντα\b", r"\bκανενασ\b", r"\bολοι\b", r"\bολεσ\b", r"\bκαθε φορα\b"],
    "Ταμπέλες": [r"\b(ειμαι|εισαι|ειναι|ημουν)\s+(ενασ\s+|μια\s+|ενα\s+)?(εντελωσ\s+|τελειωσ\s+|σκετοσ\s+|σκετη\s+)?"
                 r"(αποτυχημεν|αχρηστ|ηλιθι|βλακ|ανικαν|τιποτενι|λουζερ|ανοητ|αξιολυπητ|χαζ)\w*"],
    "Προφητεία": [r"\bξερω (οτι|πωσ)\b.{0,40}\bθα\b", r"\bσιγουρα (δεν )?θα\b", r"\bειμαι σιγουρ(οσ|η) (οτι|πωσ) (δεν )?θα\b",
                  r"\bδεν (προκειται|προκειτε) (ποτε )?να\b", r"\bθα (παει|παω|βγει) (χαλια|στραβα|ασχημα)\b",
                  r"\bθα αποτυχω\b", r"\bδεν θα (τα )?καταφερω\b"],
    "Καταστροφολογία": [r"\bτελειωσε η ζωη μου\b", r"\bη ζωη μου (τελειωσε|καταστραφηκε|ειναι κολαση)\b", r"\bκαταστροφ\w*",
                        r"\bτο τελοσ του κοσμου\b", r"\bολα (χαθηκαν|τελειωσαν)\b", r"\bδεν θα (το )?ξεπερασω ποτε\b",
                        r"\bτο χειροτερο\b"],
    "Όλα ή τίποτα": [r"\b(ολα|τελεια|τελειο) η τιποτα\b", r"\bσαν να (απετυχα|μην εκανα τιποτα)\b",
                     r"\bειτε\b.{0,60}\bειτε\b", r"\bαπολυτ\w* αποτυχ\w*"],
    "Ανάγνωση σκέψης": [r"\bσιγουρα με (βαρεθηκαν|βαρεθηκε|μισουν|μισει|σιχαινονται|σιχαινεται|θεωρουν|θεωρει|κοροιδευουν)\b",
                        r"\b(ολοι|κανεισ) με (μισουν|μισει|θελει|βαρεθηκαν|συμπαθει|αγαπαει|αγαπα)\b",
                        r"\bμε (θεωρουν|θεωρει|βλεπουν σαν) (χαζ|ηλιθι|βλακ|αχρηστ|αποτυχημεν)\w*",
                        r"\b(νομιζουν|πιστευουν|σκεφτονται) (οτι|πωσ) ειμαι\b", r"\bξερω τι σκεφτ\w*"],
    "Συναισθηματική συλλογιστική": [r"\bνιωθω\b.{0,40}\bαρα\b", r"\bαφου (το )?νιωθω\b",
                                    r"\bνιωθω\b.{0,30}\bοποτε (ειμαι|ειναι)\b"],
    "Πρέπει": [r"\bθα επρεπε\b", r"\bεπρεπε να\b", r"\bδεν επρεπε\b", r"\bπρεπει (οπωσδηποτε|παντα)\b", r"\bοφειλω\b"],
    "Προσωποποίηση": [r"\b(φταιω εγω|εγω φταιω)\b", r"\bφταιω (για|που)\b", r"\bφταιξιμο μου\b",
                      r"\b(δικο μου|δικα μου) (φταιξιμο|λαθοσ)\b", r"\bεξαιτιασ μου\b"],
    "Νοητικό φίλτρο": [r"\bτα χαλασε ολα\b", r"\bχαλασε (ολη )?τη μερα\b", r"\bτιποτα (δεν )?(παει|πηγε|πανε) καλα\b",
                       r"\bολα (πανε|πηγαν) στραβα\b", r"\bμονο (τα )?(κακα|αρνητικα)\b"],
}
ACCENTED = {"Υπεργενίκευση": [r"\bποτ[εέ]\b", r"\bκανε[ίι]ς\b"]}


def plain(text: str) -> str:
    text = unicodedata.normalize("NFD", text.casefold())
    return re.sub(r"\s+", " ", "".join(c for c in text if unicodedata.category(c) != "Mn"))


def cues(text: str) -> set[str]:
    p, low = plain(text), re.sub(r"\s+", " ", unicodedata.normalize("NFC", text.lower()))
    found = {t for t, patterns in CUES.items() if any(re.search(x, p) for x in patterns)}
    return found | {t for t, patterns in ACCENTED.items() if any(re.search(x, low) for x in patterns)}


def predict(name: str, texts: list[str], tok) -> list[bool]:
    with open(os.path.join(PY, MODELS[name], "mtl_config.json"), encoding="utf-8") as f:
        config = json.load(f)
    if config.get("labels") != LABEL_SETS:
        sys.exit(f"[!] {name}: τα labels του config διαφερουν απο το labels.py")
    model = WellnessMTLModel().to(main.device)
    model.load_state_dict(torch.load(os.path.join(PY, MODELS[name], "mtl_model.pt"), map_location=main.device,
                                     weights_only=True))
    model.eval()
    main.ml.update(tokenizer=tok, model=model, trained_tasks=set(config.get("trained_tasks", [])),
                   trained_labels=config.get("trained_labels", {}), thresholds=config.get("thresholds", {}))
    out = [main.run_mtl(t)["tasks"]["distortion"]["detected"] for t in texts]
    main.ml.clear()
    return out


def check_line(label: str, pred: list[bool], user: list[bool], negative: list[bool]) -> dict:
    said = sum(pred)
    tp = sum(p and u for p, u in zip(pred, user))
    fp_neg = sum(p and not u and g for p, u, g in zip(pred, user, negative))
    no_neg = sum(g and not u for u, g in zip(user, negative))
    print(f"      {label:<22}Ναι {said:>3} | ακριβεια {rate(tp, said)}| βρηκε {tp}/{sum(user)} | "
          f"λαθος Ναι σε Οχι {said - tp}/{len(user) - sum(user)} | σε αρνητικα Οχι {fp_neg}/{no_neg}")
    return {"said": said, "tp": tp, "fp_neg": fp_neg}


def codipad_line(label: str, pred: list[bool], y: list[bool]) -> float:
    tp = sum(p and t for p, t in zip(pred, y))
    fp = sum(p and not t for p, t in zip(pred, y))
    fn = sum(t and not p for p, t in zip(pred, y))
    print(f"      {label:<22}ανακληση {tp}/{sum(y)} = {tp / sum(y):.3f} | ακριβεια {rate(tp, tp + fp)}| F1 {2 * tp / (2 * tp + fp + fn):.3f}")
    return tp / sum(y)


def main_():
    df = pd.read_csv(TRAIN, dtype=str, keep_default_na=False)
    cod = df[(df.split == "val") & (df.distortion != "")]
    check = load()
    n = len(check)
    texts = [r["text"] for r in check] + cod.text.tolist()
    found = [cues(t) for t in texts]
    has = [bool(f) for f in found]
    user = [r["distortion"] == YES for r in check]
    negative = [r["stratum"] in NEGATIVE for r in check]
    y = (cod.distortion == "1").tolist()

    print(f"σετ ελεγχου {n} (Ναι χρηστη {sum(user)}) | CODIPAD validation {len(cod)} (Ναι {sum(y)})")
    print(f"φραση-σημαδι βρεθηκε: σετ ελεγχου {sum(has[:n])}/{n}, CODIPAD Ναι {sum(h for h, t in zip(has[n:], y) if t)}/{sum(y)}, "
          f"CODIPAD Οχι {sum(h for h, t in zip(has[n:], y) if not t)}/{len(y) - sum(y)}")
    match = sum(bool(found[i] & set(filter(None, check[i]["distortion_types"].split("|")))) for i in range(n) if user[i])
    print(f"στα {sum(user)} Ναι του χρηστη: φραση του ιδιου τυπου με του χρηστη σε {match}, καποια φραση σε "
          f"{sum(has[i] for i in range(n) if user[i])}")
    print("\n   Μονο οι φρασεις, χωρις μοντελο")
    check_line("φρασεις", has[:n], user, negative)
    codipad_line("φρασεις", has[n:], y)

    tok = AutoTokenizer.from_pretrained(MODEL_NAME, revision=MODEL_REVISION)
    results = {}
    for name in MODELS:
        base = predict(name, texts, tok)
        rule = [b and h for b, h in zip(base, has)]
        print(f"\n=== {name}")
        print("   Σετ ελεγχου")
        b = check_line("σημερα", base[:n], user, negative)
        r = check_line("μοντελο + φραση", rule[:n], user, negative)
        removed = sum(x and not z and not u and g for x, z, u, g in zip(base[:n], rule[:n], user, negative))
        lost = sum(x and not z and u for x, z, u in zip(base[:n], rule[:n], user))
        print(f"      κοπηκαν {removed} λαθος Ναι στα αρνητικα (McNemar p = {mcnemar(removed, 0):.4f}), χαθηκαν {lost} Ναι του χρηστη")
        print("   CODIPAD validation")
        recall_base = codipad_line("σημερα", base[n:], y)
        recall_rule = codipad_line("μοντελο + φραση", rule[n:], y)
        results[name] = {"removed": removed, "p": mcnemar(removed, 0), "lost": lost, "b": b, "r": r,
                         "recall_base": recall_base, "recall_rule": recall_rule}

    s, other = results[BASE], results["s42"]
    precision = s["r"]["tp"] / s["r"]["said"] if s["r"]["said"] else 0.0
    c1 = s["p"] < 0.05 and other["removed"] > 0
    c2 = precision >= 0.30
    c3 = s["recall_rule"] >= 0.40
    print(f"\n=== Αποφαση κατα το πρωτοκολλο (Β38), μοντελο {BASE}")
    print(f"   1. λιγοτερα λαθος Ναι στα αρνητικα, p < 0,05 και στο s42 πτωση: {'ναι' if c1 else 'οχι'}")
    print(f"   2. ακριβεια στα 200 >= 0,30: {precision:.2f} -> {'ναι' if c2 else 'οχι'}")
    print(f"   3. ανακληση CODIPAD >= 0,40: {s['recall_rule']:.3f} -> {'ναι' if c3 else 'οχι'}")
    print(f"   -> {'ΠΕΡΝΑΕΙ' if c1 and c2 and c3 else 'δεν περναει'}")
    strict = c1 and s["recall_base"] - s["recall_rule"] <= 0.02 and s["lost"] <= 1
    print(f"   για συγκριση, με τα κριτηρια της Β37: {'περναει' if strict else 'δεν περναει'}")


if __name__ == "__main__":
    main_()
