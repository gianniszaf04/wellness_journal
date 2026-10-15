<?php
declare(strict_types=1);
namespace App\Middleware;

class RateLimit {
    protected int $maxAttempts = 5;
    protected int $decaySec = 900 ;

    public function handle(): bool {

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return true ;
        }

        // μετραμε και ανα IP και ανα λογαριασμο, αρκει ενα να εχει γεμισει
        $blocked = false;
        foreach (static::files() as $file) {
            if (!$this->hit($file)) {
                $blocked = true;
            }
        }

        if ($blocked) {
            http_response_code(429);
            $uri= $_SERVER['REQUEST_URI'] ?? '' ;

            if (str_contains($uri, '/api')) {
                header('Content-type: application/json; charset=utf-8');
                echo json_encode(['success' => false , 'error' =>'Πάρα πολλές προσπάθειες. Προσπαθήστε ξανά αργότερα.'], JSON_UNESCAPED_UNICODE);
            }else {
                header('Content-type: text/html; charset=utf-8');
                echo '<!doctype html><html lang="el"><head><meta charset="utf-8"><title>429</title></head>
                <body><h1>429 -Πάρα πολλές προσπάθειες. Προσπαθήστε ξανά αργότερα.</h1><p>Περίμενε λίγα λεπτά και ξαναπροσπάθησε αργότερα.</p></body></html>
                ';
            }
        exit ;
        }
        return true ;
    }

    public  static function clear(): void {
        foreach (static::files() as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    // σβηνει αρχεια που δεν αγγιχτηκαν μεσα στο παραθυρο της βασικης κλασης (το μεγαλυτερο), ολα τα hits τους εχουν ληξει
    public static function purgeStale(): int {
        $cutoff = time() - (new self())->decaySec;
        $removed = 0;

        foreach (glob(dirname(self::fileFor('')) . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (filemtime($file) < $cutoff && @unlink($file)) {
                $removed++;
            }
        }
        return $removed;
    }

    // ποιος μετραει: εδω η IP, οι υποκλασεις μπορουν να το αλλαξουν
    protected static function identity(): string {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    // καταγραφει μια προσπαθεια, false αν το οριο εχει ηδη γεμισει
    // διαβασμα και γραψιμο κατω απο το ιδιο lock, για να μην ξεφευγουν ταυτοχρονα αιτηματα
    private function hit(string $file): bool {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return true;
        }
        flock($fp, LOCK_EX);

        $now = time();
        $decoded = json_decode((string) stream_get_contents($fp), true);
        $hits = array_values(array_filter(
            is_array($decoded) ? $decoded : [], fn ($t) : bool => ($now - (int) $t) < $this->decaySec
        ));

        $allowed = count($hits) < $this->maxAttempts;
        if ($allowed) {
            $hits[] = $now;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($hits));
            fflush($fp);
        }

        flock($fp, LOCK_UN);
        fclose($fp);
        return $allowed;
    }

    private static function files(): array {
        // μονο το path: αλλιως το /login?x=1, ?x=2... μετραει το καθενα χωριστα
        $path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
        $files = [self::fileFor(static::identity() . '|' . $path)];

        $email = $_POST['email'] ?? '';
        if (is_string($email) && trim($email) !== '') {
            $files[] = self::fileFor('email:' . mb_strtolower(trim($email)) . '|' . $path);
        }
        return $files;
    }

    private static function fileFor(string $identity): string {
        $key = hash('sha256', $identity);

        return BASE_PATH . 'storage' . DIRECTORY_SEPARATOR . 'ratelimit'  . DIRECTORY_SEPARATOR . $key . '.json';
    }
}
