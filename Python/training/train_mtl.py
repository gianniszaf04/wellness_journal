# training/train_mtl.py
# dataset.csv: text, sentiment, emotion, irony, distortion, distortion_types, risk (indices του labels.py)
# multi-label στηλες (emotion, distortion_types): indices με "|", π.χ. "1|4". Κενο = χωρις annotation
# distortion_types κενο με distortion=0 -> κανενας τυπος. Στηλη που λειπει -> αγνοειται στο loss
import json
import os
import sys

import pandas as pd
import torch
from sklearn.model_selection import train_test_split
from torch.utils.data import Dataset, DataLoader
from transformers import AutoTokenizer

# Επιτρεπουμε import απο τον γονικο φακελο (model_architecture, labels)
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from model_architecture import WellnessMTLModel, MODEL_NAME, MODEL_REVISION
from labels import TASKS, LABEL_SETS, HEAD_DIMS, MULTI_LABEL_TASKS, MULTI_LABEL_THRESHOLD

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
DATA_PATH = os.path.join(HERE, "dataset.csv")
WEIGHTS_OUT = os.path.join(ROOT, "mtl_model.pt")
CONFIG_OUT = os.path.join(ROOT, "mtl_config.json")

MAX_LEN = 128
BATCH_SIZE = 4
EPOCHS = 10
LR = 2e-5
VAL_RATIO = 0.2
SEED = 42
IGNORE = -100  # δειγματα χωρις annotation σε ενα task -> δεν μετρανε στο loss


def _blank(value) -> bool:
    return value is None or (isinstance(value, float) and pd.isna(value)) or str(value).strip() == ""


def parse_single(value) -> int:
    return IGNORE if _blank(value) else int(float(value))


def parse_multi(value, size: int) -> list[float] | None:
    if _blank(value):
        return None
    target = [0.0] * size
    for part in str(value).split("|"):
        target[int(float(part))] = 1.0
    return target


class JournalDataset(Dataset):
    def __init__(self, df: pd.DataFrame, tokenizer):
        self.texts = df["text"].astype(str).tolist()
        self.tokenizer = tokenizer
        self.labels = []
        for _, row in df.iterrows():
            labels = {}
            for t in TASKS:
                value = row.get(t)
                if t in MULTI_LABEL_TASKS:
                    target = parse_multi(value, HEAD_DIMS[t])
                    if target is None and t == "distortion_types" and parse_single(row.get("distortion")) == 0:
                        target = [0.0] * HEAD_DIMS[t]
                    labels[t] = target
                else:
                    labels[t] = parse_single(value)
            self.labels.append(labels)

    def __len__(self):
        return len(self.texts)

    def __getitem__(self, idx):
        enc = self.tokenizer(
            self.texts[idx],
            truncation=True, padding="max_length",
            max_length=MAX_LEN, return_tensors="pt",
        )
        item = {
            "input_ids": enc["input_ids"].squeeze(0),
            "attention_mask": enc["attention_mask"].squeeze(0),
        }
        for t in TASKS:
            value = self.labels[idx][t]
            if t in MULTI_LABEL_TASKS:
                item[t] = torch.tensor(value if value is not None else [0.0] * HEAD_DIMS[t])
                item[f"{t}_mask"] = torch.tensor(value is not None)
            else:
                item[t] = torch.tensor(value, dtype=torch.long)
        return item


def task_mask(batch, t, device):
    if t in MULTI_LABEL_TASKS:
        return batch[f"{t}_mask"].to(device)
    return batch[t].to(device) != IGNORE


@torch.no_grad()
def evaluate(model, loader, device) -> dict:
    """Accuracy για τα single-label, micro-F1 για τα multi-label (αγνοωντας οσα δεν εχουν annotation)."""
    model.eval()
    correct = {t: 0 for t in TASKS}
    total = {t: 0 for t in TASKS}
    tp = {t: 0 for t in MULTI_LABEL_TASKS}
    fp = {t: 0 for t in MULTI_LABEL_TASKS}
    fn = {t: 0 for t in MULTI_LABEL_TASKS}
    for batch in loader:
        logits = model(batch["input_ids"].to(device), batch["attention_mask"].to(device))
        for t in TASKS:
            mask = task_mask(batch, t, device)
            if mask.sum() == 0:
                continue
            y = batch[t].to(device)[mask]
            total[t] += int(mask.sum())
            if t in MULTI_LABEL_TASKS:
                pred = (torch.sigmoid(logits[t][mask]) >= MULTI_LABEL_THRESHOLD).float()
                tp[t] += int((pred * y).sum())
                fp[t] += int((pred * (1 - y)).sum())
                fn[t] += int(((1 - pred) * y).sum())
            else:
                correct[t] += int((logits[t][mask].argmax(dim=-1) == y).sum())

    scores = {}
    for t in TASKS:
        if not total[t]:
            continue  # task χωρις annotation στο validation -> δεν μπαινει στον μεσο ορο
        if t in MULTI_LABEL_TASKS:
            denom = 2 * tp[t] + fp[t] + fn[t]
            # χωρις κανενα θετικο label και καμια προβλεψη το F1 ειναι τελειο
            scores[t] = 2 * tp[t] / denom if denom else 1.0
        else:
            scores[t] = correct[t] / total[t]
    return scores


def main():
    torch.manual_seed(SEED)
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
    print(f"[*] Device: {device}")

    tokenizer = AutoTokenizer.from_pretrained(MODEL_NAME, revision=MODEL_REVISION)
    df = pd.read_csv(DATA_PATH, dtype=str)

    # train/val split (με fallback αν το dataset ειναι πολυ μικρο)
    if len(df) >= 5:
        train_df, val_df = train_test_split(df, test_size=VAL_RATIO, random_state=SEED)
    else:
        train_df, val_df = df, df
    print(f"[*] train={len(train_df)}  val={len(val_df)}")

    train_ds = JournalDataset(train_df, tokenizer)
    # tasks με annotation στο train set, τα υπολοιπα heads μενουν ανεκπαιδευτα
    trained_tasks = [t for t in TASKS if any(
        (l[t] is not None) if t in MULTI_LABEL_TASKS else (l[t] != IGNORE) for l in train_ds.labels
    )]
    print(f"[*] trained tasks: {trained_tasks}")

    train_loader = DataLoader(train_ds, batch_size=BATCH_SIZE, shuffle=True)
    val_loader = DataLoader(JournalDataset(val_df, tokenizer), batch_size=BATCH_SIZE)

    model = WellnessMTLModel().to(device)
    optimizer = torch.optim.AdamW(model.parameters(), lr=LR)
    ce = torch.nn.CrossEntropyLoss(ignore_index=IGNORE)
    bce = torch.nn.BCEWithLogitsLoss()

    best_val = -1.0  # κραταμε το καλυτερο checkpoint (οχι το τελευταιο epoch)

    for epoch in range(1, EPOCHS + 1):
        model.train()
        epoch_loss = 0.0
        for batch in train_loader:
            optimizer.zero_grad()
            logits = model(batch["input_ids"].to(device), batch["attention_mask"].to(device))

            # Συνολικο loss = αθροισμα των tasks (multi-task objective).
            # Αγνοουμε task οπου ολο το batch δεν εχει annotation -> αλλιως mean over 0 = nan
            loss = None
            for t in TASKS:
                mask = task_mask(batch, t, device)
                if mask.sum() == 0:
                    continue
                y = batch[t].to(device)
                if t in MULTI_LABEL_TASKS:
                    task_loss = bce(logits[t][mask], y[mask])
                else:
                    task_loss = ce(logits[t], y)
                loss = task_loss if loss is None else loss + task_loss

            if loss is None:
                continue

            loss.backward()
            optimizer.step()
            epoch_loss += loss.item()

        scores = evaluate(model, val_loader, device)
        mean_score = sum(scores.values()) / len(scores) if scores else 0.0
        score_str = " | ".join(f"{t}:{s:.2f}" for t, s in scores.items())
        print(f"Epoch {epoch:02d}/{EPOCHS} | loss={epoch_loss / len(train_loader):.4f} | val[{score_str}] | mean={mean_score:.3f}")

        # Best-checkpoint: αποθηκευουμε μονο οταν βελτιωνεται ο μεσος ορος του validation
        if mean_score > best_val:
            best_val = mean_score
            torch.save(model.state_dict(), WEIGHTS_OUT)
            print(f"    [*] new best (mean={mean_score:.3f}) -> saved {WEIGHTS_OUT}")

    with open(CONFIG_OUT, "w", encoding="utf-8") as f:
        json.dump({"model_name": MODEL_NAME, "max_len": MAX_LEN, "labels": LABEL_SETS, "trained_tasks": trained_tasks}, f, ensure_ascii=False, indent=2)

    print(f"[+] Best val mean: {best_val:.3f}")
    print(f"[+] Weights -> {WEIGHTS_OUT}")
    print(f"[+] Config  -> {CONFIG_OUT}")


if __name__ == "__main__":
    main()
