<?php
declare(strict_types=1);
namespace App\Controllers;

class AnalyticsController {

    // προσωρινη σελιδα - τα γραφηματα ερχονται την εβδομαδα 4
    public function index(): void {
        echo '<!doctype html>
        <html lang="el"><head><meta charset="utf-8"><title>Στατιστικα</title></head>
        <body>
        <h1>Στατιστικα</h1>
        <p>Τα γραφηματα της διαθεσης σου ερχονται συντομα.</p>
        <p><a href="' . e(url('dashboard')) . '">Πισω στο Dashboard</a></p>
        </body></html>';
    }
}
