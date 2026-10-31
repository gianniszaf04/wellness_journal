<?php
declare(strict_types=1);
namespace App\Middleware;

class RateLimitEntries extends RateLimitLive {
    protected int $maxAttempts = 20;
    protected int $decaySec = 60;

    protected static function scope(): string {
        return '/api/entries';
    }
}
