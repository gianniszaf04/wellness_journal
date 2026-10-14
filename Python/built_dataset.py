
import json
import os

import pandas as pd

from labels import LABEL_SETS, TASKS

LOG = os.getenv("VERIFY_LOG", os.path.join("..", "storage", "ai_verification.jsonl"))
OUT = os.path.join("training", "dataset_auto.csv")

INDEX = {task: {label: i for i, label in enumerate(LABEL_SETS[task])} for task in TASKS}


def main():
    if not os.path.exists(LOG):
        print(f"[!] Δεν βρεθηκε log: {LOG}")
        return

    rows = []
    with open(LOG, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line:
                continue
            rec = json.loads(line)
            gt = rec.get("ground_truth", {})
            text = rec.get("text", "").strip()
            if not text:
                continue
            # -100 οπου το OpenAI δεν εδωσε εγκυρη τιμη -> αγνοειται στο loss
            row = {"text": text}
            has_valid_label = False
            for task in TASKS:
                idx = INDEX[task].get(gt.get(task), -100)
                row[task] = idx
                if idx != -100:
                    has_valid_label = True
            # Παραλειπουμε γραμμες χωρις κανενα εγκυρο label (ολα -100) ->
            # αλλιως δημιουργουν batches οπου ενα task εχει μονο IGNORE -> nan loss
            if has_valid_label:
                rows.append(row)

    if not rows:
        print("[!] Κανενα εγκυρο record.")
        return

    df = pd.DataFrame(rows).drop_duplicates(subset=["text"])
    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    df.to_csv(OUT, index=False, encoding="utf-8")
    print(f"[+] {len(df)} γραμμες -> {OUT}")


if __name__ == "__main__":
    main()