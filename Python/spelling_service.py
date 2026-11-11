import os
import re
import unicodedata
from collections import defaultdict

from symspellpy import SymSpell, Verbosity

HERE = os.path.dirname(os.path.abspath(__file__))
SPELLING_DIR = os.getenv("SPELLING_DIR", os.path.join(HERE, "experiments", "spelling", "output"))
WORD = re.compile(r"[Ͱ-Ͽἀ-῿]+")
APOSTROPHES = "'’ʼ‘"
OPENERS = "\"«»“”'‘’(-–—"
SENTENCE_END = ".!;?…\n"
MAX_DISTANCE = 2
PREFIX_LENGTH = 7
MAX_WORDS = 5
MAX_LIVE_WORDS = 50
MAX_SUGGESTIONS = 3
CONFUSABLE_RATIO = 100
SOUNDS = (("ου", "ου"), ("αυ", "αυ"), ("ευ", "ευ"), ("ει", "ι"), ("οι", "ι"), ("υι", "ι"), ("αι", "ε"))
LETTERS = {"η": "ι", "υ": "ι", "ω": "ο", "ς": "σ"}

_SPELLER = None


def normalize(word: str) -> str:
    word = unicodedata.normalize("NFD", word.lower())
    return unicodedata.normalize("NFC", "".join(c for c in word if unicodedata.category(c) != "Mn"))


def distance_for(word: str) -> int:
    return 0 if len(word) <= 2 else 1 if len(word) <= 5 else 2


def final_sigma(word: str) -> str:
    return word[:-1] + "ς" if len(word) >= 2 and word.endswith("σ") else word


def sound(word: str) -> str:
    out, i = [], 0
    while i < len(word):
        pair = next((said for written, said in SOUNDS if word.startswith(written, i)), None)
        if pair:
            out.append(pair)
            i += 2
        else:
            out.append(LETTERS.get(word[i], word[i]))
            i += 1
    return re.sub(r"(.)\1+", r"\1", "".join(out))


def same_case(suggestion: str, raw: str) -> str:
    if len(raw) > 1 and raw.isupper():
        return normalize(suggestion).upper()
    return suggestion[:1].upper() + suggestion[1:] if raw[:1].isupper() else suggestion


class Corrector:
    def __init__(self, folder: str = SPELLING_DIR):
        self.sym = SymSpell(MAX_DISTANCE, PREFIX_LENGTH)
        self.sym.load_pickle(os.path.join(folder, "symspell.pickle"))
        self.counts = self.sym.words
        with open(os.path.join(folder, "known.txt"), encoding="utf-8") as f:
            self.known = set(f.read().split())
        self.accents = {}
        accents = os.path.join(folder, "accents.tsv")
        if os.path.exists(accents):
            with open(accents, encoding="utf-8") as f:
                self.accents = dict(line.rstrip("\n").split("\t") for line in f if "\t" in line)
        self.frequent = set()
        frequent = os.path.join(folder, "frequent.txt")
        if os.path.exists(frequent):
            with open(frequent, encoding="utf-8") as f:
                self.frequent = set(f.read().split())
        self.top_count = max(self.counts.values())
        self._sounds = None

    @property
    def sounds(self) -> dict:
        if self._sounds is None:
            self._sounds = defaultdict(list)
            for w in sorted(self.counts, key=lambda x: -self.counts[x]):
                self._sounds[sound(w)].append(w)
        return self._sounds

    def lookup(self, w: str, distance: int) -> str | None:
        found = self.sym.lookup(w, Verbosity.TOP, max_edit_distance=distance)
        return found[0].term if found else None

    def split(self, w: str) -> str | None:
        best, score = None, 0
        for i in range(2, len(w) - 1):
            a, b = final_sigma(w[:i]), w[i:]
            s = min(self.counts.get(a, 0), self.counts.get(b, 0))
            if s > score:
                best, score = f"{a} {b}", s
        return best

    def word(self, w: str) -> str | None:
        if w in self.known or distance_for(w) == 0:
            return None
        found = self.lookup(w, 1) or self.split(w)
        if not found and distance_for(w) == 2:
            found = self.lookup(w, 2)
        return found

    def fix(self, raw: str) -> str | None:
        w = normalize(raw)
        sigma = len(w) >= 2 and raw[-1] in "σΣ"
        w = final_sigma(w)
        return self.word(w) or (w if sigma else None)

    @staticmethod
    def sentence_start(text: str, i: int) -> bool:
        j = i - 1
        while j >= 0 and (text[j].isspace() or text[j] in OPENERS):
            j -= 1
        return j < 0 or text[j] in SENTENCE_END

    def skip(self, text: str, m: re.Match) -> bool:
        raw, before, after = m.group(0), text[m.start() - 1:m.start()], text[m.end():m.end() + 1]
        if (after and (after in APOSTROPHES or after.isalnum())) or (before and before.isalnum()):
            return True
        return raw[0].isupper() and not raw.isupper() and not self.sentence_start(text, m.start())

    def correct(self, text: str) -> tuple[str, list[tuple[str, str]]]:
        text = unicodedata.normalize("NFC", text)
        out, changes, last = [], [], 0
        for m in WORD.finditer(text):
            if self.skip(text, m):
                continue
            new = self.fix(m.group(0))
            if new:
                out += [text[last:m.start()], new]
                last = m.end()
                changes.append((m.group(0), new))
        out.append(text[last:])
        return "".join(out), changes

    def candidates(self, w: str, phonetic: bool = False) -> list[str]:
        if w in self.known or distance_for(w) == 0:
            return []
        found = {s.term: (s.distance, -s.count) for s in self.sym.lookup(w, Verbosity.ALL, max_edit_distance=distance_for(w))}
        split = self.split(w)
        if split:
            found.setdefault(split, (1, -min(self.counts[p] for p in split.split())))
        key = sound(w)
        if phonetic:
            for term in self.sounds.get(key, ()):
                found.setdefault(term, (MAX_DISTANCE + 1, -self.counts[term]))
        rank = (lambda t: (sound(t) != key, *found[t])) if phonetic else (lambda t: found[t])
        return sorted(found, key=rank)

    def confusable(self, w: str, ratio: int = CONFUSABLE_RATIO) -> list[str]:
        if w not in self.known or w in self.frequent or distance_for(w) == 0:
            return []
        least = ratio * max(self.counts.get(w, 0), 1)
        if least > self.top_count:
            return []
        found = [s for s in self.sym.lookup(w, Verbosity.ALL, max_edit_distance=1) if s.term != w and s.count >= least]
        return [s.term for s in sorted(found, key=lambda s: -s.count)]

    def accented(self, term: str, raw: str) -> str:
        return same_case(" ".join(self.accents.get(p, p) for p in term.split()), raw)

    def suggest(self, text: str, known_words=(), phonetic: bool = False, limit: int = MAX_WORDS,
                confusables: int = 0) -> list[dict]:
        personal = {final_sigma(normalize(w)) for w in known_words}
        out = []
        for m in WORD.finditer(text):
            if len(out) == limit:
                break
            raw = m.group(0)
            w = final_sigma(normalize(raw))
            if self.skip(text, m) or w in personal:
                continue
            terms = self.confusable(w, confusables) if confusables and w in self.known else self.candidates(w, phonetic)
            options = list(dict.fromkeys(self.accented(t, raw) for t in terms))[:MAX_SUGGESTIONS]
            if options:
                out.append({"word": raw, "start": m.start(), "end": m.end(), "suggestions": options})
        return out


def load_spelling() -> Corrector | None:
    global _SPELLER
    if _SPELLER is None:
        try:
            _SPELLER = Corrector()
            print(f"[+] Spelling loaded: {SPELLING_DIR} ({len(_SPELLER.counts)} words)")
        except Exception as e:
            print(f"[!] Failed to load spelling ({SPELLING_DIR}): {e}  -> spelling will be []")
            _SPELLER = False
    return _SPELLER or None


def suggest_spelling(text: str, known_words=(), limit: int = MAX_WORDS) -> list[dict]:
    speller = load_spelling()
    if speller is None:
        return []
    try:
        return speller.suggest(text, known_words, limit=limit)
    except Exception as e:
        print(f"[spelling] failed: {e}")
        return []
