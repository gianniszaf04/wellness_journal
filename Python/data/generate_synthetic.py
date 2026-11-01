import argparse
import csv
import json
import os
import random
import sys
import time
from collections import Counter

from common import HERE, SEED, idx, multi, summary, write
from merge_dataset import key as text_key
from translate_gemini import generate, load_key

from labels import LABEL_SETS

SYNTHETIC = os.path.join(HERE, "synthetic")
GENERATED = os.path.join(SYNTHETIC, "generated.csv")
REVIEW = os.path.join(SYNTHETIC, "review.csv")
FIELDS = ["id", "category", "status", "reason", "text", "labels", "request", "blind", "model"]
GROUP_SIZE = 20

TOPICS = [
    "σχολή και εξεταστική", "δουλειά", "οικογένεια", "φίλοι", "σχέση", "χωρισμός", "υγεία", "ύπνος", "χρήματα",
    "μετακόμιση", "χόμπι", "γυμναστική", "κατοικίδιο", "μοναξιά", "μια συνηθισμένη μέρα", "μετακινήσεις",
    "δουλειές του σπιτιού", "φαγητό", "καιρός", "διακοπές",
]
PERSONAS = [
    "φοιτήτρια, 20 χρονών", "φοιτητής, 22 χρονών", "μαθήτρια λυκείου, 17 χρονών", "μαθητής λυκείου, 17 χρονών",
    "εργαζόμενη, 29 χρονών", "εργαζόμενος, 31 χρονών", "μητέρα με μικρό παιδί, 38 χρονών", "πατέρας, 45 χρονών",
    "άνεργη, 34 χρονών", "ελεύθερος επαγγελματίας, 40 χρονών", "συνταξιούχος γυναίκα, 66 χρονών",
    "συνταξιούχος άντρας, 69 χρονών",
]
NAMES = [
    "Μαρία", "Ελένη", "Κατερίνα", "Δήμητρα", "Σοφία", "Αναστασία", "Ιωάννα", "Βασιλική", "Γεωργία", "Χριστίνα",
    "Δέσποινα", "Αθηνά", "Ζωή", "Ειρήνη", "Μυρτώ", "Φωτεινή", "Αλεξάνδρα", "Νεφέλη", "Στέλλα", "Ρένα",
    "Γιώργος", "Γιάννης", "Κώστας", "Δημήτρης", "Νίκος", "Παναγιώτης", "Βασίλης", "Χρήστος", "Θανάσης", "Μιχάλης",
    "Στέφανος", "Άρης", "Λευτέρης", "Σπύρος", "Αντώνης", "Πέτρος", "Μάνος", "Φώτης", "Τάσος", "Ηλίας",
]
LENGTHS = {
    "σύντομο": "1-2 προτάσεις, 8-25 λέξεις",
    "μεσαίο": "3-6 προτάσεις, 40-90 λέξεις",
    "μεγάλο": "μία παράγραφος, 120-200 λέξεις",
}
LENGTH_WEIGHTS = (0.35, 0.45, 0.2)

TYPES = LABEL_SETS["distortion_types"]
WEAK_TYPES = {"Καταστροφολογία", "Όλα ή τίποτα", "Υπεργενίκευση", "Νοητικό φίλτρο", "Προσωποποίηση"}
EMOTION_FOR_TYPE = {
    "Καταστροφολογία": ["Άγχος", "Φόβος"],
    "Όλα ή τίποτα": ["Λύπη", "Ντροπή"],
    "Υπεργενίκευση": ["Λύπη", "Θυμός"],
    "Ανάγνωση σκέψης": ["Ντροπή", "Λύπη", "Άγχος"],
    "Προφητεία": ["Άγχος", "Λύπη"],
    "Συναισθηματική συλλογιστική": ["Λύπη", "Ντροπή"],
    "Πρέπει": ["Ενοχή", "Άγχος", "Θυμός"],
    "Ταμπέλες": ["Ντροπή", "Θυμός"],
    "Προσωποποίηση": ["Ενοχή", "Λύπη"],
    "Νοητικό φίλτρο": ["Λύπη"],
}


def neutral(rng):
    return "Ουδέτερο", [], [], False, ("Σκέτη περιγραφή του τι έγινε, χωρίς κανένα συναίσθημα: ούτε ευχαρίστηση, "
                                       "ούτε δυσφορία, ούτε βαρεμάρα, ούτε κούραση.")


def tired(rng):
    extra = [rng.choice(["Λύπη", "Άγχος", "Θυμός"])] if rng.random() < 0.4 else []
    return "Αρνητικό", ["Κούραση"] + extra, [], False, "Σωματική ή ψυχική εξάντληση, έλλειψη ενέργειας ή λίγος ύπνος."


def mixed(rng):
    bad = rng.choice(["Λύπη", "Θυμός", "Άγχος", "Κούραση"])
    good = rng.choice(["Χαρά", "Ηρεμία", "Αγάπη", "Ενθουσιασμός"])
    if rng.random() < 0.6:
        return "Θετικό", [bad, good], [], False, (f"Μικτή μέρα: το πρώτο μέρος δείχνει καθαρά «{bad}», σε μία-δύο "
                                                  f"προτάσεις, και το τέλος δείχνει καθαρά «{good}» και κλείνει θετικά.")
    return "Αρνητικό", [good, bad], [], False, (f"Μικτή μέρα: το πρώτο μέρος δείχνει καθαρά «{good}», σε μία-δύο "
                                                f"προτάσεις, και το τέλος δείχνει καθαρά «{bad}» και κλείνει αρνητικά.")


def calm(rng):
    extra = rng.choice([[], [], ["Χαρά"], ["Αγάπη"]])
    return "Θετικό", ["Ηρεμία"] + extra, [], False, "Γαλήνη, χαλάρωση ή ανακούφιση που φαίνεται καθαρά."


def positive(rng):
    emotions = [rng.choice(["Χαρά", "Χαρά", "Ενθουσιασμός"])] + (["Αγάπη"] if rng.random() < 0.3 else [])
    return "Θετικό", sorted(emotions, key=LABEL_SETS["emotion"].index), [], False, (
        "Μια καλή μέρα ή στιγμή, με συγκεκριμένα γεγονότα και όχι γενικόλογα.")


def anxiety(rng):
    extra = ["Κούραση"] if rng.random() < 0.25 else []
    return "Αρνητικό", ["Άγχος"] + extra, [], False, ("Ρεαλιστική ανησυχία για κάτι που έρχεται ή είναι αβέβαιο, "
                                                       "χωρίς απόλυτες ή υπερβολικές σκέψεις.")


def irony(rng):
    emotion = rng.choices(["Θυμός", "Κούραση"], (0.7, 0.3))[0]
    return "Αρνητικό", [emotion], [], True, ("Ειρωνεία: λέει το αντίθετο από αυτό που εννοεί για κάτι που πήγε "
                                             "στραβά, π.χ. «τέλεια, ό,τι ήθελα».")


def hard_no(rng):
    emotions = rng.sample(["Λύπη", "Θυμός", "Ντροπή", "Ενοχή", "Φόβος", "Άγχος"], rng.choice([1, 1, 2]))
    return "Αρνητικό", emotions, [], False, ("Έντονο αρνητικό συναίσθημα για κάτι που έγινε, αλλά με ρεαλιστική και "
                                             "ισορροπημένη ερμηνεία, χωρίς καμία γνωστική διαστρέβλωση.")


def distortion(rng):
    weights = [2 if t in WEAK_TYPES else 1 for t in TYPES]
    types = [rng.choices(TYPES, weights)[0]]
    second = rng.choices(TYPES, weights)[0]
    if rng.random() < 0.3 and second not in types:
        types.append(second)
    emotions = sorted({rng.choice(EMOTION_FOR_TYPE[t]) for t in types}, key=LABEL_SETS["emotion"].index)
    return "Αρνητικό", emotions, types, False, ("Μέσα στο κείμενο υπάρχει μία καθαρή φράση-σκέψη για κάθε τύπο "
                                                "διαστρέβλωσης, ειπωμένη φυσικά.")


CATEGORIES = {
    "neutral": (400, neutral),
    "tired": (300, tired),
    "mixed": (300, mixed),
    "calm": (200, calm),
    "positive": (200, positive),
    "anxiety": (200, anxiety),
    "irony": (200, irony),
    "hard_no": (250, hard_no),
    "distortion": (300, distortion),
}

DEFINITIONS = """Πολικότητα (μία):
- Θετικό: η συνολική διάθεση είναι καλή.
- Ουδέτερο: περιγραφή χωρίς ουσιαστικό συναίσθημα, ή θετικά και αρνητικά ισορροπούν.
- Αρνητικό: η συνολική διάθεση είναι κακή.
Σε μικτά κείμενα κρίνεται από το συνολικό αποτέλεσμα, συνήθως από το πώς κλείνει το κείμενο.

Συναισθήματα του συγγραφέα (όσα φαίνονται καθαρά, μπορεί και κανένα):
- Χαρά: ευχαρίστηση, ικανοποίηση, κέφι.
- Λύπη: στεναχώρια, απογοήτευση, πένθος.
- Θυμός: εκνευρισμός, οργή, αδικία.
- Φόβος: φόβος για συγκεκριμένο κίνδυνο ή απειλή τώρα.
- Άγχος: ανησυχία ή ένταση για κάτι που έρχεται ή είναι αβέβαιο.
- Ηρεμία: γαλήνη, ανακούφιση, χαλαρότητα που φαίνεται καθαρά. Μια βαρετή μέρα δεν είναι ηρεμία.
- Αγάπη: τρυφερότητα, στοργή, ευγνωμοσύνη για πρόσωπα.
- Έκπληξη: κάτι απρόσμενο, θετικό ή αρνητικό.
- Κούραση: σωματική ή ψυχική εξάντληση, έλλειψη ενέργειας.
- Ενθουσιασμός: έντονη, ενεργητική προσμονή ή χαρά.
- Ντροπή: αίσθηση ότι «εκτέθηκα», ότι κάτι δεν πάει καλά με μένα.
- Ενοχή: αίσθηση ότι έκανα κάτι λάθος σε κάποιον ή κάτι.
Δύσκολα ζευγάρια:
- Φόβος ή Άγχος; Φόβος = συγκεκριμένη απειλή τώρα («ένας σκύλος μου όρμησε»). Άγχος = ανησυχία για κάτι που έρχεται ή είναι αβέβαιο («αύριο εξετάσεις και δεν έχω διαβάσει»).
- Χαρά ή Ενθουσιασμός; Ενθουσιασμός μόνο όταν υπάρχει ένταση ή προσμονή. Σε αμφιβολία, Χαρά.
- Ντροπή ή Ενοχή; Ντροπή = «είμαι χάλια» (για τον εαυτό). Ενοχή = «έκανα κάτι χάλια» (για μια πράξη).
- Ηρεμία μόνο όταν φαίνεται η γαλήνη. Έκπληξη μόνο όταν φαίνεται ότι ο ίδιος ξαφνιάστηκε («δεν το περίμενα», «έμεινα άφωνη»).

Γνωστική διαστρέβλωση: υπερβολικός ή άκαμπτος τρόπος ερμηνείας της πραγματικότητας από τον συγγραφέα, όχι το γεγονός ή το συναίσθημα. Υπάρχει μόνο όταν υπάρχει συγκεκριμένη φράση-σκέψη που μπορείς να δείξεις.
- Λύπη δεν είναι διαστρέβλωση: «Χωρίσαμε και πονάω πολύ» -> Όχι. «Χωρίσαμε, κανείς δεν θα με αγαπήσει ποτέ» -> Ναι.
- Ρεαλιστική ανησυχία δεν είναι διαστρέβλωση: «Φοβάμαι μήπως κοπώ, δεν διάβασα αρκετά» -> Όχι.
Τύποι:
- Καταστροφολογία: το χειρότερο σενάριο ή μεγέθυνση ενός προβλήματος.
- Όλα ή τίποτα: σε άσπρο-μαύρο, χωρίς ενδιάμεσο.
- Υπεργενίκευση: ένα γεγονός γίνεται κανόνας («πάντα», «ποτέ», «όλοι»).
- Ανάγνωση σκέψης: βεβαιότητα για το τι σκέφτονται οι άλλοι, χωρίς στοιχεία.
- Προφητεία: βεβαιότητα για το (αρνητικό) μέλλον.
- Συναισθηματική συλλογιστική: «το νιώθω, άρα ισχύει».
- Πρέπει: άκαμπτοι κανόνες για τον εαυτό ή τους άλλους, με συναισθηματικό βάρος. «Πρέπει να ψωνίσω γάλα» δεν μετράει.
- Ταμπέλες: ολική ετικέτα σε άνθρωπο αντί για συμπεριφορά.
- Προσωποποίηση: ευθύνη για κάτι που δεν ελέγχεις.
- Νοητικό φίλτρο: εστίαση μόνο στο αρνητικό, αγνοείται το θετικό.
Δύσκολα ζευγάρια:
- Προφητεία ή Καταστροφολογία; Προφητεία = «θα πάει άσχημα». Καταστροφολογία = «και θα είναι το τέλος του κόσμου». Συχνά συνυπάρχουν.
- Υπεργενίκευση ή Ταμπέλες; «Πάντα τα κάνω λάθος» = Υπεργενίκευση. «Είμαι ανίκανος» = Ταμπέλες.
- Ο φόβος μπροστά σε πραγματικό, άμεσο κίνδυνο δεν είναι διαστρέβλωση.

Ειρωνεία: το κείμενο λέει το αντίθετο από αυτό που εννοεί. Πολικότητα και συναισθήματα κρίνονται από το νόημα, όχι από τις λέξεις.

Κίνδυνος: σκέψεις αυτοτραυματισμού, θανάτου ή αυτοκτονίας του συγγραφέα για τον εαυτό του. Υπερβολές της καθημερινότητας («πέθανα στα γέλια») δεν μετράνε."""

WRITE_PROMPT = f"""Γράφεις κείμενα προσωπικού ημερολογίου στα ελληνικά. Κάθε κείμενο το γράφει διαφορετικός άνθρωπος, το βράδυ, για τη μέρα του.

Κανόνες:
- Πρώτο πρόσωπο, σωστό γραμματικό γένος για τον συγγραφέα που δίνεται.
- Απλό, προφορικό ύφος, όπως γράφει κάποιος βιαστικά στο κινητό του το βράδυ. Όχι λογοτεχνικές ή ποιητικές περιγραφές, όχι λόγιες λέξεις.
- Ακολούθησε ακριβώς την προδιαγραφή κάθε κειμένου: συγγραφέας, θέμα, μήκος, πολικότητα, συναισθήματα, διαστρέβλωση, ειρωνεία, οδηγία.
- Να φαίνονται καθαρά τα συναισθήματα της προδιαγραφής και κανένα άλλο. Αν τα συναισθήματα είναι «κανένα», το κείμενο δεν δείχνει κανένα συναίσθημα.
- Τουλάχιστον στα μισά κείμενα να μην υπάρχει καμία λέξη που ονομάζει συναίσθημα (π.χ. «ένιωσα», «νιώθω», «χάρηκα», «ανησυχώ», «άγχος», «θυμός», «γαλήνη»). Εκεί το συναίσθημα φαίνεται μόνο από ό,τι έγινε και ό,τι σκέφτηκε ο συγγραφέας.
- Διαστρέβλωση «Όχι»: ο συγγραφέας ερμηνεύει ρεαλιστικά. Χωρίς «πάντα/ποτέ/όλοι», χωρίς ταμπέλες για τον εαυτό ή τους άλλους, χωρίς βεβαιότητα για το μέλλον ή για τις σκέψεις των άλλων, χωρίς άκαμπτα «πρέπει», χωρίς υπερβολές.
- Διαστρέβλωση «Ναι»: υπάρχει μία καθαρή φράση-σκέψη για κάθε τύπο που δίνεται, και κανένας άλλος τύπος. Η σκέψη δένει φυσικά με το υπόλοιπο κείμενο, όπως θα την έκανε κανείς εκείνη τη στιγμή, όχι σαν πρόταση που κολλήθηκε. Είναι καθαρά δυσανάλογη με αυτό που έγινε, όχι αντίδραση σε πραγματικό, άμεσο κίνδυνο.
- Ειρωνεία μόνο όταν η προδιαγραφή λέει «Ναι».
- Καμία αναφορά σε αυτοτραυματισμό, αυτοκτονία ή θάνατο του συγγραφέα.
- Ποικιλία: κάθε κείμενο με διαφορετική αρχή, δομή και λεξιλόγιο. Λίγα να ξεκινούν με «Σήμερα», «Γύρισα» ή «Μετά από». Χωρίς τίτλους, ημερομηνίες, υπογραφές ή emoji.
- Αν χρειάζεσαι όνομα για άλλο πρόσωπο, χρησιμοποίησε το «όνομα» της προδιαγραφής.

Ορισμοί:
{DEFINITIONS}

Θα λάβεις JSON λίστα με προδιαγραφές. Επίστρεψε για καθεμία το ίδιο "i" και το κείμενο στο "text".
"""

LABEL_PROMPT = f"""Βάζεις labels σε ελληνικά κείμενα προσωπικού ημερολογίου, για τον συγγραφέα και όχι για τους άλλους που αναφέρει. Μετράει ό,τι εκφράζεται ή εννοείται καθαρά, όχι υποθέσεις. Σε αμφιβολία, λιγότερα labels.
Συναισθήματα: σημείωσε όλα όσα φαίνονται καθαρά σε οποιοδήποτε σημείο του κειμένου, και στην αρχή του. Μόνο η πολικότητα κρίνεται από το πώς κλείνει το κείμενο, τα συναισθήματα όχι.

{DEFINITIONS}

Θα λάβεις JSON λίστα με αντικείμενα {{"i": αριθμός, "text": κείμενο}}. Για ΚΑΘΕ αντικείμενο επίστρεψε το ίδιο "i" και:
- "sentiment": η πολικότητα;
- "emotions": λίστα με τα συναισθήματα, κενή αν δεν φαίνεται κανένα;
- "distortion": "yes" ή "no";
- "types": λίστα με τους τύπους διαστρέβλωσης, κενή αν "no";
- "irony": "yes" ή "no";
- "risk": "yes" ή "no".
"""

WRITE_SCHEMA = {"type": "ARRAY", "items": {"type": "OBJECT", "properties": {
    "i": {"type": "INTEGER"}, "text": {"type": "STRING"}}, "required": ["i", "text"]}}
LABEL_SCHEMA = {"type": "ARRAY", "items": {"type": "OBJECT", "properties": {
    "i": {"type": "INTEGER"},
    "sentiment": {"type": "STRING", "enum": LABEL_SETS["sentiment"]},
    "emotions": {"type": "ARRAY", "items": {"type": "STRING", "enum": LABEL_SETS["emotion"]}},
    "distortion": {"type": "STRING", "enum": ["yes", "no"]},
    "types": {"type": "ARRAY", "items": {"type": "STRING", "enum": TYPES}},
    "irony": {"type": "STRING", "enum": ["yes", "no"]},
    "risk": {"type": "STRING", "enum": ["yes", "no"]},
}, "required": ["i", "sentiment", "emotions", "distortion", "types", "irony", "risk"]}}


def spec(category: str, k: int) -> dict:
    rng = random.Random(f"{SEED}:{category}:{k}")
    sentiment, emotions, types, ironic, hint = CATEGORIES[category][1](rng)
    length = rng.choices(list(LENGTHS), LENGTH_WEIGHTS)[0]
    if category == "mixed" and length == "σύντομο":
        length = "μεσαίο"
    return {
        "id": f"syn-{category}-{k:04d}", "group": f"syn-{category}-{k // GROUP_SIZE}", "category": category,
        "persona": rng.choice(PERSONAS), "topic": rng.choice(TOPICS), "length": length, "name": rng.choice(NAMES),
        "sentiment": sentiment, "emotions": emotions, "types": types, "irony": ironic, "hint": hint,
    }


def request(i: int, s: dict) -> dict:
    return {
        "i": i, "συγγραφέας": s["persona"], "θέμα": s["topic"], "μήκος": LENGTHS[s["length"]], "όνομα": s["name"],
        "πολικότητα": s["sentiment"], "συναισθήματα": s["emotions"] or ["κανένα"],
        "διαστρέβλωση": "Ναι: " + ", ".join(s["types"]) if s["types"] else "Όχι",
        "ειρωνεία": "Ναι" if s["irony"] else "Όχι", "οδηγία": s["hint"],
    }


def spec_of(request_json: str) -> dict:
    q = json.loads(request_json)
    types = q["διαστρέβλωση"].split(": ", 1)[1].split(", ") if q["διαστρέβλωση"].startswith("Ναι") else []
    return {"sentiment": q["πολικότητα"], "emotions": [e for e in q["συναισθήματα"] if e != "κανένα"],
            "types": types, "irony": q["ειρωνεία"] == "Ναι"}


def agree(wanted: list[str], found: list[str], order: list[str]) -> list[str] | None:
    wanted, found = set(wanted), set(found)
    if not wanted <= found or (not wanted and found) or len(found - wanted) > 1:
        return None
    return sorted(found, key=order.index)


def verdict(s: dict, b: dict) -> tuple[str, dict | None]:
    if b.get("sentiment") != s["sentiment"]:
        return f"πολικότητα: {b.get('sentiment')}", None
    if (b.get("irony") == "yes") != s["irony"]:
        return f"ειρωνεία: {b.get('irony')}", None
    if b.get("risk") != "no":
        return "κίνδυνος", None
    if (b.get("distortion") == "yes") != bool(s["types"]):
        return f"διαστρέβλωση: {b.get('distortion')} {', '.join(b.get('types', []))}".strip(), None
    types = agree(s["types"], b.get("types", []), TYPES) if s["types"] else []
    if types is None:
        return f"τύποι: {', '.join(b.get('types', []))}", None
    emotions = agree(s["emotions"], b.get("emotions", []), LABEL_SETS["emotion"])
    if emotions is None:
        return f"συναισθήματα: {', '.join(b.get('emotions', [])) or 'κανένα'}", None
    return "", {"sentiment": s["sentiment"], "emotions": emotions, "distortion": "Ναι" if types else "Όχι",
                "types": types, "irony": "Ναι" if s["irony"] else "Όχι", "risk": "Όχι"}


def label(model: str, api_key: str, items: list[dict]) -> list[dict]:
    try:
        return generate(model, api_key, LABEL_PROMPT, items, LABEL_SCHEMA, 0.0, retries=3)
    except RuntimeError as e:
        if len(items) == 1:
            print(f"   [!] χωρις labels, θα ξαναγραφτει στο επομενο τρεξιμο: {e}")
            return []
        half = len(items) // 2
        return label(model, api_key, items[:half]) + label(model, api_key, items[half:])


def read(path: str) -> list[dict]:
    if not os.path.exists(path):
        return []
    with open(path, encoding="utf-8", newline="") as f:
        return list(csv.DictReader(f))


def row_of(r: dict) -> dict:
    labels = json.loads(r["labels"])
    k = int(r["id"].rsplit("-", 1)[1])
    return {
        "id": r["id"], "source": "synthetic", "group": f"syn-{r['category']}-{k // GROUP_SIZE}",
        "lang": "el", "text": r["text"],
        "sentiment": idx("sentiment", labels["sentiment"]),
        "emotion": multi("emotion", labels["emotions"]) or "none",
        "irony": idx("irony", labels["irony"]),
        "distortion": idx("distortion", labels["distortion"]),
        "distortion_types": multi("distortion_types", labels["types"]) or "none",
        "risk": idx("risk", labels["risk"]),
        "meta": f"category={r['category']};model={r['model']}",
    }


def export() -> None:
    accepted = [row_of(r) for r in read(GENERATED) if r["status"] == "accepted"]
    if accepted:
        path = write("synthetic", accepted)
        summary("synthetic", accepted)
        print(f"-> {path}")


def stats() -> None:
    rows = read(GENERATED)
    if not rows:
        sys.exit(f"[!] Δεν υπαρχει ακομα το {GENERATED}")
    print(f"{'κατηγορία':<12}{'δεκτά':>7}{'απορρ.':>8}{'ποσοστό':>9}{'λέξεις':>8}{'«Σήμερα»':>10}   συχνότεροι λόγοι απόρριψης")
    for category in CATEGORIES:
        part = [r for r in rows if r["category"] == category]
        if not part:
            continue
        ok = [r for r in part if r["status"] == "accepted"]
        reasons = Counter(r["reason"].split(":")[0] for r in part if r["status"] == "rejected")
        words = sum(len(r["text"].split()) for r in part) / len(part)
        today = sum(r["text"].lstrip().lower().startswith("σήμερα") for r in part) / len(part)
        print(f"{category:<12}{len(ok):>7}{len(part) - len(ok):>8}{len(ok) / len(part):>8.0%}{words:>8.0f}{today:>9.0%}   "
              + ", ".join(f"{k} {v}" for k, v in reasons.most_common(3)))


REVIEW_FIELDS = ["id", "text", "πολικότητα", "συναισθήματα", "διαστρέβλωση", "τύποι", "ειρωνεία", "σωστά", "λάθος_σε",
                 "σημειώσεις"]


def shown_labels(r: dict) -> dict:
    if r["labels"]:
        return json.loads(r["labels"])
    s = spec_of(r["request"])
    return {"sentiment": s["sentiment"], "emotions": s["emotions"], "distortion": "Ναι" if s["types"] else "Όχι",
            "types": s["types"], "irony": "Ναι" if s["irony"] else "Όχι"}


def review(n: int) -> None:
    if os.path.exists(REVIEW):
        sys.exit(f"[!] Υπαρχει ηδη το {REVIEW}. Μετονομασε το πρωτα, για να μη χαθει ο ελεγχος που ισως εχει γινει.")
    rows = read(GENERATED)
    rng = random.Random(SEED)
    accepted = [r for r in rows if r["status"] == "accepted"]
    rejected = [r for r in rows if r["status"] == "rejected"]
    picked = rng.sample(accepted, min(n, len(accepted))) + rng.sample(rejected, min(n // 4, len(rejected)))
    rng.shuffle(picked)
    with open(REVIEW, "w", encoding="utf-8-sig", newline="") as f:
        w = csv.DictWriter(f, fieldnames=REVIEW_FIELDS)
        w.writeheader()
        for r in picked:
            labels = shown_labels(r)
            w.writerow({
                "id": r["id"], "text": r["text"], "πολικότητα": labels["sentiment"],
                "συναισθήματα": "|".join(labels["emotions"]) or "κανένα", "διαστρέβλωση": labels["distortion"],
                "τύποι": "|".join(labels["types"]), "ειρωνεία": labels["irony"],
            })
    print(f"[+] {len(picked)} κειμενα ({min(n, len(accepted))} δεκτα, {len(picked) - min(n, len(accepted))} απορριφθεντα,"
          f" ανακατεμενα και χωρις να φαινεται ποια ειναι ποια) -> {REVIEW}")


def wilson(k: int, n: int, z: float = 1.96) -> tuple[float, float]:
    if not n:
        return 0.0, 0.0
    p = k / n
    centre = (p + z * z / (2 * n)) / (1 + z * z / n)
    half = z * ((p * (1 - p) / n + z * z / (4 * n * n)) ** 0.5) / (1 + z * z / n)
    return centre - half, centre + half


def review_score() -> None:
    status = {r["id"]: r for r in read(GENERATED)}
    with open(REVIEW, encoding="utf-8-sig", newline="") as f:
        done = [r for r in csv.DictReader(f) if r["σωστά"].strip()]
    if not done:
        sys.exit(f"[!] Καμια γραμμη του {REVIEW} δεν εχει τιμη στη στηλη «σωστά»")
    for name, wanted in (("δεκτά", "accepted"), ("απορριφθέντα", "rejected")):
        part = [r for r in done if status[r["id"]]["status"] == wanted]
        ok = sum(r["σωστά"].strip().lower() in ("ναι", "ν", "yes", "y", "1") for r in part)
        lo, hi = wilson(ok, len(part))
        wrong = Counter(text_key(w) for r in part for w in r["λάθος_σε"].split("|") if w.strip())
        print(f"{name}: {ok}/{len(part)} με σωστά labels κατα τον ανθρωπο ({ok / max(len(part), 1):.0%}, "
              f"95% Wilson {lo:.0%}-{hi:.0%}) | λαθη σε: {dict(wrong)}")
    by_cat = Counter((status[r["id"]]["category"], r["σωστά"].strip().lower() in ("ναι", "ν", "yes", "y", "1"))
                     for r in done if status[r["id"]]["status"] == "accepted")
    print("δεκτά ανά κατηγορία (σωστά/σύνολο): " + ", ".join(
        f"{c} {by_cat[(c, True)]}/{by_cat[(c, True)] + by_cat[(c, False)]}" for c in CATEGORIES
        if by_cat[(c, True)] + by_cat[(c, False)]))


def relabel(args) -> None:
    rows = read(GENERATED)
    if not rows:
        sys.exit(f"[!] Δεν υπαρχει ακομα το {GENERATED}")
    label_model = args.label_model or args.model
    print(f"[synthetic] νεος τυφλος ελεγχος σε {len(rows)} κειμενα, ~{-(-len(rows) // args.batch)} κλησεις")
    if args.dry_run:
        print(LABEL_PROMPT)
        return
    api_key = load_key()
    if not api_key:
        sys.exit("[!] Δεν βρεθηκε GEMINI_API_KEY (ουτε στο περιβαλλον ουτε στο .env).")
    before = Counter(r["status"] for r in rows)
    seen, out = set(), []
    for b in range(0, len(rows), args.batch):
        batch = rows[b:b + args.batch]
        t0 = time.time()
        blind = {x.get("i"): x for x in label(label_model, api_key, [{"i": i, "text": r["text"]} for i, r in
                                                                       enumerate(batch)]) if isinstance(x, dict)}
        for i, r in enumerate(batch):
            labels = blind.get(i)
            if not labels:
                out.append(r)
                continue
            reason, final = verdict(spec_of(r["request"]), labels)
            if final and text_key(r["text"]) in seen:
                reason, final = "διπλό", None
            if final:
                seen.add(text_key(r["text"]))
            writer = r["model"].split("+")[0]
            out.append({**r, "status": "accepted" if final else "rejected", "reason": reason,
                        "labels": json.dumps(final, ensure_ascii=False) if final else "",
                        "blind": json.dumps({k: v for k, v in labels.items() if k != "i"}, ensure_ascii=False),
                        "model": writer if label_model == writer else f"{writer}+{label_model}"})
        print(f"   {min(b + args.batch, len(rows))}/{len(rows)}")
        time.sleep(max(0.0, 60.0 / args.rpm - (time.time() - t0)))
    backup = os.path.join(SYNTHETIC, f"generated_before_relabel_{time.strftime('%Y%m%d_%H%M%S')}.csv")
    os.replace(GENERATED, backup)
    with open(GENERATED, "w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=FIELDS)
        w.writeheader()
        w.writerows(out)
    after = Counter(r["status"] for r in out)
    print(f"[+] δεκτα {before['accepted']} -> {after['accepted']}, απορριφθεντα {before['rejected']} -> {after['rejected']}."
          f" Το προηγουμενο: {backup}")
    stats()
    export()


def run(args) -> None:
    done = {r["id"] for r in read(GENERATED)}
    seen = {text_key(r["text"]) for r in read(GENERATED) if r["status"] == "accepted"}
    names = args.categories or list(CATEGORIES)
    todo = []
    for category in names:
        target = CATEGORIES[category][0] if not args.per_category else min(args.per_category, CATEGORIES[category][0])
        todo += [s for s in (spec(category, k) for k in range(target)) if s["id"] not in done]
    random.Random(SEED).shuffle(todo)
    calls = 2 * -(-len(todo) // args.batch)
    print(f"[synthetic] {len(done)} ηδη ετοιμα, {len(todo)} για παραγωγη, ~{calls} κλησεις")
    if not todo:
        export()
        return
    if args.dry_run:
        print(WRITE_PROMPT)
        print(json.dumps([request(i, s) for i, s in enumerate(todo[:3])], ensure_ascii=False, indent=1))
        print(Counter(s["category"] for s in todo))
        return
    api_key = load_key()
    if not api_key:
        sys.exit("[!] Δεν βρεθηκε GEMINI_API_KEY (ουτε στο περιβαλλον ουτε στο .env).")
    label_model = args.label_model or args.model
    os.makedirs(SYNTHETIC, exist_ok=True)
    new = not os.path.exists(GENERATED)
    with open(GENERATED, "a", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=FIELDS)
        if new:
            w.writeheader()
        try:
            for b in range(0, len(todo), args.batch):
                batch = todo[b:b + args.batch]
                t0 = time.time()
                requests = [request(i, s) for i, s in enumerate(batch)]
                try:
                    written = generate(args.model, api_key, WRITE_PROMPT, requests, WRITE_SCHEMA, args.temperature,
                                       retries=3)
                except RuntimeError as e:
                    print(f"   [!] παρτιδα {b // args.batch} απετυχε: {e}")
                    continue
                texts = {x.get("i"): str(x.get("text", "")).strip() for x in written if isinstance(x, dict)}
                items = [{"i": i, "text": t} for i, t in texts.items() if t]
                blind = {x.get("i"): x for x in label(label_model, api_key, items) if isinstance(x, dict)}
                for i, s in enumerate(batch):
                    text, labels = texts.get(i, ""), blind.get(i)
                    if not text or not labels:
                        continue
                    reason, final = verdict(s, labels)
                    if final and text_key(text) in seen:
                        reason, final = "διπλό", None
                    if final:
                        seen.add(text_key(text))
                    w.writerow({
                        "id": s["id"], "category": s["category"], "status": "accepted" if final else "rejected",
                        "reason": reason, "text": text, "labels": json.dumps(final, ensure_ascii=False) if final else "",
                        "request": json.dumps(requests[i], ensure_ascii=False),
                        "blind": json.dumps({k: v for k, v in labels.items() if k != "i"}, ensure_ascii=False),
                        "model": args.model if label_model == args.model else f"{args.model}+{label_model}",
                    })
                f.flush()
                print(f"   {min(b + args.batch, len(todo))}/{len(todo)}")
                time.sleep(max(0.0, 60.0 / args.rpm - (time.time() - t0)))
        except KeyboardInterrupt:
            print("\n[!] Διακοπη. Οτι εγινε εχει σωθει, ξανατρεξε για να συνεχισεις.")
            sys.exit(1)
    stats()
    export()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--categories", nargs="*", choices=list(CATEGORIES), default=None)
    ap.add_argument("--per-category", type=int, default=0, help="μεγιστα κειμενα ανα κατηγορια (πιλοτικο), 0 = ολα")
    ap.add_argument("--model", default="gemini-3.8-flash")
    ap.add_argument("--label-model", default=None, help="μοντελο για τον τυφλο ελεγχο των labels (default: --model)")
    ap.add_argument("--temperature", type=float, default=1.0)
    ap.add_argument("--batch", type=int, default=20, help="κειμενα ανα κληση")
    ap.add_argument("--rpm", type=float, default=8, help="μεγιστες παρτιδες ανα λεπτο")
    ap.add_argument("--dry-run", action="store_true")
    ap.add_argument("--stats", action="store_true", help="μονο στατιστικα του generated.csv")
    ap.add_argument("--review", type=int, metavar="N", help="δειγμα N δεκτων (+N/4 απορριφθεντων) για ελεγχο απο ανθρωπο")
    ap.add_argument("--export", action="store_true", help="μονο ξαναγραψιμο του interim/synthetic.csv")
    ap.add_argument("--relabel", action="store_true",
                    help="νεος τυφλος ελεγχος σε ολα τα κειμενα του generated.csv, χωρις να ξαναγραφτουν")
    ap.add_argument("--review-score", action="store_true", help="αποτελεσμα του ελεγχου απο ανθρωπο στο review.csv")
    args = ap.parse_args()
    if args.review_score:
        review_score()
    elif args.relabel:
        relabel(args)
    elif args.stats:
        stats()
    elif args.review:
        review(args.review)
    elif args.export:
        export()
    else:
        run(args)


if __name__ == "__main__":
    main()
