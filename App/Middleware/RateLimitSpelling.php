<?php
declare(strict_types=1);
namespace App\Middleware;

class RateLimitSpelling extends RateLimitLive {
    protected int $maxAttempts = 120;
    protected int $decaySec = 60;
}
