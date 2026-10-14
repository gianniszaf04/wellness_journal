<?php 
declare(strict_types=1);

namespace App\Core;

class Csrf {
    private const KEY = 'csrf_token';

    public static function token(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
    return $_SESSION[self::KEY];
    }

    public static function verify(?string $token): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $stored= $_SESSION[self::KEY] ?? '';

        return is_string($token) && $token !== ''  && $stored !== '' && hash_equals($stored, $token);
    }
}