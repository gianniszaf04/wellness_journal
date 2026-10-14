<?php
declare(strict_types=1);

namespace App\Middleware;

class RequireGuest
{
    public function handle(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if(isset($_SESSION['user_id'])) {
            header('Location:'.url('dashboard'));
            exit;
        }
    return true;
    }
}