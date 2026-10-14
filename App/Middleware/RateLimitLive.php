<?php
declare(strict_types=1);
namespace App\Middleware;

// πιο χαλαρο οριο για τη ζωντανη αναλυση: 30 αιτηματα το λεπτο ανα χρηστη
class RateLimitLive extends RateLimit {
    protected int $maxAttempts = 30;
    protected int $decaySec = 60;

    // ανα χρηστη και οχι ανα IP, για να μη μοιραζονται το οριο ολοι οσοι ειναι στο ιδιο δικτυο
    protected static function identity(): string {
        return 'user:' . (string) (current_user_id() ?? parent::identity());
    }
}
