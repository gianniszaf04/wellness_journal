<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/Config/bootstrap.php';

use App\Core\Crypt;
use App\Models\ThoughtRecord;

if (in_array('--key', $argv, true)) {
    echo Crypt::generateKey() . PHP_EOL;
    exit(0);
}

$apply = in_array('--apply', $argv, true);
$tables = [
    'entries' => ['columns' => ['body'], 'keep' => ', updated_at = updated_at'],
    'thought_records' => ['columns' => ThoughtRecord::ENCRYPTED, 'keep' => ''],
];

try {
    Crypt::decrypt(Crypt::encrypt('έλεγχος'));
    $db = (new Database())->getConnection();
    $db->beginTransaction();

    foreach ($tables as $table => $config) {
        $columns = $config['columns'];
        $rows = $db->query('SELECT id, ' . implode(', ', $columns) . " FROM {$table}")->fetchAll(PDO::FETCH_ASSOC);
        $pending = 0;

        foreach ($rows as $row) {
            $changes = [];
            foreach ($columns as $column) {
                if ($row[$column] !== null && !Crypt::isEncrypted((string) $row[$column])) {
                    $changes[$column] = Crypt::encrypt((string) $row[$column]);
                }
            }
            if ($changes === []) {
                continue;
            }
            $pending++;
            if (!$apply) {
                continue;
            }

            $set = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($changes)));
            $stmt = $db->prepare("UPDATE {$table} SET {$set}{$config['keep']} WHERE id = :id");
            $stmt->execute($changes + ['id' => $row['id']]);
        }

        echo "{$table}: " . count($rows) . ' γραμμές, ' . $pending . ($apply ? ' κρυπτογραφήθηκαν' : ' χωρίς κρυπτογράφηση') . PHP_EOL;
    }

    $db->commit();
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Απέτυχε, καμία γραμμή δεν άλλαξε: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!$apply) {
    echo 'Δοκιμή μόνο. Για να γραφτούν οι αλλαγές: php bin/encrypt_texts.php --apply' . PHP_EOL;
}
