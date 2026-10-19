# Ημερολόγιο Ευεξίας

Διαδικτυακό ημερολόγιο αυτοβελτίωσης και ψυχολογικής υποστήριξης. Ο χρήστης καταγράφει την καθημερινότητά του και ένα ελληνικό μοντέλο γλώσσας αναλύει το κείμενο σε πραγματικό χρόνο: συναίσθημα, κυρίαρχες συγκινήσεις, ειρωνεία, γνωστικές διαστρεβλώσεις και ενδείξεις κινδύνου. Με βάση την ανάλυση, η εφαρμογή προσφέρει μηνύματα ηρεμίας, προσαρμόζει το περιβάλλον και δείχνει στατιστικά για την πορεία του χρήστη στον χρόνο.

> Η εφαρμογή δεν αντικαθιστά επαγγελματία ψυχικής υγείας. Όταν ανιχνεύεται κίνδυνος, εμφανίζει τις ελληνικές γραμμές βοήθειας (1018, 10306, 112 κ.ά.).

---

## Περιεχόμενα

1. [Λειτουργίες](#λειτουργίες)
2. [Αρχιτεκτονική](#αρχιτεκτονική)
3. [Τεχνολογίες](#τεχνολογίες)
4. [Δομή φακέλων](#δομή-φακέλων)
5. [Εγκατάσταση](#εγκατάσταση)
6. [Ρυθμίσεις (.env)](#ρυθμίσεις-env)
7. [Υπηρεσία AI](#υπηρεσία-ai)
8. [Εκπαίδευση μοντέλου](#εκπαίδευση-μοντέλου)
9. [REST API](#rest-api)
10. [Tests](#tests)
11. [Συντήρηση](#συντήρηση)
12. [Ασφάλεια και ιδιωτικότητα](#ασφάλεια-και-ιδιωτικότητα)

---

## Λειτουργίες

- **Καταγραφή σκέψεων** σε απλό, φιλικό editor, με ιστορικό και προβολή κάθε καταγραφής.
- **Ζωντανή ανάλυση κειμένου** όσο γράφει ο χρήστης, χωρίς αποθήκευση:
  - συναίσθημα (αρνητικό, ουδέτερο, θετικό)
  - 12 συγκινήσεις (χαρά, λύπη, θυμός, φόβος, άγχος, ηρεμία, αγάπη, έκπληξη, κούραση, ενθουσιασμός, ντροπή, ενοχή)
  - ειρωνεία και σαρκασμός
  - γνωστικές διαστρεβλώσεις κατά CBT (καταστροφολογία, όλα ή τίποτα, υπεργενίκευση, ανάγνωση σκέψης κ.ά.)
  - ενδείξεις αυτοτραυματισμού ή αυτοκτονικού ιδεασμού
- **Αναγνώριση οντοτήτων (NER)**: πρόσωπα και γεγονότα που εμφανίζονται στις καταγραφές, ώστε να φαίνεται ποια συνδέονται με ποια συναισθήματα.
- **Εργαλεία ηρεμίας**: χαλαρωτικά μηνύματα, ήχοι και αλλαγή φόντου όταν ανιχνεύεται έντονο στρες ή θλίψη.
- **Υποστήριξη σε κρίση**: γραμμές βοήθειας όταν το μοντέλο ή οι λέξεις-κλειδιά δείχνουν κίνδυνο. Οι λέξεις-κλειδιά λειτουργούν και όταν η υπηρεσία AI δεν απαντά.
- **Στατιστικά**: τάση διάθεσης, κατανομή συγκινήσεων, εβδομαδιαίοι και μηνιαίοι μέσοι όροι, συνεχόμενες μέρες καταγραφής, συχνότερες οντότητες.
- **Insights και ημερήσιες περιλήψεις**: ειδοποιήσεις όταν η διάθεση πέφτει ή βελτιώνεται αισθητά.
- **Λογαριασμός**: αλλαγή κωδικού, εξαγωγή όλων των δεδομένων, διαγραφή λογαριασμού με περίοδο 30 ημερών για επαναφορά.

---

## Αρχιτεκτονική

```
  Browser (HTML/CSS/JS, Chart.js)
          |
          |  HTTP / JSON
          v
  Apache + PHP 8  (public/index.php, front controller)
          |                         |
          |  PDO                    |  cURL, POST /analyze
          v                         v
  MariaDB                     Python FastAPI (127.0.0.1:8000)
                                GreekBERT multi-task + spaCy NER
```

- Το PHP εξυπηρετεί τις σελίδες και το REST API. Όλα τα αιτήματα περνούν από το `public/index.php`. Το `.htaccess` σερβίρει απευθείας μόνο τον φάκελο `public/`.
- Η υπηρεσία AI ακούει μόνο τοπικά και καλείται αποκλειστικά από το PHP μέσω `App/Services/AiClient.php`.
- Το PHP κρατά μόνο τα αποτελέσματα των tasks για τα οποία έχει εκπαιδευτεί το μοντέλο. Τα υπόλοιπα αγνοούνται.

---

## Τεχνολογίες

| Επίπεδο      | Τεχνολογία                                                        |
|--------------|-------------------------------------------------------------------|
| Frontend     | HTML5, CSS3, Vanilla JavaScript, Chart.js                         |
| Backend      | PHP 8+, δικός router με middleware, Front Controller pattern      |
| Βάση         | MariaDB 10.4+ (XAMPP)                                             |
| AI service   | Python 3.11, FastAPI, Uvicorn                                     |
| Μοντέλο      | PyTorch, Transformers, `nlpaueb/bert-base-greek-uncased-v1`       |
| NER          | spaCy, `el_core_news_sm`                                          |
| Προαιρετικά  | OpenAI (επαλήθευση αποτελεσμάτων), Gemini (μετάφραση datasets)    |

---

## Δομή φακέλων

```
wellness_journal/
  App/
    Controllers/     σελίδες και controllers του API (Api/)
    Core/            Router, Request, Session, Csrf, Env, SecurityHeaders
    Middleware/      RequireLogin, RequireGuest, VerifyCsrf, RateLimit
    Models/          User, Entry, Emotion, Insight, DailySummary κ.ά.
    Services/        AiClient, CrisisSupport, UserDataExport
    Views/           templates των σελίδων
  Config/            bootstrap.php, database.php
  Routes/            web.php, όλες οι διαδρομές
  public/            index.php, το μόνο σημείο εισόδου
  database/          schema.sql, seed.sql, create_user.sql, migrations/, migrate.php
  Python/
    main.py          FastAPI service (/analyze, /health)
    model_architecture.py, labels.py, ner_service.py
    data/            scripts προετοιμασίας datasets και οδηγός annotation
    training/        train_mtl.py
  bin/               maintenance.php
  storage/           rate limit και logs (εκτός git)
  tests/             tests σε καθαρή PHP
```

---

## Εγκατάσταση

### Προαπαιτούμενα

- XAMPP με PHP 8+ (με την επέκταση cURL) και MariaDB 10.4+
- Python 3.11
- Το project μέσα στο `htdocs`, π.χ. `/Applications/XAMPP/xamppfiles/htdocs/wellness_journal`

### 1. Ρυθμίσεις

```bash
cp .env.example .env
```

Συμπληρώστε τουλάχιστον τα `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`. Δείτε την ενότητα [Ρυθμίσεις](#ρυθμίσεις-env).

### 2. Βάση δεδομένων

Πρώτη εγκατάσταση, με τον χρήστη root. Στο `create_user.sql` ορίστε πρώτα τον ίδιο κωδικό με το `DB_PASS`.

```bash
mysql -u root < database/create_user.sql
mysql -u root < database/schema.sql
mysql -u root < database/seed.sql
```

Το `schema.sql` μπορεί να ξανατρέξει με ασφάλεια. Οι αλλαγές σε υπάρχουσα βάση γίνονται με migrations:

```bash
MIGRATE_DB_USER=root MIGRATE_DB_PASS= php database/migrate.php            # τρέχει όσα δεν έχουν τρέξει
MIGRATE_DB_USER=root MIGRATE_DB_PASS= php database/migrate.php --status   # δείχνει την κατάσταση
```

### 3. Υπηρεσία AI

```bash
cd Python
python3.11 -m venv .venv
.venv/bin/pip install -r requirements.txt
.venv/bin/pip install https://github.com/explosion/spacy-models/releases/download/el_core_news_sm-3.7.0/el_core_news_sm-3.7.0-py3-none-any.whl
.venv/bin/python main.py
```

Η υπηρεσία ξεκινά στο `http://127.0.0.1:8000`. Έλεγχος κατάστασης:

```bash
curl http://127.0.0.1:8000/health
```

### 4. Εκκίνηση

Ξεκινήστε Apache και MariaDB από το XAMPP και ανοίξτε `http://localhost/wellness_journal`.

---

## Ρυθμίσεις (.env)

| Μεταβλητή                          | Περιγραφή                                                                 | Προεπιλογή                       |
|------------------------------------|---------------------------------------------------------------------------|----------------------------------|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Σύνδεση με τη βάση                                                |                                  |
| `MIGRATE_DB_USER`, `MIGRATE_DB_PASS` | Χρήστης με δικαίωμα ALTER/CREATE για τα migrations                      | ίδιος με `DB_USER`               |
| `APP_URL`                          | Βασική διεύθυνση της εφαρμογής                                            | αυτόματη ανίχνευση               |
| `APP_ENV`, `APP_DEBUG`             | Περιβάλλον και εμφάνιση σφαλμάτων                                         |                                  |
| `APP_TIMEZONE`                     | Ζώνη ώρας για PHP και MySQL                                               | `Europe/Athens`                  |
| `AI_SERVICE_URL`                   | Διεύθυνση του endpoint ανάλυσης                                           | `http://127.0.0.1:8000/analyze`  |
| `AI_SERVICE_TIMEOUT`               | Χρόνος αναμονής σε δευτερόλεπτα                                           | `4`                              |
| `OPENAI_VERIFY`                    | `1` = στέλνει τα κείμενα στο OpenAI για επαλήθευση (θέλει `OPENAI_API_KEY`) | `0`                            |
| `AI_ALLOW_UNTRAINED`               | `1` = κρατά αποτελέσματα ανεκπαίδευτου μοντέλου, μόνο για δοκιμές          | `0`                              |
| `SENTIMENT_WARNING_THRESHOLD`      | Όριο για insight πτώσης διάθεσης                                          |                                  |
| `SENTIMENT_IMPROVEMENT_THRESHOLD`  | Όριο για insight βελτίωσης                                                |                                  |
| `CRISIS_RISK_THRESHOLD`            | Πιθανότητα κινδύνου από την οποία εμφανίζονται οι γραμμές βοήθειας         | `0.3`                            |

Η υπηρεσία Python διαβάζει επίσης τα `WEIGHTS_PATH` (προεπιλογή `mtl_model.pt`), `CONFIG_PATH`, `OPENAI_MODEL` (προεπιλογή `gpt-4o-mini`), `NER_ENABLED` και `VERIFY_LOG`.

Το `OPENAI_VERIFY` στέλνει κείμενα χρηστών σε τρίτη υπηρεσία. Ενεργοποιήστε το μόνο σε περιβάλλον ανάπτυξης.

---

## Υπηρεσία AI

Ένα multi-task μοντέλο πάνω στο Greek BERT, με ξεχωριστό head για κάθε task:

| Task               | Τύπος        | Έξοδος                                   |
|--------------------|--------------|------------------------------------------|
| `sentiment`        | single-label | Αρνητικό, Ουδέτερο, Θετικό               |
| `emotion`          | multi-label  | 12 συγκινήσεις                           |
| `irony`            | binary       | Όχι, Ναι                                 |
| `distortion`       | binary       | Όχι, Ναι                                 |
| `distortion_types` | multi-label  | 10 τύποι γνωστικής διαστρέβλωσης         |
| `risk`             | binary       | Όχι, Ναι                                 |

Οι ετικέτες ορίζονται στο `Python/labels.py`. Αν αλλάξουν, το μοντέλο πρέπει να ξαναεκπαιδευτεί. Η υπηρεσία το ελέγχει στην εκκίνηση συγκρίνοντας με το `mtl_config.json`.

Endpoints:

- `GET /health`: κατάσταση, αν φορτώθηκαν βάρη, ποια tasks είναι εκπαιδευμένα, συσκευή (CPU, CUDA, MPS)
- `POST /analyze` με σώμα `{"text": "..."}`: πλήρης ανάλυση και οντότητες

Χωρίς αρχείο βαρών (`mtl_model.pt`) η υπηρεσία λειτουργεί, αλλά οι προβλέψεις είναι τυχαίες και το PHP τις απορρίπτει, εκτός αν `AI_ALLOW_UNTRAINED=1`.

---

## Εκπαίδευση μοντέλου

Τα datasets δεν περιλαμβάνονται στο repository. Κάθε script `prepare_*.py` αναφέρει στην κεφαλίδα του από πού κατεβαίνει η πηγή.

| Πηγή          | Άδεια        | Γλώσσα    | Tasks                          |
|---------------|--------------|-----------|--------------------------------|
| SentiMP-Gr    | CC BY-SA 4.0 | Ελληνικά  | sentiment                      |
| XED (el)      | CC BY 4.0    | Ελληνικά  | emotion, sentiment             |
| GoEmotions    | Apache 2.0   | Αγγλικά   | emotion, sentiment             |
| CODIPAD       | CC BY 4.0    | Αγγλικά   | distortion, distortion_types   |
| Reddit C-SSRS | CC BY 4.0    | Αγγλικά   | risk                           |

Ροή, από τον φάκελο `Python/`:

```bash
# 1. Προετοιμασία κάθε πηγής -> data/interim/
.venv/bin/python data/prepare_sentimp.py
.venv/bin/python data/prepare_xed.py
.venv/bin/python data/prepare_goemotions.py
.venv/bin/python data/prepare_codipad.py
.venv/bin/python data/prepare_cssrs.py

# 2. Μετάφραση των αγγλικών πηγών -> data/processed/ (θέλει GEMINI_API_KEY)
.venv/bin/python data/translate_gemini.py --dry-run
.venv/bin/python data/translate_gemini.py --limit 800

# 3. Συγχώνευση, έλεγχος ετικετών, split ανά group -> training/train.csv
.venv/bin/python data/merge_dataset.py

# 4. Εκπαίδευση -> mtl_model.pt και mtl_config.json
.venv/bin/python training/train_mtl.py --epochs 3 --batch-size 16
```

Σημειώσεις:

- Η μετάφραση συνεχίζει από εκεί που σταμάτησε αν διακοπεί.
- Το `merge_dataset.py` αφαιρεί ό,τι υπάρχει στο test set (`data/annotation/`), ώστε να μη μπει ποτέ στην εκπαίδευση. Οδηγίες σχολιασμού στο `data/annotation/guide.md`.
- Αν δεν υπάρχει `training/train.csv`, η εκπαίδευση χρησιμοποιεί το χειρόγραφο `training/dataset.csv`.
- Στο δωρεάν tier του Gemini τα δεδομένα μπορεί να χρησιμοποιηθούν από την Google. Χρησιμοποιείται μόνο για δημόσια datasets, ποτέ για καταγραφές πραγματικών χρηστών.

---

## REST API

Όλα τα endpoints επιστρέφουν JSON και απαιτούν συνδεδεμένο χρήστη. Τα `POST`, `PATCH` και `DELETE` απαιτούν το CSRF token στο header `X-CSRF-Token`.

| Μέθοδος              | Διαδρομή                               | Περιγραφή                                          |
|----------------------|----------------------------------------|----------------------------------------------------|
| GET                  | `/api/entries?page=&per_page=`         | Λίστα καταγραφών με σελιδοποίηση                   |
| POST                 | `/api/entries`                         | Νέα καταγραφή, επιστρέφει και μήνυμα ηρεμίας       |
| GET / PATCH / DELETE | `/api/entries/{id}`                    | Προβολή, επεξεργασία, διαγραφή                     |
| POST                 | `/api/sentiment/live`                  | Ζωντανή ανάλυση χωρίς αποθήκευση                   |
| GET                  | `/api/insights?unseen=1&limit=`        | Insights του χρήστη                                |
| POST                 | `/api/insights/{id}/read`              | Σήμανση ως αναγνωσμένο                             |
| GET                  | `/api/messages/today`                  | Το μήνυμα της ημέρας                               |
| POST                 | `/api/messages/{id}/seen`              | Σήμανση ως ειδωμένο                                |
| GET                  | `/api/summaries?from=&to=`             | Ημερήσιες περιλήψεις, έως 366 ημέρες               |
| GET                  | `/api/analytics/mood-trends?days=`     | Τάση διάθεσης                                      |
| GET                  | `/api/analytics/emotions?days=`        | Κατανομή συγκινήσεων                               |
| GET                  | `/api/analytics/weekly?weeks=`         | Εβδομαδιαίος μέσος όρος                            |
| GET                  | `/api/analytics/streak`                | Συνεχόμενες ημέρες καταγραφής                      |
| GET                  | `/api/analytics/monthly`               | Σύγκριση μηνών                                     |
| GET                  | `/api/analytics/entities?limit=`       | Συχνότερα πρόσωπα και γεγονότα                     |
| GET / PATCH          | `/api/profile`                         | Θέμα, ήχος, προτιμήσεις                            |

---

## Tests

Χωρίς εξαρτήσεις, μόνο PHP:

```bash
php tests/run.php            # όλα
php tests/run.php password   # μόνο όσα ταιριάζουν στο όνομα αρχείου ή test
```

Απαιτήσεις:

- Apache και MariaDB σε λειτουργία, με τα migrations και το `seed.sql` περασμένα.
- Ελεύθερη η θύρα του `AI_SERVICE_URL`. Τα tests ξεκινούν στη θέση της υπηρεσίας AI ένα ψεύτικο server (`tests/fake_ai.php`) για προβλέψιμα αποτελέσματα.
- Για διαφορετική διεύθυνση από το `APP_URL`: `TEST_BASE_URL=...`.

Τα tests χρησιμοποιούν τη βάση του `.env`. Δημιουργούν χρήστες `*@tests.invalid`, τους διαγράφουν στο τέλος και αδειάζουν το `storage/ratelimit`. Μόνο για τοπική ανάπτυξη.

---

## Συντήρηση

Το `bin/maintenance.php` διαγράφει οριστικά:

- λογαριασμούς 30 ημέρες μετά το αίτημα διαγραφής
- παλιά insights, ημερήσια μηνύματα και συσχετίσεις συναισθημάτων με οντότητες
- ληγμένα αρχεία του rate limit

Μία φορά την ημέρα από cron:

```cron
0 4 * * * /Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/wellness_journal/bin/maintenance.php
```

---

## Ασφάλεια και ιδιωτικότητα

- Μόνο ο φάκελος `public/` είναι προσβάσιμος από τον browser. Τα `.env`, `.git`, `storage/` κ.λπ. δεν σερβίρονται.
- Κωδικοί με `password_hash`, ελάχιστο μήκος 8 χαρακτήρες. Οι συνεδρίες ακυρώνονται όταν αλλάξει ο κωδικός.
- CSRF προστασία σε κάθε αίτημα που αλλάζει δεδομένα.
- Rate limiting στη σύνδεση, την εγγραφή, τις αλλαγές λογαριασμού και τη ζωντανή ανάλυση.
- Security headers (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, CSP).
- Η υπηρεσία AI ακούει μόνο στο `127.0.0.1`.
- Ο χρήστης μπορεί να εξαγάγει όλα τα δεδομένα του και να διαγράψει τον λογαριασμό του.
