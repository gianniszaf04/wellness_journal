-- Ο χρηστης της εφαρμογης. Τρεχει με root, μια φορα, πριν το schema.sql.
-- Βαλε τον ιδιο κωδικο με το DB_PASS του .env. Αν ο χρηστης υπαρχει ηδη, ο κωδικος του δεν αλλαζει.
CREATE USER IF NOT EXISTS 'wellness_user'@'localhost' IDENTIFIED BY 'CHANGE_ME';

GRANT SELECT ,INSERT ,UPDATE , DELETE ON wellness_journal.* TO 'wellness_user'@'localhost';
FLUSH PRIVILEGES;
