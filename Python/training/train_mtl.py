# training/train_mtl.py
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
from model_architecture import WellnessMTLModel, MODEL_NAME
from labels import TASKS, LABEL_SETS                          

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


class JournalDataset(Dataset):
    def __init__(self, df: pd.DataFrame, tokenizer):
        self.texts = df["text"].astype(str).tolist()
        self.labels = {t: df[t].astype(int).tolist() for t in TASKS}
        self.tokenizer = tokenizer

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
            item[t] = torch.tensor(self.labels[t][idx], dtype=torch.long)
        return item


@torch.no_grad()
def evaluate(model, loader, device) -> dict:
    """Per-task accuracy στο validation set (αγνοωντας τα IGNORE labels)."""
    model.eval()
    correct = {t: 0 for t in TASKS}
    total = {t: 0 for t in TASKS}
    for batch in loader:
        logits = model(batch["input_ids"].to(device), batch["attention_mask"].to(device))
        for t in TASKS:
            y = batch[t].to(device)
            mask = y != IGNORE
            if mask.sum() == 0:
                continue
            pred = logits[t].argmax(dim=-1)
            correct[t] += (pred[mask] == y[mask]).sum().item()
            total[t] += mask.sum().item()
    return {t: (correct[t] / total[t] if total[t] else 0.0) for t in TASKS}


def main():
    torch.manual_seed(SEED)
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
    print(f"[*] Device: {device}")

    tokenizer = AutoTokenizer.from_pretrained(MODEL_NAME)
    df = pd.read_csv(DATA_PATH)

    # train/val split (με fallback αν το dataset ειναι πολυ μικρο)
    if len(df) >= 5:
        train_df, val_df = train_test_split(df, test_size=VAL_RATIO, random_state=SEED)
    else:
        train_df, val_df = df, df
    print(f"[*] train={len(train_df)}  val={len(val_df)}")

    train_loader = DataLoader(JournalDataset(train_df, tokenizer), batch_size=BATCH_SIZE, shuffle=True)
    val_loader = DataLoader(JournalDataset(val_df, tokenizer), batch_size=BATCH_SIZE)

    model = WellnessMTLModel().to(device)
    optimizer = torch.optim.AdamW(model.parameters(), lr=LR)
    criterion = torch.nn.CrossEntropyLoss(ignore_index=IGNORE)

    best_val_acc = -1.0  # κραταμε το καλυτερο checkpoint (οχι το τελευταιο epoch)

    for epoch in range(1, EPOCHS + 1):
        model.train()
        epoch_loss = 0.0
        for batch in train_loader:
            optimizer.zero_grad()
            logits = model(batch["input_ids"].to(device), batch["attention_mask"].to(device))

            # Συνολικο loss = αθροισμα των tasks (multi-task objective).
            # Αγνοουμε task οπου ολο το batch ειναι IGNORE -> αλλιως mean over 0 = nan
            loss = None
            for t in TASKS:
                y = batch[t].to(device)
                if (y != IGNORE).sum() == 0:
                    continue
                task_loss = criterion(logits[t], y)
                loss = task_loss if loss is None else loss + task_loss

            if loss is None:
                continue

            loss.backward()
            optimizer.step()
            epoch_loss += loss.item()

        acc = evaluate(model, val_loader, device)
        mean_acc = sum(acc.values()) / len(acc)
        acc_str = " | ".join(f"{t}:{acc[t]:.2f}" for t in TASKS)
        print(f"Epoch {epoch:02d}/{EPOCHS} | loss={epoch_loss / len(train_loader):.4f} | val_acc[{acc_str}] | mean={mean_acc:.3f}")

        # Best-checkpoint: αποθηκευουμε μονο οταν βελτιωνεται το mean val accuracy
        if mean_acc > best_val_acc:
            best_val_acc = mean_acc
            torch.save(model.state_dict(), WEIGHTS_OUT)
            print(f"    [*] new best (mean_acc={mean_acc:.3f}) -> saved {WEIGHTS_OUT}")

    with open(CONFIG_OUT, "w", encoding="utf-8") as f:
        json.dump({"model_name": MODEL_NAME, "max_len": MAX_LEN, "labels": LABEL_SETS}, f, ensure_ascii=False, indent=2)

    print(f"[+] Best val mean-acc: {best_val_acc:.3f}")
    print(f"[+] Weights -> {WEIGHTS_OUT}")
    print(f"[+] Config  -> {CONFIG_OUT}")


if __name__ == "__main__":
    main()