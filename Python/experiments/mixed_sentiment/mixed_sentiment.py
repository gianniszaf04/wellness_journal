import re
import unicodedata

from labels import SENTIMENT_LABELS

ADVERSATIVE = {"αλλά", "όμως", "ωστόσο"}
TURN = {"τελικά"}
MIN_WORDS = 4
BEFORE, AFTER = 0.5, 1.5
POSITION_SPAN = 0.5
NEUTRAL_BAND = 0.2
MIXED_LEVEL = 0.5

_SENTENCE_END = re.compile(r"[.!?;;…]+|\n+")
_EDGE_PUNCT = ",.;;:!?…«»\"'()-–—"


def _plain(word: str) -> str:
    word = unicodedata.normalize("NFD", word.lower().strip(_EDGE_PUNCT))
    return "".join(c for c in word if unicodedata.category(c) != "Mn")


def _is(word: str, forms: set) -> bool:
    word = word.lower().strip(_EDGE_PUNCT)
    if word in forms:
        return True
    return _plain(word) == word and word in {_plain(f) for f in forms}


def _connective(words: list[str], j: int) -> tuple[str | None, int]:
    if _is(words[j], ADVERSATIVE):
        if _is(words[j], {"αλλά"}) and j + 1 < len(words) and _plain(words[j + 1]) == "και":
            return None, 0
        return "adversative", 1
    if _plain(words[j]) == "παρολα" and j + 1 < len(words) and _plain(words[j + 1]) == "αυτα":
        return "adversative", 2
    if _is(words[j], TURN):
        return "turn", 1
    return None, 0


def split(text: str) -> list[dict]:
    pieces = []
    for sentence in _SENTENCE_END.split(text):
        words = sentence.split()
        lead, kind, current, j = [], None, [], 0
        while j < len(words):
            found, size = _connective(words, j)
            if found and (current or lead or pieces):
                if current:
                    pieces.append({"text": " ".join(current).strip(_EDGE_PUNCT + " "), "lead": " ".join(lead), "contrast": kind})
                    lead, kind = [], None
                lead += words[j:j + size]
                kind = "adversative" if "adversative" in (kind, found) else found
                current, j = [], j + size
                continue
            current.append(words[j])
            j += 1
        if current:
            pieces.append({"text": " ".join(current).strip(_EDGE_PUNCT + " "), "lead": " ".join(lead), "contrast": kind})
    return [p for p in pieces if p["text"]] or [{"text": text.strip(), "lead": "", "contrast": None}]


def combine(pieces: list[dict], probs: list[list[float]]) -> dict:
    n = len(pieces)
    v = [1.0] * n
    for i, p in enumerate(pieces):
        if p["contrast"] == "adversative":
            v[i] *= AFTER
            if i > 0:
                v[i - 1] *= BEFORE
    scores, weights = [], []
    for i, (neg, neu, pos) in enumerate(probs):
        position = 1.0 + POSITION_SPAN * (i / (n - 1)) if n > 1 else 1.0
        scores.append(pos - neg)
        weights.append(position * (1.0 - neu) * v[i])
    total = sum(weights)
    score = sum(w * s for w, s in zip(weights, scores)) / total if total > 0 else 0.0
    label = 1 if abs(score) <= NEUTRAL_BAND else (2 if score > 0 else 0)
    return {
        "score": score,
        "label": SENTIMENT_LABELS[label],
        "mixed": max(scores) > MIXED_LEVEL and min(scores) < -MIXED_LEVEL,
        "pieces": [{"text": p["text"], "score": s, "weight": w} for p, s, w in zip(pieces, scores, weights)],
    }


def long_enough(piece: dict) -> bool:
    return len(piece["text"].split()) >= MIN_WORDS
