 Ημερολόγιο Ευεξίας 

 Ένα έξυπνο, διαδικτυακό ημερολόγιο αυτοβελτίωσης και ψυχολογικής υποστήριξης. Το ημερολόγιο Ευεξίας επιτρέπει στους χρήστες να καταγράφουν την καθημερινότητα τους, ενώ αξιοποιεί τεχνητή νοημοσύνη για ζωντανή ανάλυση συναισθημάτων, παρέχοντας άμεσα μηχανισμούς ηρεμίας και αναλυτικά στατιστικά της ψυχολογικής τους  κατάστασης.

 Βασικές λειτουργίες
 * καταγραφή σκέψεων με φιλικό user interface
 * ζωντανή ανάλυση συναισθήματος. Αξιολόγηση  κειμένου σε πραγματικό χρόνο και αναγνώριση αρνητικών μοτίβων.
 * εργαλεία ηρεμίας. Αυτόματη εμφάνιση χαλαρωτικών Μηνυμάτων, ήχων, η αλλαγή του φόντου όταν ανιχνεύεται έντονο στρες και θλίψη.
 * στατιστικά και γραφήματα. Οπτικοποίηση της ψυχολογικής εξέλιξης του χρήστη μέσω διαδραστικών διαγραμμάτων.
 * Πλήρης ασφάλεια δεδομένων του χρήστη. 

 Τεχνολογικά ζητήματα.

 === FRONTEND ===
 # HTML5/ CSS3
 # VANILIA JAVASCRIPT
 # REST API
 # CHART.JS 

 === BACKEND ===
 # PHP 8+ 
 # MARIADB 10.4+ (XAMPP)
 # FRONT  CONTROLLER PATTERN

 == ΒΑΣΗ ΔΕΔΟΜΕΝΩΝ ==
 Πρώτη εγκατάσταση (με root):
   mysql -u root < database/create_user.sql   (βάλε πρώτα τον κωδικό του DB_PASS)
   mysql -u root < database/schema.sql
   mysql -u root < database/seed.sql
 Το schema.sql ξανατρέχει με ασφάλεια. Αλλαγές σε υπάρχουσα βάση γίνονται με migrations:
   MIGRATE_DB_USER=root MIGRATE_DB_PASS= php database/migrate.php            (τρέχει τα νέα)
   MIGRATE_DB_USER=root MIGRATE_DB_PASS= php database/migrate.php --status   (τι έχει τρέξει)

 == API (JSON, μόνο για συνδεδεμένους, CSRF στο X-CSRF-Token για POST/PATCH/DELETE) ==
   GET    /api/entries?page=&per_page=        λίστα καταγραφών με σελίδες
   POST   /api/entries                        νέα καταγραφή (επιστρέφει και το μήνυμα ηρεμίας)
   GET|PATCH|DELETE /api/entries/{id}
   POST   /api/sentiment/live                 ζωντανή ανάλυση, δεν αποθηκεύει
   GET    /api/insights?unseen=1&limit=       POST /api/insights/{id}/read
   GET    /api/messages/today                 POST /api/messages/{id}/seen
   GET    /api/summaries?from=&to=            ημερήσιες περιλήψεις (έως 366 μέρες)
   GET    /api/analytics/mood-trends?days=    /emotions?days=  /weekly?weeks=  /streak  /monthly  /entities?limit=
   GET|PATCH /api/profile                     theme, sound, preferences

 == TESTS ==
 Χωρίς εξαρτήσεις, μόνο PHP:
   php tests/run.php              όλα
   php tests/run.php password     μόνο όσα ταιριάζουν στο αρχείο ή στο όνομα
 Θέλουν τον Apache και τη MariaDB να τρέχουν, τα migrations και το seed.sql περασμένα, και ελεύθερη
 τη θύρα του AI_SERVICE_URL: στη θέση του AI ξεκινούν ένα ψεύτικο (tests/fake_ai.php) για προβλέψιμα αποτελέσματα.
 Μιλούν στη βάση του .env: φτιάχνουν χρήστες *@tests.invalid, τους σβήνουν στο τέλος, και αδειάζουν
 το storage/ratelimit. Μόνο για τοπικό dev. Άλλη διεύθυνση από το APP_URL: TEST_BASE_URL=...

 == ΣΥΝΤΗΡΗΣΗ ==
 Το bin/maintenance.php σβήνει λογαριασμούς μετά τις 30 μέρες διαγραφής, παλιά insights,
 daily messages και relations, και τα ληγμένα αρχεία του rate limit. Μια φορά τη μέρα από cron, π.χ.:
   0 4 * * * /Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/wellness_journal/bin/maintenance.php

 == AI == 
 ...


 Αρχιτεκτονική φακέλου 
 wellness diary/
   --app/
   --bin/
   --public/
   --routes/
   --config/
   --database/
   --python/
   --storage/
   --tests/

