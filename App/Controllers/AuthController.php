<?php 
declare(strict_types=1);
namespace App\Controllers;

use App\Models\User;
use App\Models\VerificationCode;
use App\Services\Mailer;
use App\Core\Request;

class AuthController {
    // ποσα δευτερολεπτα εχει ο χρηστης να επιλεξει επαναφορα μετα το login
    private const RESTORE_WINDOW_SEC = 600;
    private const VERIFY_WINDOW_SEC = 1800;
    // bcrypt cost 10 (ιδιο με τα πραγματικα) απο τυχαιο κειμενο που δεν ταιριαζει με κανεναν κωδικο
    private const DUMMY_HASH = '$2y$10$.BpNrpLU0vTQyoirwbLihOYC97Jp1TB7I1SJ92Fz3IRQD1K/H5COe';
    private const RESET_SENT_MESSAGE = 'Αν υπάρχει λογαριασμός με αυτό το email, σου στείλαμε κωδικό. Μπορείς να ζητήσεις νέο κάθε ένα λεπτό.';

    private  User $userModel;
    private VerificationCode $codes;

    public function __construct() {
        $this->userModel=new User();
        $this->codes = new VerificationCode();
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
        $this->userModel->purgeUnverified();

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
        $user = $this->userModel->findDbEmail($email);
        if ($user === null) {
            $_SESSION['success'] = 'Ο λογαριασμός σου δημιουργήθηκε. Συνδέσου για να ξεκινήσεις.';
            redirect('/login');
        }

        $this->beginVerification($user);
        redirect('/verify-email');
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
        $this->userModel->purgeUnverified();

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

        if (empty($user['email_verified_at'])) {
            \App\Middleware\RateLimit::clear();
            $this->beginVerification($user);
            redirect('/verify-email');
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

    public function showVerify(): void {
        $user = $this->pendingVerifyUser();

        if ($user === null) {
            redirect('/login');
        }

        view('auth/verify', [
            'title' => 'Επιβεβαίωση email',
            'email' => (string) $user['email'],
            'ttlMin' => VerificationCode::CODE_TTL_MIN,
        ], 'guest');
    }

    public function verify(): void {
        $user = $this->pendingVerifyUser();
        if ($user === null) {
            $this->redirectWithError('/login', 'Η επιβεβαίωση έληξε. Συνδέσου ξανά για να λάβεις νέο κωδικό.');
            return;
        }

        $code = $this->codeFromInput(new Request());
        if ($code === null) {
            $this->redirectWithError('/verify-email', 'Ο κωδικός έχει 6 ψηφία.');
            return;
        }

        $userId = (int) $user['id'];
        $result = $this->codes->check($userId, VerificationCode::PURPOSE_EMAIL, $code);

        if ($result === 'expired') {
            $this->redirectWithError('/verify-email', 'Ο κωδικός έληξε ή έγιναν πολλές λάθος προσπάθειες. Ζήτησε νέο κωδικό.');
            return;
        }
        if ($result !== 'ok') {
            $this->redirectWithError('/verify-email', 'Λάθος κωδικός. Δοκίμασε ξανά.');
            return;
        }
        if (!$this->userModel->markEmailVerified($userId)) {
            $this->redirectWithError('/verify-email', 'Η επιβεβαίωση δεν ολοκληρώθηκε. Δοκίμασε ξανά.');
            return;
        }

        $this->codes->clear($userId, VerificationCode::PURPOSE_EMAIL);
        $this->clearPendingVerify();
        $this->loginUser($user);
        $_SESSION['success'] = 'Το email σου επιβεβαιώθηκε. Καλώς ήρθες!';
        redirect('/dashboard');
    }

    public function resendVerification(): void {
        $user = $this->pendingVerifyUser();
        if ($user === null) {
            $this->redirectWithError('/login', 'Η επιβεβαίωση έληξε. Συνδέσου ξανά για να λάβεις νέο κωδικό.');
            return;
        }

        $wait = $this->codes->secondsUntilResend((int) $user['id'], VerificationCode::PURPOSE_EMAIL);
        if ($wait > 0) {
            $this->redirectWithError('/verify-email', "Περίμενε {$wait} δευτερόλεπτα πριν ζητήσεις νέο κωδικό.");
            return;
        }

        if (!$this->sendCode($user, VerificationCode::PURPOSE_EMAIL)) {
            $this->redirectWithError('/verify-email', 'Δεν μπορέσαμε να στείλουμε το email. Δοκίμασε ξανά σε λίγο.');
            return;
        }

        $_SESSION['verify_until'] = time() + self::VERIFY_WINDOW_SEC;
        $_SESSION['success'] = 'Σου στείλαμε νέο κωδικό.';
        redirect('/verify-email');
    }

    private function beginVerification(array $user): void {
        session_regenerate_id(true);
        $_SESSION['verify_user_id'] = (int) $user['id'];
        $_SESSION['verify_until'] = time() + self::VERIFY_WINDOW_SEC;

        if ($this->codes->secondsUntilResend((int) $user['id'], VerificationCode::PURPOSE_EMAIL) > 0) {
            return;
        }
        if (!$this->sendCode($user, VerificationCode::PURPOSE_EMAIL)) {
            $_SESSION['error'] = 'Δεν μπορέσαμε να στείλουμε το email. Πάτησε «Στείλε νέο κωδικό» σε λίγο.';
        }
    }

    public function showForgot(): void {
        view('auth/forgot', ['title' => 'Ξέχασα τον κωδικό μου'], 'guest');
    }

    public function forgot(): void {
        $email = trim((string) (new Request())->input('email', ''));

        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->redirectWithError('/password/forgot', 'Το email δεν είναι έγκυρο.');
            return;
        }

        $user = $this->userModel->findDbEmail($email);
        if ($user !== null && (int) $user['status'] !== 0
            && $this->codes->secondsUntilResend((int) $user['id'], VerificationCode::PURPOSE_PASSWORD) === 0) {
            $this->sendCode($user, VerificationCode::PURPOSE_PASSWORD);
        }

        $_SESSION['reset_email'] = $email;
        $_SESSION['reset_until'] = time() + self::VERIFY_WINDOW_SEC;
        $_SESSION['info'] = self::RESET_SENT_MESSAGE;
        redirect('/password/reset');
    }

    public function showReset(): void {
        $email = $this->pendingResetEmail();

        if ($email === null) {
            redirect('/password/forgot');
        }

        view('auth/reset', [
            'title' => 'Νέος κωδικός',
            'email' => $email,
            'ttlMin' => VerificationCode::CODE_TTL_MIN,
        ], 'guest');
    }

    public function reset(): void {
        $email = $this->pendingResetEmail();
        if ($email === null) {
            $this->redirectWithError('/password/forgot', 'Η διαδικασία έληξε. Ζήτησε νέο κωδικό.');
            return;
        }

        $request = new Request();
        $code = $this->codeFromInput($request);
        $password = (string) $request->input('password', '');

        if ($code === null) {
            $this->redirectWithError('/password/reset', 'Ο κωδικός έχει 6 ψηφία.');
            return;
        }
        if ($password !== (string) $request->input('password_confirmation', '')) {
            $this->redirectWithError('/password/reset', 'Οι δύο κωδικοί δεν είναι ίδιοι.');
            return;
        }
        $passwordError = User::passwordError($password);
        if ($passwordError !== null) {
            $this->redirectWithError('/password/reset', $passwordError);
            return;
        }

        $user = $this->userModel->findDbEmail($email);
        $result = $user !== null && (int) $user['status'] !== 0
            ? $this->codes->check((int) $user['id'], VerificationCode::PURPOSE_PASSWORD, $code)
            : 'expired';

        if ($result !== 'ok') {
            $this->redirectWithError('/password/reset', 'Ο κωδικός είναι λάθος ή έχει λήξει. Δοκίμασε ξανά ή ζήτησε νέο κωδικό.');
            return;
        }

        $userId = (int) $user['id'];
        if (!$this->userModel->updatePassword($userId, $password)) {
            $this->redirectWithError('/password/reset', 'Η αλλαγή δεν ολοκληρώθηκε. Δοκίμασε ξανά.');
            return;
        }

        $this->userModel->markEmailVerified($userId);
        $this->codes->clear($userId, VerificationCode::PURPOSE_PASSWORD);
        $this->codes->clear($userId, VerificationCode::PURPOSE_EMAIL);
        $this->clearPendingReset();
        $_SESSION['success'] = 'Ο κωδικός σου άλλαξε. Συνδέσου με τον νέο κωδικό.';
        redirect('/login');
    }

    private function sendCode(array $user, string $purpose): bool {
        $userId = (int) $user['id'];
        $code = $this->codes->issue($userId, $purpose);

        if ($purpose === VerificationCode::PURPOSE_PASSWORD) {
            $subject = "Κωδικός αλλαγής κωδικού: {$code}";
            $intro = "Ζητήθηκε αλλαγή κωδικού για τον λογαριασμό σου στο Wellness Journal. Ο κωδικός είναι:";
            $ignore = 'Αν δεν το ζήτησες εσύ, αγνόησε αυτό το email. Ο κωδικός σου δεν αλλάζει.';
        } else {
            $subject = "Κωδικός επιβεβαίωσης: {$code}";
            $intro = 'Ο κωδικός επιβεβαίωσης για το Wellness Journal είναι:';
            $ignore = 'Αν δεν έκανες εσύ εγγραφή, αγνόησε αυτό το email.';
        }

        $body = 'Γεια σου ' . $user['name'] . ",\n\n"
            . "{$intro}\n\n"
            . "    {$code}\n\n"
            . 'Ισχύει για ' . VerificationCode::CODE_TTL_MIN . " λεπτά.\n"
            . "{$ignore}\n";

        if (!(new Mailer())->send((string) $user['email'], $subject, $body)) {
            $this->codes->clear($userId, $purpose);
            return false;
        }
        return true;
    }

    private function codeFromInput(Request $request): ?string {
        $code = preg_replace('/\s+/', '', (string) $request->input('code', ''));

        return is_string($code) && preg_match('/^\d{6}$/', $code) ? $code : null;
    }

    private function pendingResetEmail(): ?string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['reset_email'], $_SESSION['reset_until']) || time() > (int) $_SESSION['reset_until']) {
            $this->clearPendingReset();
            return null;
        }
        return (string) $_SESSION['reset_email'];
    }

    private function clearPendingReset(): void {
        unset($_SESSION['reset_email'], $_SESSION['reset_until']);
    }

    private function pendingVerifyUser(): ?array {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['verify_user_id'], $_SESSION['verify_until']) || time() > (int) $_SESSION['verify_until']) {
            $this->clearPendingVerify();
            return null;
        }

        $user = $this->userModel->findById((int) $_SESSION['verify_user_id']);
        if ($user === null || !empty($user['email_verified_at']) || (int) $user['status'] === 0) {
            $this->clearPendingVerify();
            return null;
        }
        return $user;
    }

    private function clearPendingVerify(): void {
        unset($_SESSION['verify_user_id'], $_SESSION['verify_until']);
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