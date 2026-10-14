<?php 
declare(strict_types=1);

namespace App\Middleware;

class RequireLogin {
    public function handle(): bool {

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if(!isset($_SESSION['user_id'])) {
            $uri=$_SERVER['REQUEST_URI'] ?? '' ;

            if (str_starts_with($uri,'/api/')) {
                http_response_code(401);
                header('Content-Type:application/json;charset=utf-8');
                echo json_encode(['success' => false, 'error'=>'Παρακαλώ συνδεθείτε. Μη εξουσιοδοτημένη πρόσβαση']);
                exit;
            }
            header('Location:' . url('login'));
            exit;
        }
        return true ;
    }
}