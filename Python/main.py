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


from model_architecture import WellnessMTLModel, MODEL_NAME, MODEL_REVISION, pick_device
from ner_service import extract_entities, load_ner
from labels import (
    SENTIMENT_LABELS,
    SENTIMENT_WEIGHTS,
    EMOTION_LABELS,
    IRONY_LABELS,
    DISTORTION_LABELS,
    DISTORTION_TYPE_LABELS,
    RISK_LABELS,
    LABEL_SETS,
    MULTI_LABEL_TASKS,
    MULTI_LABEL_THRESHOLD,
)

load_dotenv()

WEIGHTS_PATH = os.getenv("WEIGHTS_PATH", "mtl_model.pt")
CONFIG_PATH = os.getenv("CONFIG_PATH", os.path.join(os.path.dirname(WEIGHTS_PATH), "mtl_config.json"))
OPENAI_MODEL = os.getenv("OPENAI_MODEL", "gpt-4o-mini")
VERIFY_LOG = os.getenv("VERIFY_LOG", os.path.join("..", "storage", "ai_verification.jsonl"))
NER_ENABLED = os.getenv("NER_ENABLED", "1") != "0"
# Επαληθευση μεσω OpenAI μονο οταν ενεργοποιηθει ρητα (default: κλειστη)
OPENAI_VERIFY = os.getenv("OPENAI_VERIFY", "0") == "1"
MAX_LEN = 128


device = pick_device()
ml: dict = {}

# Σειριοποιει τα concurrent appends στο verify log (BackgroundTasks -> ιδιο process)
_verify_log_lock = threading.Lock()



def _trained() -> tuple[set, dict]:
    # απο το mtl_config.json του training. Αλλα labels απο τα τωρινα -> τα indices δεν ταιριαζουν
    try:
        with open(CONFIG_PATH, encoding="utf-8") as f:
            config = json.load(f)
    except (OSError, ValueError) as e:
        print(f"[!] WARNING: cannot read '{CONFIG_PATH}' ({e}) - risk head disabled.")
        return set(), {}
    if config.get("labels") != LABEL_SETS:
        print(f"[!] WARNING: labels in '{CONFIG_PATH}' differ from labels.py - retrain the model.")
        return set(), {}
    # trained_labels λειπει σε παλια config -> ολα τα labels των trained tasks
    return set(config.get("trained_tasks", [])), config.get("trained_labels", {})


@asynccontextmanager
async def lifespan(app: FastAPI):
    ml["tokenizer"] = AutoTokenizer.from_pretrained(MODEL_NAME, revision=MODEL_REVISION)
    model = WellnessMTLModel().to(device)
    ml["trained"] = False
    ml["trained_tasks"] = set()
    ml["trained_labels"] = {}
    if os.path.exists(WEIGHTS_PATH):
        try:
            model.load_state_dict(torch.load(WEIGHTS_PATH, map_location=device, weights_only=True))
            ml["trained"] = True
            ml["trained_tasks"], ml["trained_labels"] = _trained()
            print(f"[+] Loaded trained weights: {WEIGHTS_PATH} (tasks: {sorted(ml['trained_tasks'])})")
        except RuntimeError as e:
            # βαρη απο παλια αρχιτεκτονικη (π.χ. πριν τα MLP heads) -> θελει νεο training
            print(f"[!] WARNING: '{WEIGHTS_PATH}' does not match the model, retrain it - running on un-trained heads. ({e})")
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


def _multi_probs(task: str, labels: list, probs) -> dict:
    # μονο τα labels που εκπαιδευτηκαν, τα υπολοιπα βγαζουν τυχαια ~0.5
    keep = ml["trained_labels"].get(task)
    return {l: round(p.item(), 4) for l, p in zip(labels, probs) if keep is None or l in keep}


def _above(probs: dict) -> list:
    return [l for l, p in sorted(probs.items(), key=lambda x: -x[1]) if p >= MULTI_LABEL_THRESHOLD]


@torch.no_grad()
def run_mtl(text: str) -> dict:
    enc = ml["tokenizer"](
        text, truncation=True, padding="max_length",
        max_length=MAX_LEN, return_tensors="pt",
    ).to(device)

    logits = ml["model"](enc["input_ids"], enc["attention_mask"])
    probs = {
        task: (torch.sigmoid(out) if task in MULTI_LABEL_TASKS else F.softmax(out, dim=-1))[0]
        for task, out in logits.items()
    }

    sent_p = probs["sentiment"]
    iro_p = probs["irony"]
    dist_p = probs["distortion"]
    emo_probs = _multi_probs("emotion", EMOTION_LABELS, probs["emotion"])
    type_probs = _multi_probs("distortion_types", DISTORTION_TYPE_LABELS, probs["distortion_types"])

    # Συνεχες sentiment score [-1,1] = σταθμισμενο αθροισμα της κατανομης
    sentiment_score = _round_score(sum(p.item() * w for p, w in zip(sent_p, SENTIMENT_WEIGHTS)))
    sent_idx = int(torch.argmax(sent_p))
    dominant = max(emo_probs, key=emo_probs.get) if emo_probs else None
    distortion_detected = int(torch.argmax(dist_p)) == 1

    return {
        "sentiment_score": sentiment_score,
        "dominant_emotion": dominant,

"tasks": {
            "sentiment": {
                "label": SENTIMENT_LABELS[sent_idx],
                "score": sentiment_score,
                "probabilities": {l: round(p.item(), 4) for l, p in zip(SENTIMENT_LABELS, sent_p)},
            },
            "emotion": {
                "label": dominant,
                "labels": _above(emo_probs),
                "probabilities": emo_probs,
            },
            "irony": {
                "label": IRONY_LABELS[int(torch.argmax(iro_p))],
                "probability": round(iro_p[1].item(), 4),  # P(Ναι)
            },
            "distortion": {  # CBT cognitive distortion
                "label": DISTORTION_LABELS[int(distortion_detected)],
                "detected": distortion_detected,
                "probability": round(dist_p[1].item(), 4),  # P(Ναι)
                "types": _above(type_probs) if distortion_detected else [],
                "type_probabilities": type_probs,
            },
            # μονο αν το head εκπαιδευτηκε, αλλιως ενα τυχαιο head θα εβγαζε ψευτικους συναγερμους
            "risk": {
                "label": RISK_LABELS[int(torch.argmax(probs["risk"]))],
                "probability": round(probs["risk"][1].item(), 4),  # P(Ναι)
            } if "risk" in ml["trained_tasks"] else None,
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
            f"sentiment (ένα από {SENTIMENT_LABELS}), "
            f"emotion (λίστα με όσα ισχύουν από {EMOTION_LABELS}), "
            f"irony (ένα από {IRONY_LABELS}), distortion (ένα από {DISTORTION_LABELS}), "
            f"distortion_types (λίστα με όσα ισχύουν από {DISTORTION_TYPE_LABELS}, κενή αν distortion είναι Όχι), "
            f"risk (ένα από {RISK_LABELS}: Ναι μόνο για ενδείξεις αυτοτραυματισμού ή αυτοκτονικού ιδεασμού)."
        )
        resp = client.chat.completions.create(
            model=OPENAI_MODEL,
            messages=[{"role": "system", "content": system}, {"role": "user", "content": text}],
            response_format={"type": "json_object"},
            temperature=0,
        )
        raw = json.loads(resp.choices[0].message.content)
        truth = {}
        for k, labels in LABEL_SETS.items():
            value = raw.get(k)
            if k in MULTI_LABEL_TASKS:
                truth[k] = sorted({v for v in value if v in labels}) if isinstance(value, list) else None
            else:
                truth[k] = value if value in labels else None

        tasks = local_result["tasks"]
        local = {
            "sentiment": tasks["sentiment"]["label"],
            "emotion": sorted(tasks["emotion"]["labels"]),
            "irony": tasks["irony"]["label"],
            "distortion": tasks["distortion"]["label"],
            "distortion_types": sorted(tasks["distortion"]["types"]),
            "risk": tasks["risk"]["label"] if tasks["risk"] else None,
        }
        agreement = {k: (local[k] == truth[k]) for k in LABEL_SETS}
        record = {
            "ts": datetime.now(timezone.utc).isoformat(),
            "text": text[:280],
            "local": local,
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
        "weights_loaded": ml.get("trained", False),
        "trained_tasks": sorted(ml.get("trained_tasks", [])),
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
        "emotions": result["tasks"]["emotion"]["labels"],
        "entities": entities,         
        "analysis": result["tasks"],
        "model_version": "mtl-greekbert-v2",
        # το PHP κραταει μονο τα tasks που εκπαιδευτηκαν, τα υπολοιπα ειναι τυχαια
        "model_trained": ml.get("trained", False),
        "trained_tasks": sorted(ml.get("trained_tasks", [])),
    }

# Εκκινηση: python main.py -> ακουει μονο τοπικα (127.0.0.1), ποτε σε ολο το δικτυο
if __name__ == "__main__":
    import uvicorn

    uvicorn.run(app, host="127.0.0.1", port=8000)
