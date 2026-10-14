<?php
declare(strict_types=1);

namespace   App\Controllers;

class HomeController{
    public function index(): void {
        echo '<!doctype html>
            <html lang="el">
            <head>
            <meta charset="utf-8">
            <title>Ημερολογιο Ευεξιας</title>
            </head>
            <body>
            <h1>Καλως Ηρθες</h1>
            <p> Το backend δουλευει. </p>
            </body>
            </html>';
    }
}

