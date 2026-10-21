<?php 
declare(strict_types=1);
namespace App\Controllers;

use App\Models\User;
use App\Core\Request;

class AuthController {
    // ποσα δευτερολεπτα εχει ο χρηστης να επιλεξει επαναφορα μετα το login
    private const RESTORE_WINDOW_SEC = 600;
    // bcrypt cost 10 (ιδιο με τα πραγματικα) απο τυχαιο κειμενο που δεν ταιριαζει με κανεναν κωδικο
    private const DUMMY_HASH = '$2y$10$.BpNrpLU0vTQyoirwbLihOYC97Jp1TB7I1SJ92Fz3IRQD1K/H5COe';

    private  User $userModel;

    public function __construct() {
        $this->userModel=new User();
    }
    public function showLoginForm(): void {
        view('auth/login', ['title' => 'Σύνδεση'], 'guest');
    }

    public function showRegisterForm(): void {
        view('auth/register', ['title' => 'Εγγραφή'], 'guest');
    }

    public function register(): void {
        $request = new  Request();

        $username=trim ($request->input('username',''));
        $name=trim($request->input('name',''));
        $email=trim($request->input('email',''));
        $password=$request->input('password','');

        if($username ==='' || $name ==='' || $email === '' || $password === '') {
            $this->redirectWithError('/register','Ολα τα πεδία είναι υποχρεωτικά.');
            return;
        }

        if (mb_strlen($username) < 3 || mb_strlen($username) > 50) {
            $this->redirectWithError('/register', 'Το username πρέπει να έχει από 3 έως 50 χαρακτήρες.');
            return;
        }

        if (mb_strlen($name) > 70) {
            $this->redirectWithError('/register', 'Το ονοματεπώνυμο μπορεί να έχει έως 70 χαρακτήρες.');
            return;
        }

        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->redirectWithError('/register', 'Το email δεν είναι έγκυρο.');
            return;
        }

        $passwordError = User::passwordError($password);
        if ($passwordError !== null) {
            $this->redirectWithError('/register', $passwordError);
            return;
        }

        // ελευθερωνει email/username λογαριασμων που εληξαν
        $this->userModel->purgeExpiredDeletions();

        if($this->userModel->emailExists($email)) {
            $this->redirectWithError('/register','Το email χρησιμοποιείται ήδη');
            return;
        }

        if ($this->userModel->usernameExists($username)) {
            $this->redirectWithError('/register','To username χρησιμοποιειται ήδη.');
            return;
        }

        if (!$this->userModel->create($username,$name,$email,$password)) {
            $this->redirectWithError('/register', 'Η εγγραφή δεν ολοκληρώθηκε. Δοκίμασε ξανά.');
            return;
        }
        $_SESSION['success'] = 'Ο λογαριασμός σου δημιουργήθηκε. Συνδέσου για να ξεκινήσεις.';
        redirect('/login');
    }

    public function login(): void {
        $request= new Request();

        $email=trim($request->input('email',''));
        $password=$request->input('password' , '');

        if($email === '' || $password === '') {
            $this->redirectWithError('/login', 'Συμπλήρωσε email και κωδίκο');
            return;
        }

        // πρωτα σβηνουμε οσους ληξαν, ωστε να μη γινεται επαναφορα μετα τις 30 μερες
        $this->userModel->purgeExpiredDeletions();

        $user = $this->userModel->findDbEmail($email);
        // και οταν δεν υπαρχει το email τρεχει password_verify, ωστε ο χρονος να μη δειχνει ποια emails υπαρχουν
        $hash = $user['password_hash'] ?? self::DUMMY_HASH;
        if(!password_verify($password, $hash) || $user === null) {
            $this->redirectWithError('/login' , 'Λαθος email ή κωδικός');
            return;
        }

        // ελεγχος μετα τον κωδικο, για να μη φαινεται σε τριτους ποιοι λογαριασμοι υπαρχουν
        if ((int) $user['status'] === 0) {
            $this->redirectWithError('/login', 'Ο λογαριασμός σου είναι απενεργοποιημένος.');
            return;
        }

        if(session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // λογαριασμος σε αναμονη διαγραφης: δεν συνδεεται, ρωταμε πρωτα αν θελει επαναφορα
        if (!empty($user['deletion_requested_at'])) {
            session_regenerate_id(true);
            $_SESSION['restore_user_id'] = (int) $user['id'];
            $_SESSION['restore_until'] = time() + self::RESTORE_WINDOW_SEC;
            \App\Middleware\RateLimit::clear();
            redirect('/account/restore');
        }

        $this->loginUser($user);
        redirect('/dashboard');
    }

    public function showRestore(): void {
        $userId = $this->pendingRestoreUserId();
        $date = $userId !== null ? $this->userModel->deletionDate($userId) : null;

        if ($date === null) {
            redirect('/login');
        }

        view('auth/restore', ['title' => 'Επαναφορά λογαριασμού', 'deletionDate' => (string) $date], 'guest');
    }

    public function restore(): void {
        $userId = $this->pendingRestoreUserId();
        $user = $userId !== null ? $this->userModel->findById($userId) : null;

        if ($user === null || !$this->userModel->cancelDeletion($userId)) {
            $this->clearPendingRestore();
            $this->redirectWithError('/login', 'Η επαναφορά δεν ολοκληρώθηκε. Συνδέσου ξανά.');
            return;
        }

        $this->clearPendingRestore();
        $this->loginUser($user);
        redirect('/dashboard');
    }

    public function cancelRestore(): void {
        $userId = $this->pendingRestoreUserId();
        $date = $userId !== null ? $this->userModel->deletionDate($userId) : null;
        $this->clearPendingRestore();

        if ($date !== null) {
            $_SESSION['info'] = 'Η διαγραφή συνεχίζεται. Ο λογαριασμός σου θα διαγραφεί οριστικά στις '
                . date('d/m/Y', strtotime($date)) . '.';
        }
        redirect('/login');
    }

    private function loginUser(array $user): void {
        session_regenerate_id(true);
        $_SESSION['user_id'] =$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['password_fp'] = User::passwordFingerprint((string) $user['password_hash']);

        \App\Middleware\RateLimit::clear();
    }

    // ο χρηστης που εδωσε σωστο κωδικο αλλα ο λογαριασμος του περιμενει διαγραφη (ισχυει για λιγα λεπτα)
    private function pendingRestoreUserId(): ?int {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['restore_user_id'], $_SESSION['restore_until']) || time() > (int) $_SESSION['restore_until']) {
            $this->clearPendingRestore();
            return null;
        }
        return (int) $_SESSION['restore_user_id'];
    }

    private function clearPendingRestore(): void {
        unset($_SESSION['restore_user_id'], $_SESSION['restore_until']);
    }

    public function logout(): void {
        if(session_status()=== PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION = [];

        // σβηνουμε και το cookie απο τον browser, με τις ιδιες ρυθμισεις που ειχε οταν δημιουργηθηκε
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }

        session_destroy();
        redirect('/login');
        
    }

    private function redirectWithError(string $path, string $message ): void {
        if(session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['error']=$message;
        redirect($path);

    }

}