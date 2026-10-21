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

// ακεραιος απο το query string, με την προεπιλογη αν λειπει ή ειναι εκτος οριων
function query_int(string $key, int $default, int $min, int $max): int {
    $value = filter_var($_GET[$key] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $min, 'max_range' => $max],
    ]);
    return $value === false ? $default : $value;
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

function asset(string $path): string {
    $path = ltrim($path, '/');
    $file = BASE_PATH . 'public/assets/' . $path;
    $version = is_file($file) ? '?v=' . filemtime($file) : '';
    return url('public/assets/' . $path) . $version;
}

function view(string $template, array $data = [], string $layout = 'app'): void {
    $render = static function (string $__file, array $__data): string {
        extract($__data, EXTR_SKIP);
        ob_start();
        require $__file;
        return (string) ob_get_clean();
    };

    $content = $render(BASE_PATH . "App/Views/{$template}.php", $data);
    echo $render(BASE_PATH . "App/Views/layouts/{$layout}.php", ['content' => $content] + $data);
}

function partial(string $name, array $data = []): void {
    extract($data, EXTR_SKIP);
    require BASE_PATH . "App/Views/partials/{$name}.php";
}

function flash(string $key): ?string {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $message = $_SESSION[$key] ?? null;
    unset($_SESSION[$key]);
    return is_string($message) && $message !== '' ? $message : null;
}

function mood_level(mixed $score): int {
    if ($score === null || $score === '') {
        return 0;
    }
    $score = (float) $score;
    return match (true) {
        $score >= .6 => 5,
        $score >= .2 => 4,
        $score > -.2 => 3,
        $score > -.6 => 2,
        default => 1,
    };
}

function mood_label(int $level): string {
    return ['', 'Πολύ δύσκολα', 'Δύσκολα', 'Ουδέτερα', 'Καλά', 'Πολύ καλά'][$level] ?? '';
}

function greek_weekday(int $weekday, bool $short = false): string {
    $full = ['Κυριακή', 'Δευτέρα', 'Τρίτη', 'Τετάρτη', 'Πέμπτη', 'Παρασκευή', 'Σάββατο'];
    $abbr = ['Κυ', 'Δε', 'Τρ', 'Τε', 'Πε', 'Πα', 'Σα'];
    return ($short ? $abbr : $full)[$weekday] ?? '';
}

function greek_date(string $date, bool $withWeekday = true): string {
    $months = ['Ιανουαρίου', 'Φεβρουαρίου', 'Μαρτίου', 'Απριλίου', 'Μαΐου', 'Ιουνίου',
        'Ιουλίου', 'Αυγούστου', 'Σεπτεμβρίου', 'Οκτωβρίου', 'Νοεμβρίου', 'Δεκεμβρίου'];
    $ts = strtotime($date);
    $text = date('j', $ts) . ' ' . $months[(int) date('n', $ts) - 1];
    return $withWeekday ? greek_weekday((int) date('w', $ts)) . ', ' . $text : $text;
}

function entry_when(string $datetime): string {
    $ts = strtotime($datetime);
    $days = (int) ((strtotime('today') - strtotime(date('Y-m-d', $ts))) / 86400);
    $time = date('H:i', $ts);
    return match (true) {
        $days === 0 => 'Σήμερα, ' . $time,
        $days === 1 => 'Χθες, ' . $time,
        $days < 7 => greek_weekday((int) date('w', $ts)) . ', ' . $time,
        default => greek_date(date('Y-m-d', $ts), false),
    };
}

function is_current_path(string $path): bool {
    $current = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
    $target = rtrim((string) parse_url(url($path), PHP_URL_PATH), '/');
    return $current === $target;
}