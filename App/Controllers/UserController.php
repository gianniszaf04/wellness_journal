<?php 
declare(strict_types=1);
namespace App\Controllers;

use App\Models\User;
use App\Models\UserProfile;
use App\Core\Request;
use App\Services\UserDataExport;

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
            view('entry/not-found', ['title' => 'Δεν βρέθηκε']);
            return ;
        }

        $profile = $this->profileModel->getOrCreate($userId);
        $created = strtotime((string) $user['created_at']);

        view('profile', [
            'title'           => 'Προφίλ',
            'scripts'         => ['api.js', 'profile.js'],
            'user'            => $user,
            'memberSince'     => 'τον ' . mb_substr(greek_month((int) date('n', $created)), 0, -1) . ' του ' . date('Y', $created),
            'pages'           => (new \App\Models\Entry())->countForUser($userId),
            'theme'           => (string) ($profile['theme_background'] ?? UserProfile::DEFAULT_THEME),
            'sound'           => (string) ($profile['sound'] ?? UserProfile::DEFAULT_SOUND),
            'passwordError'   => flash('password_error'),
            'passwordSuccess' => flash('password_success'),
            'deleteError'     => flash('error'),
        ]);
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

    // GET /account/export -> ληψη ολων των δεδομενων του χρηστη σε JSON
    public function exportData(): void {
        $userId = (int) current_user_id();
        $json = json_encode((new UserDataExport())->forUser($userId), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            error_log('UserController::exportData json error: ' . json_last_error_msg());
            http_response_code(500);
            echo 'Η εξαγωγή απέτυχε.';
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="wellness-journal-' . date('Y-m-d') . '.json"');
        header('Cache-Control: no-store');
        echo $json;
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
        $passwordError = User::passwordError($new);
        if ($passwordError !== null) {
            $this->flash('password_error', str_replace('Ο κωδικός', 'Ο νέος κωδικός', $passwordError));
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
}
