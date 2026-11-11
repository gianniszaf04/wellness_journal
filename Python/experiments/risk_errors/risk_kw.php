<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/App/Services/CrisisSupport.php';

use App\Services\CrisisSupport;

$rows = json_decode((string) stream_get_contents(STDIN), true);
$out = [];
foreach ($rows as $row) {
    $out[$row['id']] = CrisisSupport::mentionsSelfHarm($row['text']);
}
echo json_encode($out);
