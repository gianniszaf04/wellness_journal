# data/prepare_codipad.py
# CODIPAD (Zenodo 10.5281/zenodo.20307820, CC BY 4.0) -> interim/codipad.csv
# Γεμιζει μονο distortion + distortion_types. Αγγλικα -> θελει μεταφραση.
import json
import os

from common import RAW, clean, multi, summary, words, write

SRC = os.path.join(RAW, "codipad", "CODIPAD.json")
MAX_WORDS = 100  # μεγαλυτερα κομματια κοβονται στα 128 tokens και ακριβαινουν τη μεταφραση

# CODIPAD (Burns) -> labels.py
TYPE_MAP = {
    "Magnification": "Καταστροφολογία",
    "All-or-nothing thinking": "Όλα ή τίποτα",
    "Overgeneralization": "Υπεργενίκευση",
    "Mind reading": "Ανάγνωση σκέψης",
    "Fortune-telling": "Προφητεία",
    "Emotional reasoning": "Συναισθηματική συλλογιστική",
    "Should statements": "Πρέπει",
    "Labeling": "Ταμπέλες",
    "Personalization": "Προσωποποίηση",
    "Mental filter": "Νοητικό φίλτρο",
}
NONE = "No distortion"


def main():
    data = json.load(open(SRC, encoding="utf-8"))
    rows, skipped_long, seen = [], 0, set()
    for x in data:
        text = clean(x["distorted_part"])
        if not text or words(text) > MAX_WORDS:
            skipped_long += 1
            continue
        if text in seen:  # ιδιο κομματι δυο φορες -> κραταμε το πρωτο
            continue
        seen.add(text)
        d = x["distortions"]
        dom, sec = d["dominant_distortion"], d.get("secondary_distortion")
        if dom == NONE:
            distortion, types = 0, ""
        else:
            distortion = 1
            types = multi("distortion_types", [TYPE_MAP[t] for t in (dom, sec) if t and t != NONE])
        rows.append({
            "id": f"codipad-{x['id_distorted_part']}",
            "source": "codipad",
            "group": f"codipad-q{x['id_patient_question']}",
            "lang": "en",
            "text": text,
            "distortion": distortion,
            "distortion_types": types,
            "meta": x["origin"],  # organic / synthetic
        })
    print(f"[codipad] παραλειφθηκαν {skipped_long} κενα ή πανω απο {MAX_WORDS} λεξεις")
    summary("codipad", rows)
    print("->", write("codipad", rows))


if __name__ == "__main__":
    main()
