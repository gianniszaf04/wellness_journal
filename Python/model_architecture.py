import torch 
import torch.nn as nn 
from transformers import AutoModel

from labels import HEAD_DIMS

MODEL_NAME = "nlpaueb/bert-base-greek-uncased-v1"
# κλειδωμενη εκδοση με model.safetensors (μετατροπη του Hugging Face, PR #1)
MODEL_REVISION = "b1092b2d17c5732186573ce8e76a01f45be927f0"


class WellnessMTLModel(nn.Module): 
    def  __init__(self, model_name: str = MODEL_NAME, dropout: float = 0.3): 

        super().__init__()
        self.bert = AutoModel.from_pretrained(model_name, revision=MODEL_REVISION, use_safetensors=True)

        hidden = self.bert.config.hidden_size

        self.dropout = nn.Dropout(dropout)
        
        self.sentiment_head = nn.Linear(hidden, HEAD_DIMS["sentiment"])
        self.emotion_head = nn.Linear(hidden, HEAD_DIMS["emotion"])
        self.irony_head = nn.Linear(hidden, HEAD_DIMS["irony"])
        self.distortion_head = nn.Linear(hidden, HEAD_DIMS["distortion"])

    def forward(self, input_ids, attention_mask):
        outputs = self.bert(input_ids=input_ids, attention_mask=attention_mask)

        pooled_output = getattr(outputs, "pooler_output", None)
        if pooled_output is None:
            last_hidden = outputs.last_hidden_state
            mask = attention_mask.unsqueeze(-1).float()
            pooled_output = (last_hidden * mask).sum(1) / mask.sum(1).clamp(min=1e-9)

        pooled_output = self.dropout(pooled_output)

        return {
            "sentiment": self.sentiment_head(pooled_output),
            "emotion": self.emotion_head(pooled_output),
            "irony": self.irony_head(pooled_output),
            "distortion": self.distortion_head(pooled_output),
        }
