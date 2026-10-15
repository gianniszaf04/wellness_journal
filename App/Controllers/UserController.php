<?php 
declare(strict_types=1);
namespace App\Controllers;

use App\Models\User;
use App\Models\UserProfile;
use App\Core\Request;

class UserController {
    private const MIN_PASSWORD_LENGTH = 8;

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
        $passwordMessage = $this->pullFlash('password_error') . $this->pullFlash('password_success', 'green');

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
        <h2>Αλλαγη κωδικου</h2>
        ' . $passwordMessage . '
        <p>Μετα την αλλαγη θα αποσυνδεθεις απο ολες τις αλλες συσκευες.</p>
        <form method="POST" action="' . e(url('account/password')) . '">' . csrf_field() . '
            <input type="password" name="current_password" placeholder="Τρεχων κωδικος" required><br>
            <input type="password" name="new_password" placeholder="Νεος κωδικος (τουλαχιστον ' . self::MIN_PASSWORD_LENGTH . ' χαρακτηρες)" required><br>
            <input type="password" name="new_password_confirmation" placeholder="Επαναληψη νεου κωδικου" required><br>
            <button type="submit">Αλλαγη κωδικου</button>
        </form>
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

    public function changePassword(): void {
        $userId = (int) current_user_id();
        $request = new Request();
        $current = (string) $request->input('current_password', '');
        $new = (string) $request->input('new_password', '');
        $confirmation = (string) $request->input('new_password_confirmation', '');
        $user = $this->userModel->findById($userId);

        if ($user === null || $current === '' || !password_verify($current, $user['password_hash'])) {
            $this->flash('password_error', 'Λάθος τρέχων κωδικός.');
            redirect('/profile');
        }
        // ιδιος κανονας με την εγγραφη
        if (strlen($new) < self::MIN_PASSWORD_LENGTH) {
            $this->flash('password_error', 'Ο νέος κωδικός πρέπει να έχει τουλάχιστον ' . self::MIN_PASSWORD_LENGTH . ' χαρακτήρες.');
            redirect('/profile');
        }
        if (!hash_equals($new, $confirmation)) {
            $this->flash('password_error', 'Οι δύο νέοι κωδικοί δεν ταιριάζουν.');
            redirect('/profile');
        }
        if (password_verify($new, $user['password_hash'])) {
            $this->flash('password_error', 'Ο νέος κωδικός είναι ίδιος με τον τρέχοντα.');
            redirect('/profile');
        }

        if (!$this->userModel->updatePassword($userId, $new)) {
            $this->flash('password_error', 'Η αλλαγή δεν ολοκληρώθηκε. Δοκίμασε ξανά.');
            redirect('/profile');
        }

        // νεο αποτυπωμα: αυτο το session μενει, τα υπολοιπα ακυρωνονται στο RequireLogin
        $updated = $this->userModel->findById($userId);
        session_regenerate_id(true);
        $_SESSION['password_fp'] = User::passwordFingerprint((string) ($updated['password_hash'] ?? ''));
        $this->flash('password_success', 'Ο κωδικός άλλαξε.');
        redirect('/profile');
    }

    private function flash(string $key, string $message): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION[$key] = $message;
    }

    private function pullFlash(string $key, string $color = 'red'): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $message = $_SESSION[$key] ?? '';
        unset($_SESSION[$key]);
        return $message !== '' ? '<p style="color:' . $color . ';">' . e($message) . '</p>' : '';
    }
}