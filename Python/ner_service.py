import os
import re

from spelling_service import SPELLING_DIR, WORD, final_sigma, normalize

_NLP = None
_PROPERS = None
SPACES = re.compile(r"[^\S\n]+")
NER_MODEL = os.getenv("NER_MODEL", "el_core_news_md")
NER_LEMMATIZER = os.getenv("NER_LEMMATIZE", "1") != "0"
NER_TRUECASE = os.getenv("NER_TRUECASE", "0") != "0"


LABEL_MAPPING = {
    "PERSON": "Άτομο",
    "PER": "Άτομο",
    "ORG": "Οργανισμός",
    "LOC": "Τοποθεσία",
    "GPE": "Τοποθεσία",
    "FAC": "Τοποθεσία",
    "EVENT": "Γεγονός",
    "PRODUCT": "Αντικείμενο",
    "WORK_OF_ART": "Αντικείμενο",   
}

MAX_NAME_LEN = 100
MAX_ENTITIES = 20 

def load_ner():
    global _NLP
    if _NLP is None:
        try:
            import spacy
            _NLP = spacy.load(NER_MODEL)
            has_lemma = "lemmatizer" in _NLP.pipe_names or "trainable_lemmatizer" in _NLP.pipe_names
            print(f"[+] NER loaded: {NER_MODEL} (lemmatizer: {'yes ' if has_lemma else 'no'})")
        except Exception as e:
            print(f"[!] Failed to load NER model ({NER_MODEL}): {e}  -> entities will be []")
            _NLP = False

    return _NLP or None


def load_propers() -> dict:
    global _PROPERS
    if _PROPERS is None:
        path = os.path.join(SPELLING_DIR, "propers.tsv")
        try:
            with open(path, encoding="utf-8") as f:
                _PROPERS = dict(line.rstrip("\n").split("\t") for line in f if "\t" in line)
            print(f"[+] NER truecase: {len(_PROPERS)} proper names")
        except OSError as e:
            print(f"[!] NER truecase off ({path}): {e}")
            _PROPERS = {}
    return _PROPERS


def truecase(text: str) -> str:
    propers = load_propers()
    return WORD.sub(lambda m: propers.get(final_sigma(normalize(m.group(0))), m.group(0)), text) if propers else text


def _normalize_name(ent) -> str:
    if not NER_LEMMATIZER:
        return " ".join(ent.text.split()).strip()

    parts = []
    for tok in ent:
        lemma = (tok.lemma_ or "").strip()
        if not lemma :
            lemma = tok.text

            if tok.text[:1].isupper() :
                lemma = lemma[:1].upper() + lemma[1:]
        parts.append(lemma)

    name = " ".join(" ".join(parts).split()).strip()

    if len(name) < 2:
        name = " ".join(ent.text.split()).strip()

    return name


def extract_entities(text: str, truecased: bool = NER_TRUECASE) -> list[dict]:
    nlp = load_ner()
    if nlp is None:
        return []

    try:
        text = SPACES.sub(" ", text)
        doc = nlp(truecase(text) if truecased else text)

        out, seen = [], set()
        for ent in doc.ents:
            etype=LABEL_MAPPING.get(ent.label_)
            if etype is None or (truecased and not ent.text[:1].isupper()):
                continue

            name = _normalize_name(ent)[:MAX_NAME_LEN]
            if len(name) < 2 :
                continue

            key = (name.lower(), etype)
            if key in seen:
                continue
            seen.add(key)

            out.append({
                "name": name,
                "type": etype,
            })

            if len(out) >= MAX_ENTITIES:
                break
        return out
    except Exception as e:
        print(f"[ner] inference failed: {e}")
        return []