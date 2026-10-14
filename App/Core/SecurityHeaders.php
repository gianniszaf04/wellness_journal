<?php 
declare(strict_types=1);

namespace App\Core;

class SecurityHeaders{
    public static function apply(): void {
        if (headers_sent()) {
            return ;
        }

        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-XSS-Protection: 0');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

        header(
            "Content-Security-Policy: "
            . "default-src 'self' ; "
            . "script-src 'self' 'unsafe-inline'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: ; "
            . "connect-src 'self' ; "
            . "base-uri 'self' ; "
            . "form-action 'self' ; "
            . "frame-ancestors 'none'"
        );
    }
}