# training/train_mtl.py
# Δεδομενα: training/train.csv (απο το data/merge_dataset.py), αλλιως τα χειρογραφα training/dataset.csv.
# Στηλες: text, sentiment, emotion, irony, distortion, distortion_types, risk (indices του labels.py)
# και προαιρετικα split (train/val) και group.
# multi-label στηλες (emotion, distortion_types): indices με "|", π.χ. "1|4". Κενο = χωρις annotation,
# "none" = ρητα κανενα label. distortion_types κενο με distortion=0 -> κανενας τυπος.
# <task>_covered (προαιρετικα): τα labels που καλυπτει η πηγη, τα υπολοιπα αγνοουνται στο loss. Κενο = ολα.
# Στηλη που λειπει -> αγνοειται στο loss
#
# Εκτελεση (απο τον φακελο Python/):
#   .venv/bin/python training/train_mtl.py
#   .venv/bin/python training/train_mtl.py --epochs 3 --batch-size 16 --data training/train.csv
import argparse
import json
import math
import os
import sys
import time
from datetime import datetime

import pandas as pd
import torch
from sklearn.metrics import average_precision_score, f1_score
from sklearn.model_selection import GroupShuffleSplit, train_test_split
from torch.utils.data import Dataset, DataLoader, Sampler, default_collate
from transformers import AutoTokenizer, get_linear_schedule_with_warmup

# Επιτρεπουμε import απο τον γονικο φακελο (model_architecture, labels)
sys.path.append(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from model_architecture import WellnessMTLModel, MODEL_NAME, MODEL_REVISION, pick_device
from labels import TASKS, LABEL_SETS, HEAD_DIMS, MULTI_LABEL_TASKS, MULTI_LABEL_THRESHOLD

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(HERE)
MERGED_PATH = os.path.join(HERE, "train.csv")
MANUAL_PATH = os.path.join(HERE, "dataset.csv")
WEIGHTS_OUT = os.path.join(ROOT, "mtl_model.pt")
CONFIG_OUT = os.path.join(ROOT, "mtl_config.json")
RUNS_DIR = os.path.join(ROOT, "runs")

MAX_LEN = 128
BATCH_SIZE = 16
EPOCHS = 10
LR = 2e-5
HEAD_LR = 1e-4
WARMUP_RATIO = 0.1
GRAD_CLIP = 1.0
MEGA_BATCHES = 50
PAD_MULTIPLE = 16
VAL_RATIO = 0.2
SEED = 42
IGNORE = -100  # δειγματα χωρις annotation σε ενα task -> δεν μετρανε στο loss
# λιγοτερα δειγματα με annotation -> το task δεν δηλωνεται trained, οποτε το PHP δεν δειχνει τα αποτελεσματα του
MIN_TASK_EXAMPLES = 50
THRESHOLD_GRID = [round(0.05 * i, 2) for i in range(1, 20)]
MIN_VAL_POSITIVES = 5
TUNED_BINARY_TASKS = ("irony", "distortion")
WEIGHTED_TASKS = ("sentiment", "risk")


def _blank(value) -> bool:
    return value is None or (isinstance(value, float) and pd.isna(value)) or str(value).strip() == ""


def parse_single(value) -> int:
    return IGNORE if _blank(value) else int(float(value))


def parse_multi(value, size: int) -> list[float] | None:
    if _blank(value):
        return None
    target = [0.0] * size
    if str(value).strip() == "none":  # ρητα κανενα label -> ολα 0, αλλα μετραει στο loss
        return target
    for part in str(value).split("|"):
        target[int(float(part))] = 1.0
    return target


def parse_covered(value, target: list[float]) -> list[float]:
    if _blank(value):
        return [1.0] * len(target)
    covered = parse_multi(value, len(target))
    # ενα θετικο label μετραει παντα, ακομα κι αν η πηγη ξεχασε να το δηλωσει
    return [max(c, y) for c, y in zip(covered, target)]


class JournalDataset(Dataset):
    def __init__(self, df: pd.DataFrame, tokenizer):
        self.texts = df["text"].astype(str).tolist()
        self.input_ids = tokenizer(self.texts, truncation=True, max_length=MAX_LEN)["input_ids"] if self.texts else []
        self.pad_id = tokenizer.pad_token_id
        self.labels = []
        self.covered = []  # ανα γραμμη: {task: μασκα ανα label}, None χωρις annotation
        for _, row in df.iterrows():
            labels, covered = {}, {}
            for t in TASKS:
                value = row.get(t)
                if t in MULTI_LABEL_TASKS:
                    target = parse_multi(value, HEAD_DIMS[t])
                    if target is None and t == "distortion_types" and parse_single(row.get("distortion")) == 0:
                        target = [0.0] * HEAD_DIMS[t]
                    labels[t] = target
                    covered[t] = parse_covered(row.get(f"{t}_covered"), target) if target is not None else None
                else:
                    labels[t] = parse_single(value)
            self.labels.append(labels)
            self.covered.append(covered)

    def __len__(self):
        return len(self.texts)

    def __getitem__(self, idx):
        item = {"input_ids": torch.tensor(self.input_ids[idx], dtype=torch.long)}
        for t in TASKS:
            value = self.labels[idx][t]
            if t in MULTI_LABEL_TASKS:
                zeros = [0.0] * HEAD_DIMS[t]
                item[t] = torch.tensor(value if value is not None else zeros)
                item[f"{t}_mask"] = torch.tensor(self.covered[idx][t] or zeros)
            else:
                item[t] = torch.tensor(value, dtype=torch.long)
        return item

    def collate(self, items: list[dict]) -> dict:
        longest = max(len(item["input_ids"]) for item in items)
        width = min(MAX_LEN, math.ceil(longest / PAD_MULTIPLE) * PAD_MULTIPLE)
        input_ids = torch.full((len(items), width), self.pad_id, dtype=torch.long)
        attention_mask = torch.zeros((len(items), width), dtype=torch.long)
        for row, item in enumerate(items):
            n = len(item["input_ids"])
            input_ids[row, :n] = item["input_ids"]
            attention_mask[row, :n] = 1
        batch = default_collate([{k: v for k, v in item.items() if k != "input_ids"} for item in items])
        batch["input_ids"] = input_ids
        batch["attention_mask"] = attention_mask
        return batch


class LengthGroupedBatchSampler(Sampler):
    def __init__(self, lengths: list[int], batch_size: int, shuffle: bool, seed: int = SEED):
        self.lengths = lengths
        self.batch_size = batch_size
        self.shuffle = shuffle
        self.seed = seed
        self.epoch = 0

    def __len__(self):
        return math.ceil(len(self.lengths) / self.batch_size)

    def _cut(self, order: list[int]) -> list[list[int]]:
        return [order[i:i + self.batch_size] for i in range(0, len(order), self.batch_size)]

    def __iter__(self):
        if not self.shuffle:
            yield from self._cut(sorted(range(len(self.lengths)), key=lambda i: self.lengths[i]))
            return
        g = torch.Generator()
        g.manual_seed(self.seed + self.epoch)
        self.epoch += 1
        order = torch.randperm(len(self.lengths), generator=g).tolist()
        size = self.batch_size * MEGA_BATCHES
        batches = []
        for start in range(0, len(order), size):
            chunk = sorted(order[start:start + size], key=lambda i: self.lengths[i], reverse=True)
            batches += self._cut(chunk)
        for i in torch.randperm(len(batches), generator=g).tolist():
            yield batches[i]


def make_loader(ds: JournalDataset, batch_size: int, shuffle: bool, seed: int = SEED) -> DataLoader:
    lengths = [len(ids) for ids in ds.input_ids]
    sampler = LengthGroupedBatchSampler(lengths, batch_size, shuffle, seed)
    return DataLoader(ds, batch_sampler=sampler, collate_fn=ds.collate)


def task_mask(batch, t, device):
    # γραμμες με annotation στο task
    if t in MULTI_LABEL_TASKS:
        return batch[f"{t}_mask"].to(device).any(dim=-1)
    return batch[t].to(device) != IGNORE


@torch.no_grad()
def evaluate(model, loader, device) -> tuple[dict, dict, dict]:
    """Macro-F1 για τα single-label, micro-F1 για τα multi-label (αγνοωντας οσα δεν εχουν annotation)."""
    model.eval()
    correct = {t: 0 for t in TASKS}
    total = {t: 0 for t in TASKS}
    tp = {t: 0 for t in MULTI_LABEL_TASKS}
    fp = {t: 0 for t in MULTI_LABEL_TASKS}
    fn = {t: 0 for t in MULTI_LABEL_TASKS}
    y_true = {t: [] for t in TASKS if t not in MULTI_LABEL_TASKS}
    y_pred = {t: [] for t in TASKS if t not in MULTI_LABEL_TASKS}
    outputs = {t: {"probs": [], "y": [], "covered": []} for t in MULTI_LABEL_TASKS}
    outputs |= {t: {"probs": [], "y": []} for t in TUNED_BINARY_TASKS}
    for batch in loader:
        logits = model(batch["input_ids"].to(device), batch["attention_mask"].to(device))
        for t in TASKS:
            mask = task_mask(batch, t, device)
            if mask.sum() == 0:
                continue
            y = batch[t].to(device)[mask]
            total[t] += int(mask.sum())
            if t in MULTI_LABEL_TASKS:
                covered = batch[f"{t}_mask"].to(device)[mask]  # labels που δεν καλυπτει η πηγη δεν μετρανε
                probs = torch.sigmoid(logits[t][mask])
                pred = (probs >= MULTI_LABEL_THRESHOLD).float()
                tp[t] += int((pred * y * covered).sum())
                fp[t] += int((pred * (1 - y) * covered).sum())
                fn[t] += int(((1 - pred) * y * covered).sum())
                outputs[t]["probs"].append(probs.cpu())
                outputs[t]["y"].append(y.cpu())
                outputs[t]["covered"].append(covered.cpu())
            else:
                pred = logits[t][mask].argmax(dim=-1)
                correct[t] += int((pred == y).sum())
                y_true[t] += y.cpu().tolist()
                y_pred[t] += pred.cpu().tolist()
                if t in TUNED_BINARY_TASKS:
                    outputs[t]["probs"].append(torch.softmax(logits[t][mask], dim=-1)[:, 1].cpu())
                    outputs[t]["y"].append(y.cpu())

    scores, accuracy = {}, {}
    for t in TASKS:
        if not total[t]:
            continue  # task χωρις annotation στο validation -> δεν μπαινει στον μεσο ορο
        if t in MULTI_LABEL_TASKS:
            denom = 2 * tp[t] + fp[t] + fn[t]
            # χωρις κανενα θετικο label και καμια προβλεψη το F1 ειναι τελειο
            scores[t] = 2 * tp[t] / denom if denom else 1.0
        else:
            scores[t] = f1_score(y_true[t], y_pred[t], average="macro", zero_division=0)
            accuracy[t] = correct[t] / total[t]
    outputs = {t: {k: torch.cat(v) for k, v in o.items()} for t, o in outputs.items() if o["probs"]}
    return scores, accuracy, outputs


def micro_f1(probs, y, covered, thresholds) -> float:
    pred = (probs >= thresholds).float()
    tp = (pred * y * covered).sum()
    denom = 2 * tp + (pred * (1 - y) * covered).sum() + ((1 - pred) * y * covered).sum()
    return float(2 * tp / denom) if denom else 1.0


def tune_thresholds(t: str, output: dict, labels: list[str]) -> dict:
    thresholds = {}
    for i, label in enumerate(LABEL_SETS[t]):
        if label not in labels:
            continue
        rows = output["covered"][:, i] > 0
        y = output["y"][rows, i]
        probs = output["probs"][rows, i]
        if y.sum() < MIN_VAL_POSITIVES:
            thresholds[label] = MULTI_LABEL_THRESHOLD
            continue
        best_th, best_f1 = MULTI_LABEL_THRESHOLD, -1.0
        for th in THRESHOLD_GRID:
            pred = (probs >= th).float()
            tp = float((pred * y).sum())
            denom = 2 * tp + float((pred * (1 - y)).sum()) + float(((1 - pred) * y).sum())
            f1 = 2 * tp / denom if denom else 0.0
            if f1 > best_f1:
                best_th, best_f1 = th, f1
        thresholds[label] = best_th
    return thresholds


def tune_binary_threshold(output: dict) -> tuple[float, float, float]:
    y = output["y"].tolist()
    probs = output["probs"]
    default = f1_score(y, (probs > 0.5).long().tolist(), average="macro", zero_division=0)
    if sum(y) < MIN_VAL_POSITIVES or len(y) - sum(y) < MIN_VAL_POSITIVES:
        return 0.5, default, default
    best_th, best_f1 = 0.5, default
    for th in THRESHOLD_GRID:
        f1 = f1_score(y, (probs > th).long().tolist(), average="macro", zero_division=0)
        if f1 > best_f1:
            best_th, best_f1 = th, f1
    return best_th, default, best_f1


def multi_label_scores(t: str, output: dict, labels: list[str]) -> tuple[float, float, float]:
    thresholds = tune_thresholds(t, output, labels)
    keep = torch.tensor([1.0 if l in labels else 0.0 for l in LABEL_SETS[t]])
    covered = output["covered"] * keep
    per_label = torch.tensor([thresholds.get(l, MULTI_LABEL_THRESHOLD) for l in LABEL_SETS[t]])
    tuned = micro_f1(output["probs"], output["y"], covered, per_label)
    default = micro_f1(output["probs"], output["y"], covered, MULTI_LABEL_THRESHOLD)
    aps = []
    for i, label in enumerate(LABEL_SETS[t]):
        rows = covered[:, i] > 0
        y = output["y"][rows, i]
        if label in labels and MIN_VAL_POSITIVES <= int(y.sum()) < len(y):
            aps.append(average_precision_score(y.numpy(), output["probs"][rows, i].numpy()))
    return tuned, default, sum(aps) / len(aps) if aps else 0.0


def pos_weights(ds: JournalDataset, t: str, labels: list[str]) -> list[float]:
    rows = [(l[t], c[t]) for l, c in zip(ds.labels, ds.covered) if c[t] is not None]
    weights = []
    for i, label in enumerate(LABEL_SETS[t]):
        pos = sum(y[i] * c[i] for y, c in rows)
        neg = sum((1 - y[i]) * c[i] for y, c in rows)
        weights.append(round(math.sqrt(neg / pos), 4) if label in labels and pos and neg else 1.0)
    return weights


def class_weights(labels: list[int], n_classes: int) -> list[float] | None:
    counts = [sum(1 for y in labels if y == c) for c in range(n_classes)]
    total = sum(counts)
    if not total:
        return None
    return [round(total / (n_classes * n), 4) if n else 0.0 for n in counts]


def split(df: pd.DataFrame) -> tuple[pd.DataFrame, pd.DataFrame]:
    # 1) ετοιμο split απο το merge_dataset.py (ανα group, σταθερο αναμεσα σε τρεξιματα)
    if "split" in df.columns and df["split"].isin(["train", "val"]).all():
        print("[*] split: στηλη split του αρχειου")
        return df[df["split"] == "train"], df[df["split"] == "val"]
    if len(df) < 5:
        return df, df  # πολυ μικρο dataset, μονο για δοκιμη της ροης
    # 2) ανα group, ωστε δειγματα του ιδιου χρηστη / της ιδιας ερωτησης να μη μοιραζονται σε train και val
    if "group" in df.columns and (df["group"] != "").all():
        print("[*] split: ανα group")
        gss = GroupShuffleSplit(n_splits=1, test_size=VAL_RATIO, random_state=SEED)
        tr, va = next(gss.split(df, groups=df["group"]))
        return df.iloc[tr], df.iloc[va]
    # 3) χωρις groups: τυχαιο
    print("[*] split: τυχαιο (το αρχειο δεν εχει group)")
    return train_test_split(df, test_size=VAL_RATIO, random_state=SEED)


def save_run(run_dir: str, run: dict) -> None:
    with open(os.path.join(run_dir, "run.json"), "w", encoding="utf-8") as f:
        json.dump(run, f, ensure_ascii=False, indent=2)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--data", default=None, help=f"default: {MERGED_PATH} αν υπαρχει, αλλιως {MANUAL_PATH}")
    ap.add_argument("--epochs", type=int, default=EPOCHS)
    ap.add_argument("--batch-size", type=int, default=BATCH_SIZE)
    ap.add_argument("--head-lr", type=float, default=HEAD_LR, help="learning rate των κεφαλων (το BERT μενει στο LR)")
    ap.add_argument("--seed", type=int, default=SEED,
                    help="αρχικοποιηση των κεφαλων, dropout και σειρα των batches (ο χωρισμος train/val δεν αλλαζει)")
    ap.add_argument("--min-examples", type=int, default=MIN_TASK_EXAMPLES,
                    help="ελαχιστα δειγματα στο train για να θεωρηθει ενα task εκπαιδευμενο")
    ap.add_argument("--note", default="", help="περιγραφη του run, π.χ. τι αλλαξε απο το προηγουμενο")
    args = ap.parse_args()
    data_path = args.data or (MERGED_PATH if os.path.exists(MERGED_PATH) else MANUAL_PATH)
    started = time.time()

    torch.manual_seed(args.seed)
    device = pick_device()
    print(f"[*] Device: {device}")
    print(f"[*] Data: {data_path}")

    tokenizer = AutoTokenizer.from_pretrained(MODEL_NAME, revision=MODEL_REVISION)
    df = pd.read_csv(data_path, dtype=str, keep_default_na=False)

    train_df, val_df = split(df)
    print(f"[*] train={len(train_df)}  val={len(val_df)}")

    train_ds = JournalDataset(train_df, tokenizer)
    # tasks με annotation στο train set, τα υπολοιπα heads μενουν ανεκπαιδευτα
    annotated = {t: sum(
        (l[t] is not None) if t in MULTI_LABEL_TASKS else (l[t] != IGNORE) for l in train_ds.labels
    ) for t in TASKS}
    print("[*] δειγματα ανα task: " + ", ".join(f"{t}={n}" for t, n in annotated.items()))
    trained_tasks = [t for t in TASKS if annotated[t] >= args.min_examples]
    too_few = [t for t in TASKS if 0 < annotated[t] < args.min_examples]
    print(f"[*] trained tasks: {trained_tasks}")
    if too_few:
        print(f"[!] λιγοτερα απο {args.min_examples} δειγματα, δεν δηλωνονται trained: {too_few}")
    # labels που δεν καλυπτει καμια πηγη μενουν ανεκπαιδευτα (sigmoid γυρω στο 0.5) -> το main.py τα κρυβει
    trained_labels = {}
    for t in MULTI_LABEL_TASKS & set(trained_tasks):
        counts = [sum(c[t][i] for c in train_ds.covered if c[t] is not None) for i in range(HEAD_DIMS[t])]
        trained_labels[t] = [l for l, n in zip(LABEL_SETS[t], counts) if n >= args.min_examples]
        missing = [l for l in LABEL_SETS[t] if l not in trained_labels[t]]
        if missing:
            print(f"[!] {t}: λιγοτερες απο {args.min_examples} γραμμες καλυπτουν {missing}, δεν δηλωνονται trained")

    weights = {}
    for t in WEIGHTED_TASKS:
        w = class_weights([l[t] for l in train_ds.labels], HEAD_DIMS[t])
        if w:
            weights[t] = w
            print(f"[*] βαρη {t}: " + ", ".join(f"{l}={x}" for l, x in zip(LABEL_SETS[t], w)))
    positive = {t: pos_weights(train_ds, t, trained_labels.get(t, [])) for t in MULTI_LABEL_TASKS}
    for t in trained_labels:
        print(f"[*] pos_weight {t}: " + ", ".join(
            f"{l}={x}" for l, x in zip(LABEL_SETS[t], positive[t]) if l in trained_labels[t]))

    run_dir = os.path.join(RUNS_DIR, datetime.now().strftime("%Y-%m-%d_%H%M%S"))
    os.makedirs(run_dir, exist_ok=True)
    sources = {}
    if "source" in df.columns:
        for name, part in (("train", train_df), ("val", val_df)):
            sources[name] = part["source"].value_counts().to_dict()
    run = {
        "started": datetime.now().isoformat(timespec="seconds"),
        "note": args.note,
        "model": {"name": MODEL_NAME, "revision": MODEL_REVISION},
        "device": str(device),
        "data": {
            "path": os.path.relpath(data_path, ROOT),
            "train": len(train_df),
            "val": len(val_df),
            "sources": sources,
            "annotated_train": annotated,
        },
        "params": {
            "epochs": args.epochs,
            "batch_size": args.batch_size,
            "lr": LR,
            "head_lr": args.head_lr,
            "schedule": f"linear warmup {WARMUP_RATIO:.0%}, μετα γραμμικη μειωση στο 0",
            "grad_clip": GRAD_CLIP,
            "batching": f"ομαδες ιδιου μηκους (x{MEGA_BATCHES} batches), padding ανα batch σε πολλαπλασιο του {PAD_MULTIPLE}"
                        + (", αδειασμα της μνημης MPS καθε βημα" if device.type == "mps" else ""),
            "max_len": MAX_LEN,
            "seed": args.seed,
            "min_examples": args.min_examples,
            "multi_label_threshold": MULTI_LABEL_THRESHOLD,
            "class_weights": {t: dict(zip(LABEL_SETS[t], w)) for t, w in weights.items()},
            "multi_label_loss": "BCE μεσος ορος στα καλυπτομενα labels, pos_weight = sqrt(αρνητικα/θετικα)",
            "pos_weight": {t: {l: x for l, x in zip(LABEL_SETS[t], positive[t]) if l in trained_labels[t]}
                           for t in trained_labels},
            "selection_metric": "macro-F1 (single-label), micro-F1 με ορια ανα label ρυθμισμενα στο validation "
                                "καθε epoch (multi-label, μονο trained labels)",
        },
        "trained_tasks": trained_tasks,
        "trained_labels": trained_labels,
        "epochs": [],
        "best": None,
    }
    save_run(run_dir, run)
    print(f"[*] Run -> {run_dir}")

    train_loader = make_loader(train_ds, args.batch_size, shuffle=True, seed=args.seed)
    val_loader = make_loader(JournalDataset(val_df, tokenizer), args.batch_size, shuffle=False)

    model = WellnessMTLModel().to(device)
    optimizer = torch.optim.AdamW([
        {"params": model.bert.parameters(), "lr": LR},
        {"params": model.heads.parameters(), "lr": args.head_lr},
    ])
    total_steps = args.epochs * len(train_loader)
    scheduler = get_linear_schedule_with_warmup(optimizer, int(WARMUP_RATIO * total_steps), total_steps)
    ce = torch.nn.CrossEntropyLoss(ignore_index=IGNORE)
    ce_weighted = {t: torch.nn.CrossEntropyLoss(weight=torch.tensor(w, device=device), ignore_index=IGNORE)
                   for t, w in weights.items()}
    bce = {t: torch.nn.BCEWithLogitsLoss(reduction="none", pos_weight=torch.tensor(positive[t], device=device))
           for t in MULTI_LABEL_TASKS}

    best_val = -1.0  # κραταμε το καλυτερο checkpoint (οχι το τελευταιο epoch)
    best_outputs = {}

    for epoch in range(1, args.epochs + 1):
        model.train()
        epoch_loss = 0.0
        task_loss_sum = {t: 0.0 for t in TASKS}
        task_batches = {t: 0 for t in TASKS}
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
                    covered = batch[f"{t}_mask"].to(device)
                    task_loss = (bce[t](logits[t], y) * covered).sum() / covered.sum()
                elif t in ce_weighted:
                    task_loss = ce_weighted[t](logits[t], y)
                else:
                    task_loss = ce(logits[t], y)
                task_loss_sum[t] += task_loss.item()
                task_batches[t] += 1
                loss = task_loss if loss is None else loss + task_loss

            if loss is None:
                continue

            loss.backward()
            torch.nn.utils.clip_grad_norm_(model.parameters(), GRAD_CLIP)
            optimizer.step()
            scheduler.step()
            if device.type == "mps":
                torch.mps.empty_cache()
            epoch_loss += loss.item()

        # μονο τα trained tasks μετρανε στο validation και στην επιλογη checkpoint
        all_scores, accuracy, outputs = evaluate(model, val_loader, device)
        scores = {t: s for t, s in all_scores.items() if t in trained_tasks}
        f1_default, avg_precision = {}, {}
        for t in MULTI_LABEL_TASKS & set(scores) & set(trained_labels):
            scores[t], f1_default[t], avg_precision[t] = multi_label_scores(t, outputs[t], trained_labels[t])
        mean_score = sum(scores.values()) / len(scores) if scores else 0.0
        mean_loss = epoch_loss / len(train_loader)
        score_str = " | ".join(f"{t}:{s:.2f}" for t, s in scores.items())
        print(f"Epoch {epoch:02d}/{args.epochs} | loss={mean_loss:.4f} | val[{score_str}] | mean={mean_score:.3f}")
        for t in f1_default:
            print(f"    {t}: F1 με οριο 0.5={f1_default[t]:.2f} | average precision={avg_precision[t]:.2f}")

        # Best-checkpoint: αποθηκευουμε μονο οταν βελτιωνεται ο μεσος ορος του validation
        if mean_score > best_val:
            best_val = mean_score
            torch.save({k: v.cpu() for k, v in model.state_dict().items()}, WEIGHTS_OUT)
            print(f"    [*] new best (mean={mean_score:.3f}) -> saved {WEIGHTS_OUT}")
            run["best"] = {"epoch": epoch, "mean": round(mean_score, 4)}
            best_outputs = outputs

        run["epochs"].append({
            "epoch": epoch,
            "loss": round(mean_loss, 4),
            "val": {t: round(s, 4) for t, s in scores.items()},
            "val_f1_at_0.5": {t: round(s, 4) for t, s in f1_default.items()},
            "val_average_precision": {t: round(s, 4) for t, s in avg_precision.items()},
            "task_loss": {t: round(task_loss_sum[t] / task_batches[t], 4) for t in TASKS if task_batches[t]},
            "val_untrained": {t: round(s, 4) for t, s in all_scores.items() if t not in trained_tasks},
            "val_accuracy": {t: round(a, 4) for t, a in accuracy.items()},
            "mean": round(mean_score, 4),
            "minutes": round((time.time() - started) / 60, 1),
        })
        save_run(run_dir, run)

    thresholds, threshold_f1 = {}, {}
    for t in trained_labels:
        if t not in best_outputs:
            continue
        thresholds[t] = tune_thresholds(t, best_outputs[t], trained_labels[t])
        per_label = torch.tensor([thresholds[t].get(l, MULTI_LABEL_THRESHOLD) for l in LABEL_SETS[t]])
        output = best_outputs[t]
        default_f1 = micro_f1(output["probs"], output["y"], output["covered"], MULTI_LABEL_THRESHOLD)
        tuned_f1 = micro_f1(output["probs"], output["y"], output["covered"], per_label)
        threshold_f1[t] = {"default": round(default_f1, 4), "tuned": round(tuned_f1, 4)}
        print(f"[*] {t} ορια: " + ", ".join(f"{l}={th}" for l, th in thresholds[t].items()))
        print(f"[*] {t} micro-F1 (val, best epoch): οριο 0.5={default_f1:.3f} | ορια ανα label={tuned_f1:.3f}")
    binary_f1 = {}
    for t in TUNED_BINARY_TASKS:
        if t not in trained_tasks or t not in best_outputs:
            continue
        thresholds[t], default_f1, tuned_f1 = tune_binary_threshold(best_outputs[t])
        binary_f1[t] = {"default": round(default_f1, 4), "tuned": round(tuned_f1, 4)}
        print(f"[*] {t} οριο: {thresholds[t]} | macro-F1 (val, best epoch): οριο 0.5={default_f1:.3f} | ρυθμισμενο={tuned_f1:.3f}")
    run["thresholds"] = thresholds
    run["threshold_micro_f1"] = threshold_f1
    run["threshold_macro_f1"] = binary_f1

    with open(CONFIG_OUT, "w", encoding="utf-8") as f:
        json.dump({"model_name": MODEL_NAME, "max_len": MAX_LEN, "labels": LABEL_SETS, "trained_tasks": trained_tasks,
                   "trained_labels": trained_labels, "thresholds": thresholds}, f, ensure_ascii=False, indent=2)

    run["finished"] = datetime.now().isoformat(timespec="seconds")
    run["minutes"] = round((time.time() - started) / 60, 1)
    save_run(run_dir, run)

    print(f"[+] Best val mean: {best_val:.3f}")
    print(f"[+] Weights -> {WEIGHTS_OUT}")
    print(f"[+] Config  -> {CONFIG_OUT}")
    print(f"[+] Run     -> {run_dir}")


if __name__ == "__main__":
    main()
