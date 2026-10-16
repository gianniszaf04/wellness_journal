<?php
declare(strict_types=1);

// Τρεχει οσα αρχεια του database/migrations δεν εχουν τρεξει ακομα, με αλφαβητικη σειρα.
//   php database/migrate.php            τρεχει τα νεα
//   php database/migrate.php --status   δειχνει ποια εχουν τρεξει
// Ο wellness_user δεν εχει ALTER/CREATE, οποτε συνηθως:
//   MIGRATE_DB_USER=root MIGRATE_DB_PASS= php database/migrate.php
// Τα migrations να ξανατρεχουν χωρις λαθος (IF NOT EXISTS), γιατι το DDL δεν γυριζει πισω αν κοπει στη μεση.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$base = dirname(__DIR__) . DIRECTORY_SEPARATOR;
require $base . 'App/Core/Env.php';
\App\Core\Env::load($base . '.env');

$user = getenv('MIGRATE_DB_USER') ?: (string) getenv('DB_USER');
$pass = getenv('MIGRATE_DB_USER') ? (string) getenv('MIGRATE_DB_PASS') : (string) getenv('DB_PASS');
// το --status μονο διαβαζει, οποτε αρκει ο wellness_user (SELECT)
$statusOnly = in_array('--status', $argv, true);

try {
    $db = new PDO(
        'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    if (!$statusOnly) {
        $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            migration VARCHAR(255) PRIMARY KEY,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }
} catch (PDOException $e) {
    fwrite(STDERR, 'Σύνδεση με τη βάση απέτυχε: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

try {
    $applied = $db->query('SELECT migration FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // στο --status ο πινακας μπορει να μην υπαρχει ακομα: τοτε κανενα δεν εχει τρεξει
    if (!$statusOnly) {
        fwrite(STDERR, 'Ανάγνωση του schema_migrations απέτυχε: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    $applied = [];
}
$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files);

if ($statusOnly) {
    foreach ($files as $file) {
        $name = basename($file);
        echo (in_array($name, $applied, true) ? 'applied  ' : 'pending  ') . $name . PHP_EOL;
    }
    exit(0);
}

$pending = array_values(array_filter($files, fn (string $f): bool => !in_array(basename($f), $applied, true)));

if ($pending === []) {
    echo 'Δεν υπάρχουν νέα migrations.' . PHP_EOL;
    exit(0);
}

$record = $db->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');

foreach ($pending as $file) {
    $name = basename($file);

    try {
        foreach (statements($file) as $sql) {
            $db->exec($sql);
        }
        $record->execute([':migration' => $name]);
    } catch (PDOException $e) {
        fwrite(STDERR, "Απέτυχε το {$name}: " . $e->getMessage() . PHP_EOL);
        exit(1);
    }

    echo "applied  {$name}" . PHP_EOL;
}

// ενα statement ανα ';' στο τελος γραμμης, χωρις τα σχολια '--'
function statements(string $file): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($file));
    $parts = preg_split('/;\s*$/m', (string) $sql) ?: [];

    return array_values(array_filter(array_map('trim', $parts), fn (string $s): bool => $s !== ''));
}
