import json
import os
import threading
from contextlib import asynccontextmanager
from datetime import datetime, timezone

import torch
import torch.nn.functional as F
from dotenv import load_dotenv
from fastapi import FastAPI, BackgroundTasks
from pydantic import BaseModel, Field
from transformers import AutoTokenizer


from model_architecture import WellnessMTLModel, MODEL_NAME
from ner_service import extract_entities, load_ner
from labels import (
    SENTIMENT_LABELS,
    SENTIMENT_WEIGHTS,
    EMOTION_LABELS,
    IRONY_LABELS,
    DISTORTION_LABELS,
    LABEL_SETS,
)

load_dotenv()

WEIGHTS_PATH = os.getenv("WEIGHTS_PATH", "mtl_model.pt")
OPENAI_MODEL = os.getenv("OPENAI_MODEL", "gpt-4o-mini")
VERIFY_LOG = os.getenv("VERIFY_LOG", os.path.join("..", "storage", "ai_verification.jsonl"))
NER_ENABLED = os.getenv("NER_ENABLED", "1") != "0"
# Επαληθευση μεσω OpenAI μονο οταν ενεργοποιηθει ρητα (default: κλειστη)
OPENAI_VERIFY = os.getenv("OPENAI_VERIFY", "0") == "1"
MAX_LEN = 128


device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
ml: dict = {}

# Σειριοποιει τα concurrent appends στο verify log (BackgroundTasks -> ιδιο process)
_verify_log_lock = threading.Lock()



@asynccontextmanager
async def lifespan(app: FastAPI):
    ml["tokenizer"] = AutoTokenizer.from_pretrained(MODEL_NAME)
    model = WellnessMTLModel().to(device)
    if os.path.exists(WEIGHTS_PATH):
        model.load_state_dict(torch.load(WEIGHTS_PATH, map_location=device, weights_only=True))
        print(f"[+] Loaded trained weights: {WEIGHTS_PATH}")
    else:
        print(f"[!] WARNING: '{WEIGHTS_PATH}' not found - running on un-trained heads.")
    model.eval()
    ml["model"] = model

    if NER_ENABLED:
        load_ner()
    yield
    ml.clear()

# Χωρις CORS: το service το καλει μονο το PHP (server -> server), οχι ο browser
app = FastAPI(title="Wellness MTL Service", version="1.1" , lifespan=lifespan)

class AnalyzeRequest(BaseModel):
    text: str = Field(..., min_length=1, max_length=10000)

def _round_score(x:float) -> float:
    return max(-1.0, min(1.0, round(x, 4)))


@torch.no_grad()
def run_mtl(text: str) -> dict:
    enc = ml["tokenizer"](
        text, truncation=True, padding="max_length",
        max_length=MAX_LEN, return_tensors="pt",
    ).to(device)

    logits = ml["model"](enc["input_ids"], enc["attention_mask"])

    sent_p = F.softmax(logits["sentiment"], dim=-1)[0]
    emo_p = F.softmax(logits["emotion"], dim=-1)[0]
    iro_p = F.softmax(logits["irony"], dim=-1)[0]
    dist_p = F.softmax(logits["distortion"], dim=-1)[0]

    # Συνεχες sentiment score [-1,1] = σταθμισμενο αθροισμα της κατανομης
    sentiment_score = _round_score(sum(p.item() * w for p, w in zip(sent_p, SENTIMENT_WEIGHTS)))
    sent_idx = int(torch.argmax(sent_p))
    emo_idx = int(torch.argmax(emo_p))

    return {
        "sentiment_score": sentiment_score,
        "dominant_emotion": EMOTION_LABELS[emo_idx],

"tasks": {
            "sentiment": {
                "label": SENTIMENT_LABELS[sent_idx],
                "score": sentiment_score,
                "probabilities": {l: round(p.item(), 4) for l, p in zip(SENTIMENT_LABELS, sent_p)},
            },
            "emotion": {
                "label": EMOTION_LABELS[emo_idx],
                "probabilities": {l: round(p.item(), 4) for l, p in zip(EMOTION_LABELS, emo_p)},
            },
            "irony": {
                "label": IRONY_LABELS[int(torch.argmax(iro_p))],
                "probability": round(iro_p[1].item(), 4),  # P(Ναι)
            },
            "distortion": {  # CBT cognitive distortion
                "label": DISTORTION_LABELS[int(torch.argmax(dist_p))],
                "detected": bool(int(torch.argmax(dist_p)) == 1),
                "probability": round(dist_p[1].item(), 4),  # P(Ναι)
            },
        },
    }

def verify_with_openai(text: str, local_result: dict) -> None:
    if not os.getenv("OPENAI_API_KEY"):
        print("[verify] skipped - no OPENAI_API_KEY")
        return
    try:
        from openai import OpenAI

        client = OpenAI()
        system = (
            "Είσαι ψυχολογικός αναλυτής κειμένου. Ταξινόμησε το ελληνικό κείμενο και "
            "επίστρεψε ΜΟΝΟ JSON με κλειδιά: "
            f"sentiment (ένα από {SENTIMENT_LABELS}), emotion (ένα από {EMOTION_LABELS}), "
            f"irony (ένα από {IRONY_LABELS}), distortion (ένα από {DISTORTION_LABELS})."
        )
        resp = client.chat.completions.create(
            model=OPENAI_MODEL,
            messages=[{"role": "system", "content": system}, {"role": "user", "content": text}],
            response_format={"type": "json_object"},
            temperature=0,
        )
        truth = json.loads(resp.choices[0].message.content)
        truth = {k: (truth.get(k) if truth.get(k) in LABEL_SETS[k] else None) for k in LABEL_SETS}

        local = local_result["tasks"]
        agreement = {k: (local[k]["label"] == truth[k]) for k in LABEL_SETS}
        record = {
            "ts": datetime.now(timezone.utc).isoformat(),
            "text": text[:280],
            "local": {k: local[k]["label"] for k in local},
            "ground_truth": truth,
            "agreement": agreement,
            "agreement_rate": round(sum(agreement.values()) / len(agreement), 3),
        }

        with _verify_log_lock:
            os.makedirs(os.path.dirname(VERIFY_LOG) or ".", exist_ok=True)
            with open(VERIFY_LOG, "a", encoding="utf-8") as f:
                f.write(json.dumps(record, ensure_ascii=False) + "\n")
        print(f"[verify] OpenAI ground-truth OK | agreement={record['agreement_rate']}")

    except Exception as e:
        print(f"[verify] OpenAI verification failed: {e}")


@app.get("/health")
def health():
    return {
        "status": "ok",
        "weights_loaded": os.path.exists(WEIGHTS_PATH),
        "ner_enabled": NER_ENABLED,
        "openai_verify": OPENAI_VERIFY,
        "device": str(device),
    }


@app.post("/analyze")
def analyze(req: AnalyzeRequest, background_tasks: BackgroundTasks):
    result = run_mtl(req.text)

    entities = extract_entities(req.text) if NER_ENABLED else []

    if OPENAI_VERIFY:
        background_tasks.add_task(verify_with_openai, req.text, result)
    return {
        "success": True,
        "sentiment_score": result["sentiment_score"],
        "dominant_emotion": result["dominant_emotion"],
        "entities": entities,         
        "analysis": result["tasks"],  
        "model_version": "mtl-greekbert-v1",
    }

# Εκκινηση: python main.py -> ακουει μονο τοπικα (127.0.0.1), ποτε σε ολο το δικτυο
if __name__ == "__main__":
    import uvicorn

    uvicorn.run(app, host="127.0.0.1", port=8000)
