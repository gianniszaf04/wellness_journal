import torch
import torch.nn as nn
from transformers import AutoModel

from labels import HEAD_DIMS

MODEL_NAME = "nlpaueb/bert-base-greek-uncased-v1"
# κλειδωμενη εκδοση με model.safetensors (μετατροπη του Hugging Face, PR #1)
MODEL_REVISION = "b1092b2d17c5732186573ce8e76a01f45be927f0"
HEAD_HIDDEN = 256


def pick_device() -> torch.device:
    # NVIDIA GPU, αλλιως η GPU του Mac (Apple Silicon, MPS), αλλιως CPU
    if torch.cuda.is_available():
        return torch.device("cuda")
    if torch.backends.mps.is_available():
        return torch.device("mps")
    return torch.device("cpu")


class TaskHead(nn.Module):
    # μικρο MLP ανα task, ωστε καθε task να εχει δικο του χωρο πανω απο το κοινο BERT
    def __init__(self, hidden: int, out_dim: int, dropout: float):
        super().__init__()
        self.net = nn.Sequential(
            nn.Linear(hidden, HEAD_HIDDEN),
            nn.GELU(),
            nn.Dropout(dropout),
            nn.Linear(HEAD_HIDDEN, out_dim),
        )

    def forward(self, x):
        return self.net(x)


class WellnessMTLModel(nn.Module):
    def  __init__(self, model_name: str = MODEL_NAME, dropout: float = 0.3):

        super().__init__()
        self.bert = AutoModel.from_pretrained(model_name, revision=MODEL_REVISION, use_safetensors=True)

        hidden = self.bert.config.hidden_size

        self.dropout = nn.Dropout(dropout)
        self.heads = nn.ModuleDict({task: TaskHead(hidden, dim, dropout) for task, dim in HEAD_DIMS.items()})

    def forward(self, input_ids, attention_mask):
        outputs = self.bert(input_ids=input_ids, attention_mask=attention_mask)

        # mean pooling: το pooler_output ειναι εκπαιδευμενο για NSP και αποδιδει χειροτερα
        mask = attention_mask.unsqueeze(-1).float()
        pooled_output = (outputs.last_hidden_state * mask).sum(1) / mask.sum(1).clamp(min=1e-9)

        pooled_output = self.dropout(pooled_output)

        return {task: head(pooled_output) for task, head in self.heads.items()}
