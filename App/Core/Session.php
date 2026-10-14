<?php 
declare(strict_types=1);
namespace App\Core;

class Session{
    public static function configure(): void {
        if(session_status() !== PHP_SESSION_NONE) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '' ) === '443');

        ini_set('session.use_strict_mode' , '1');
        ini_set('session.use_only_cookies' , '1');
        ini_set('session.cookie_httponly' , '1');
        ini_set('session.cookie_samesite' , 'Lax');
        ini_set('session.cookie_secure' , $isHttps ? '1' : '0');
    }
}