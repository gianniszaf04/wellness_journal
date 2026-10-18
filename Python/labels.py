#Φάση 1 -> 3 κλάσεις
SENTIMENT_LABELS = ["Αρνητικό", "Ουδέτερο", "Θετικό"]

SENTIMENT_WEIGHTS = [-1.0, 0.0, 1.0]

#Φαση 2 -> 12 κλάσεις, multi-label (ενα κειμενο μπορει να εχει πολλα συναισθηματα)
# νεα labels μονο στο τελος, αλλιως αλλαζουν τα indices των υπαρχοντων

EMOTION_LABELS = [
    "Χαρά",
    "Λύπη",
    "Θυμός",
    "Φόβος",
    "Άγχος",
    "Ηρεμία",
    "Αγάπη",
    "Έκπληξη",
    "Κούραση",
    "Ενθουσιασμός",
    "Ντροπή",
    "Ενοχή",
]

#Φάση 3 -> Ευρεση Ειρωνείας, σαρκασμού (0,1)
IRONY_LABELS = ["Όχι","Ναι"]

#Φαση 4 ->  Λανθασμένα ή υπερβολικά μοτίβα σκέψης.

DISTORTION_LABELS = ["Όχι","Ναι"]

# Τυποι γνωστικης διαστρεβλωσης (CBT, Burns), multi-label
DISTORTION_TYPE_LABELS = [
    "Καταστροφολογία",
    "Όλα ή τίποτα",
    "Υπεργενίκευση",
    "Ανάγνωση σκέψης",
    "Προφητεία",
    "Συναισθηματική συλλογιστική",
    "Πρέπει",
    "Ταμπέλες",
    "Προσωποποίηση",
    "Νοητικό φίλτρο",
]

#Φαση 5 -> Κινδυνος αυτοτραυματισμου / αυτοκτονικου ιδεασμου
RISK_LABELS = ["Όχι","Ναι"]

TASKS = ["sentiment","emotion","irony","distortion","distortion_types","risk"]

MULTI_LABEL_TASKS = {"emotion", "distortion_types"}

LABEL_SETS = {
    "sentiment": SENTIMENT_LABELS,
    "emotion": EMOTION_LABELS,
    "irony": IRONY_LABELS,
    "distortion": DISTORTION_LABELS,
    "distortion_types": DISTORTION_TYPE_LABELS,
    "risk": RISK_LABELS,
}

HEAD_DIMS = {task: len(labels) for task, labels in LABEL_SETS.items()}

# οριο για τα multi-label (sigmoid)
MULTI_LABEL_THRESHOLD = 0.5
