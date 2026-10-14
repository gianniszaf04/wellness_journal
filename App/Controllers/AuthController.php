<?php 
declare(strict_types=1);
namespace App\Controllers;

use App\Models\User;
use App\Core\Request;

class AuthController {
    private  User $userModel;

    public function __construct() {
        $this->userModel=new User();
    }
    public function showLoginForm(): void {
        $error =$this->pullError();
        
        echo '<!doctype html>
        <html lang="el"><head><meta charset="utf-8"><title>Συνδεση</title></head>
        <body>
            <h1>Συνδεση</h1>
            ' . $error . '
            <form method="POST"  action= "'  .url('login') . '" > ' . csrf_field() . '
                <input type="email" name="email" placeholder="Email" required><br>
                <input type="password" name="password" placeholder="Κωδικος" required><br>
                <button type="submit">Συνδεση</button>
            </form>
            <a href= "'  .url('register') . '" >Εγγραφη</a>
        </body></html>    
        ';
    }

    public function showRegisterForm(): void {
        $error =$this->pullError();

        echo '<!doctype html>
        <html lang="el"><head><meta charset="utf-8"><title>Εγγραφη</title></head>
        <body>
        <h1>Εγγραφη</h1>
        ' . $error . '
        <form method="POST" action="'  .url('register') . '" >  ' . csrf_field() . '
        <input type="text" name="username" placeholder="Username" required><br>
        <input type="text" name="name" placeholder="Ονοματεπωνυμο" required><br>
        <input type="email" name="email" placeholder="Email" required><br>
        <input type="password" name="password" placeholder="Κωδικος" required><br>
        <button type="submit">Εγγραφη</button>
        </form>
            <a href="'  .url('register') . '" >Εγγραφη</a>
        </body></html>  
        ';
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

        if(strlen($password) <8) {
            $this->redirectWithError('/register','Ο κωδικός πρέπει να αποτελείται τουλάχιστον από 8 χαρακτήρες.');
            return;
        }

        if($this->userModel->emailExists($email)) {
            $this->redirectWithError('/register','Το email χρησιμοποιείται ήδη');
            return;
        }

        if ($this->userModel->usernameExists($username)) {
            $this->redirectWithError('/register','To username χρησιμοποιειται ήδη.');
            return;
        }

        $this->userModel->create($username,$name,$email,$password);
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

        $user = $this->userModel->findDbEmail($email);
        if($user === null || !password_verify($password, $user['password_hash'])) {
            $this->redirectWithError('/login' , 'Λαθος email ή κωδικός');
            return;
        }

        if(session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] =$user['id'];
        $_SESSION['username'] = $user['username'];

        \App\Middleware\RateLimit::clear();

        redirect('/dashboard');
    }

    public function logout(): void {
        if(session_status()=== PHP_SESSION_NONE) {
            session_start();
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

    private function pullError(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $error = $_SESSION['error'] ?? '';
        unset($_SESSION['error']);
        return $error !== '' ? '<p style="color:red;">' . e($error) . '</p>' : '';
    }
    
}