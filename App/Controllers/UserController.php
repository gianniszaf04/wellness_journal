<?php 
declare(strict_types=1);
namespace App\Controllers;

use App\Models\User;
use App\Models\UserProfile;

class UserController {
    private User $userModel;
    private UserProfile $profileModel;

    public function __construct() {
        $this->userModel = new User();
        $this->profileModel = new UserProfile();
    }
    public function  showProfile(): void {
        $userId = (int) current_user_id();
        $user = $this->userModel->findById($userId);

        if ($user === null ) {
            http_response_code(404);
            echo '<!doctype html><html lang="el"><head><meta charset="utf-8"><title>Σφάλμα</title></head>
            <body><h1>Ο χρήστης δνε βρέθηκε</h1></body></html>';
            return ;
        }

        $profile =$this->profileModel->getOrCreate($userId);

        echo '<!doctype html>
        <html lang="el"><head><meta charset="utf-8"><title>Προφίλ</title></head>
        <body>
            <h1>Το προφίλ μου</h1>
            <p><a href="' . e(url('dashboard')) . '"> Πισω στο Dashboard</a></p>
            <ul>
                <li><strong>Username:</strong> ' . e((string) $user['username']) . '</li>
                <li><strong>Ονοματεπωνυμο:</strong> ' . e((string) $user['name']) . '</li>
                <li><strong>email:</strong> ' . e((string) $user['email']) . '</li>
                <li><strong>Μελος απο:</strong> ' . e((string) $user['created_at']) . '</li>
        </ul>
        <h2>Προτιμησεις</h2>
        <ul>
            <li><strong>Θέμα:</strong> ' . e((string) ($profile['theme_background'] ?? 'default_theme')) . '</li>
            <li><strong>Ηχος:</strong> ' . e((string) ($profile['sound'] ?? 'none')) . '</li>
        </ul>
        </body></html>
        ';
    }
}