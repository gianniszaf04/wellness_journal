<?php 
declare(strict_types=1);
namespace App\Controllers;

use App\Models\User;
use App\Models\UserProfile;
use App\Core\Request;

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
        $error = $this->pullFlash('error');

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
        <hr>
        <h2>Διαγραφη λογαριασμου</h2>
        ' . $error . '
        <p>Αν διαγραψεις τον λογαριασμο σου:</p>
        <ul>
            <li>θα αποσυνδεθεις αμεσως και δεν θα μπορεις να τον χρησιμοποιεις,</li>
            <li>μετα απο <strong>' . User::DELETION_GRACE_DAYS . ' ημερες</strong> ο λογαριασμος και <strong>ολες οι καταγραφες σου</strong> θα σβηστουν οριστικα,</li>
            <li>μεχρι τοτε μπορεις να τον επαναφερεις συνδεομενος ξανα με το email και τον κωδικο σου.</li>
        </ul>
        <form method="POST" action="' . e(url('account/delete')) . '">' . csrf_field() . '
            <input type="password" name="password" placeholder="Κωδικος για επιβεβαιωση" required><br>
            <button type="submit">Διαγραφη λογαριασμου</button>
        </form>
        </body></html>
        ';
    }

    public function deleteAccount(): void {
        $userId = (int) current_user_id();
        $password = (string) (new Request())->input('password', '');
        $user = $this->userModel->findById($userId);

        if ($user === null || $password === '' || !password_verify($password, $user['password_hash'])) {
            $this->flash('error', 'Λάθος κωδικός. Ο λογαριασμός δεν διαγράφηκε.');
            redirect('/profile');
        }

        if (!$this->userModel->requestDeletion($userId)) {
            $this->flash('error', 'Η διαγραφή δεν ολοκληρώθηκε. Δοκίμασε ξανά.');
            redirect('/profile');
        }

        $date = $this->userModel->deletionDate($userId);
        $dateText = $date !== null ? date('d/m/Y', strtotime($date)) : '';

        // αποσυνδεση: αδειαζουμε το session και αλλαζουμε id, κραταμε μονο το μηνυμα για τη σελιδα συνδεσης
        $_SESSION = [];
        session_regenerate_id(true);
        $this->flash('info', 'Ο λογαριασμός σου θα διαγραφεί οριστικά στις ' . $dateText
            . '. Αν αλλάξεις γνώμη, συνδέσου μέχρι τότε για να τον επαναφέρεις.');
        redirect('/login');
    }

    private function flash(string $key, string $message): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION[$key] = $message;
    }

    private function pullFlash(string $key): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $message = $_SESSION[$key] ?? '';
        unset($_SESSION[$key]);
        return $message !== '' ? '<p style="color:red;">' . e($message) . '</p>' : '';
    }
}