<?php 
declare(strict_types=1);
namespace App\Controllers;

use App\Models\Entry;
use App\Models\User;

class DashboardController {
    private  Entry $entryModel;
    private User $userModel;
    public function __construct() {
        $this->entryModel= new Entry();
        $this->userModel =  new User();
    }

    public function index(): void {
        $userId= (int)  current_user_id();
        $user = $this->userModel->findById($userId);
        $name = $user['name'] ?? 'χρήστη';

        $total = $this->entryModel->countForUser($userId);
        $recent=$this->entryModel->allForUser($userId, 5, 0);

        echo '<!doctype html>
        <html lang="el"><head><meta charset="utf-8"><title>Dashboard</title></head>
        <body>
        <h1>Καλως ηρθες, ' . e((string) $name) . '!</h1>
        <p>
            <a href=" ' . e(url('entry/editor')) . '">Νεα Καταγραφη</a> |
            <a href=" ' . e(url('entry/history')) . '">Ιstoriko</a> |
            <a href=" ' . e(url('profile')) . '" >Προφιλ</a>
            </p>
        <form method="Post" action=" ' . e(url('logout')) . ' " >  ' . csrf_field() . '
        <button type="submit">Αποσυνδεση</button>
        </form>
        <hr>
        <h2>Στατιστικα</h2>
        <p>Συνολο καταγραφων: <strong>' . $total . '</strong></p>
        <hr>
        <h2>Προσφατες καταγραφες </h2>';

        if(count($recent)=== 0 ) {
            echo '<p>Δεν υπαρχουν καταγραφες ακομα . <a href=" ' . e(url('entry/editor')) .'">Ξεκινα τωρα! </a></p>';

        } else {
            echo '<ul>';
            foreach ($recent as $entry) {
                $viewUrl = e(url('entry/' . $entry['id']));
                $date = e((string) $entry['entry_date']);
                $emotion = $entry['emotion_emoji'] ? e((string) $entry['emotion_emoji']) . '' : '' ;
                $preview = e(mb_substr((string) $entry['body'], 0, 60));
                echo '<li>' . $emotion . '<a href=" ' . $viewUrl  . ' ">' . $date . '</a> - ' . $preview . '...</li>'; 
            }
        echo '</ul>';
        }
    echo '</body></html>';
    }
}