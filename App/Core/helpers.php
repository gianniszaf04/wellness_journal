<?php 
declare(strict_types=1);

function url( string $path= ''): string {
    $baseUrl = (defined('APP_URL') && APP_URL) ? APP_URL : '';

    if (empty($baseUrl)) {
        $scheme = $_SERVER['REQUEST_SCHEME']  ?? 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName= $_SERVER['SCRIPT_NAME'] ?? '/index.php';

        $basePath = str_replace('/public/index.php', ''  , $scriptName);
        $basePath = str_replace('/index.php', ''  , $basePath);

        $baseUrl="{$scheme}://{$host}{$basePath}";
    }
    return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
}
function redirect(string $path) :void {
    header('Location:' . url($path));
    exit;
}

function current_user_id(): ?int {
    if (session_status() === PHP_SESSION_NONE ) {
            session_start();
    }
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null ;
}

function json_response (mixed $data , int $status = 200) : void {
    http_response_code($status);
    header('Content-type: application/json; charset=utf-8');
    echo  json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string {
    return \App\Core\Csrf::token();
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}