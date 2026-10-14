<?php 
declare(strict_types=1);
namespace App\Middleware;

class RateLimit {
    private int $maxAttempts = 5;
    private int $decaySec = 900 ;

    public function handle(): bool {

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return true ;
        }

        $file = self::fileFor();
        $now =time();
        $hits =[];

        if (is_file($file)) {
            $decoded = json_decode((string) file_get_contents($file), true );
            if (is_array($decoded)) {
                $hits=$decoded;
            }
        }

        $hits= array_values(array_filter(
            $hits , fn ($t) : bool => ($now -(int) $t) < $this ->decaySec
        ));

        if (count($hits) >= $this->maxAttempts) {
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
    
        $hits[] = $now;
        $dir=dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir,0775, true);
        }
    file_put_contents($file ,json_encode($hits), LOCK_EX);
    return true ;
    }

    public  static function clear(): void {
        $file = self ::fileFor();
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private static  function fileFor(): string {
        $ip=$_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $uri= $_SERVER['REQUEST_URI'] ?? '';
        $key= hash('sha256', $ip . '|' . $uri);

        return BASE_PATH . 'storage' . DIRECTORY_SEPARATOR . 'ratelimit'  . DIRECTORY_SEPARATOR . $key . '.json';
    }
}