<?php 
declare(strict_types=1);
namespace App\Middleware;

use App\Core\Csrf;

class VerifyCsrf {
    public function handle(): bool {

        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        if (!in_array($method, ['POST' , 'PUT' , 'PATCH', 'DELETE'],true )) {
            return true;
        }

        if (Csrf::verify($this->extractToken())) {
            return true ;
        }

        $uri = $_SERVER['REQUEST_URI'] ?? '';

        if (str_contains($uri, '/api/')) {
            http_response_code(403);
            header('Content-type: application/json; charset=utf-8');
            echo json_encode(['success'=> false, 'error'=>'Άκυρο ή ληγμενο Csrf token'],JSON_UNESCAPED_UNICODE);
        }else {
            http_response_code(403);
            header('Content-type: text/html; charset=utf-8');
            echo '<!doctype html><html lang="el"><head><meta charset="utf-8"><title>403</title></head>
            <body><h1> 403- Άκυρο ή ληγμενο Csrf token</h1><p>Aνανέωσe την ιστοσελίδα και προσπάθησε ξανά.</p></body></html>';
        }
        exit;
    }


    private function  extractToken(): ?string {

        $header= $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '' ;

        if ($header !== '' ) {
            return $header;
        }

        if (isset($_POST['_csrf'])) {
            return (string) $_POST['_csrf'];
        }

        $contentType= $_SERVER['CONTENT_TYPE'] ?? '';

        if(str_contains(strtolower($contentType), 'application/json')) {
            $raw= file_get_contents('php://input');
            if( $raw !== false && $raw !== '') {
                $decoded=json_decode($raw,true);
                if (is_array($decoded) && isset($decoded['_csrf'])) {
                    return (string) $decoded['_csrf'];
                }
            }
        }
        return null;
    }
}