<?php
declare(strict_types=1);

namespace   App\Controllers;

class HomeController{
    public function index(): void {
        view('home', [
            'title'    => 'Αρχική',
            'loggedIn' => current_user_id() !== null,
        ], 'guest');
    }
}
