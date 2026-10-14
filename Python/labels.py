#Φάση 1 -> 3 κλάσεις
SENTIMENT_LABELS = ["Αρνητικό", "Ουδέτερο", "Θετικό"]

SENTIMENT_WEIGHTS = [-1.0, 0.0, 1.0]

#Φαση 2 -> 10 κλάσεις

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
]

#Φάση 3 -> Ευρεση Ειρωνείας, σαρκασμού (0,1)
IRONY_LABELS = ["Όχι","Ναι"]

#Φαση 4 ->  Λανθασμένα ή υπερβολικά μοτίβα σκέψης.

DISTORTION_LABELS = ["Όχι","Ναι"]

TASKS = ["sentiment","emotion","irony","distortion"]

HEAD_DIMS = {
    "sentiment": len(SENTIMENT_LABELS),
    "emotion": len(EMOTION_LABELS),
    "irony": len(IRONY_LABELS),
    "distortion": len(DISTORTION_LABELS),
}

LABEL_SETS = {
    "sentiment": SENTIMENT_LABELS,
    "emotion": EMOTION_LABELS,
    "irony": IRONY_LABELS,
    "distortion": DISTORTION_LABELS,
}