<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Models\User;

class RequireLogin {
    public function handle(): bool {

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // ο λογαριασμος μπορει να απενεργοποιηθηκε ή να ζητηθηκε διαγραφη απο αλλη συσκευη
        if (isset($_SESSION['user_id']) && !self::isActive((int) $_SESSION['user_id'])) {
            $_SESSION = [];
            session_regenerate_id(true);
        }

        if(!isset($_SESSION['user_id'])) {
            $uri=$_SERVER['REQUEST_URI'] ?? '' ;

            if (str_contains($uri,'/api/')) {
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

    private static function isActive(int $userId): bool {
        $user = (new User())->findById($userId);

        return $user !== null
            && (int) $user['status'] !== 0
            && empty($user['deletion_requested_at']);
    }
}
