<?php
declare(strict_types=1);

// Μικρο test framework χωρις εξαρτησεις: test(), assertions, HTTP client, ψευτικο AI, χρηστες για tests.

require_once dirname(__DIR__) . '/Config/bootstrap.php';

const TEST_EMAIL_DOMAIN = 'tests.invalid';

final class AssertionFailed extends RuntimeException {}
final class SkipTest extends RuntimeException {}

$GLOBALS['__tests'] = [];

function test(string $name, Closure $fn): void
{
    $GLOBALS['__tests'][] = ['file' => basename(debug_backtrace()[0]['file']), 'name' => $name, 'fn' => $fn];
}

function skip(string $reason): never
{
    throw new SkipTest($reason);
}

function assert_same(mixed $expected, mixed $actual, string $label = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($label !== '' ? "{$label}: " : '') . 'αναμενόταν ' . export($expected) . ', ήρθε ' . export($actual));
    }
}

function assert_true(bool $condition, string $label): void
{
    if (!$condition) {
        throw new AssertionFailed($label);
    }
}

function export(mixed $value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
}

// τιμη απο εμφωλευμενο array με "data.0.id"
function dig(mixed $value, string $path): mixed
{
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) {
            return null;
        }
        $value = $value[$key];
    }
    return $value;
}

function base_url(): string
{
    return rtrim((string) (getenv('TEST_BASE_URL') ?: (APP_URL ?: 'http://localhost/wellness_journal')), '/');
}

// ιδια διαδρομη, αλλο host (π.χ. 127.0.0.1 και [::1] για δυο διαφορετικες IP)
function base_url_with_host(string $host): string
{
    $parts = parse_url(base_url());
    return ($parts['scheme'] ?? 'http') . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '');
}

function db(): PDO
{
    return (new Database())->getConnection();
}

function rate_limit_dir(): string
{
    return BASE_PATH . 'storage' . DIRECTORY_SEPARATOR . 'ratelimit';
}

function clear_rate_limits(): void
{
    foreach (glob(rate_limit_dir() . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
        @unlink($file);
    }
}

// σβηνει μονο τους χρηστες των tests, τα υπολοιπα φευγουν με ON DELETE CASCADE
function cleanup_test_data(): void
{
    db()->exec("DELETE FROM users WHERE email LIKE '%@" . TEST_EMAIL_DOMAIN . "'");
    clear_rate_limits();
}

final class Response
{
    public function __construct(public int $status, public array $headers, public string $body) {}

    public function json(): array
    {
        $data = json_decode($this->body, true);
        if (!is_array($data)) {
            throw new AssertionFailed('Η απάντηση δεν είναι JSON: ' . mb_substr($this->body, 0, 200));
        }
        return $data;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    // το τελος του Location μετα τη βαση, π.χ. "login", "dashboard"
    public function redirect(): ?string
    {
        $location = $this->header('location');
        if ($location === null) {
            return null;
        }
        $basePath = (string) parse_url(base_url(), PHP_URL_PATH);
        $path = (string) parse_url(trim($location), PHP_URL_PATH);
        return trim(str_starts_with($path, $basePath) ? substr($path, strlen($basePath)) : $path, '/');
    }
}

final class Http
{
    private string $cookies;

    public function __construct(private string $base = '')
    {
        $this->base = $base !== '' ? rtrim($base, '/') : base_url();
        $this->cookies = (string) tempnam(sys_get_temp_dir(), 'wj-test-');
    }

    public function __destruct()
    {
        @unlink($this->cookies);
    }

    public function handle(string $method, string $path, ?string $body = null, array $headers = []): CurlHandle
    {
        $ch = curl_init($this->base . '/' . ltrim($path, '/'));
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_COOKIEFILE => $this->cookies,
            CURLOPT_COOKIEJAR => $this->cookies,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_NOBODY => $method === 'HEAD',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        return $ch;
    }

    public static function response(CurlHandle $ch, string|false|null $raw): Response
    {
        if ($raw === false || $raw === null) {
            throw new RuntimeException('HTTP σφάλμα: ' . curl_error($ch));
        }
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = [];
        foreach (explode("\r\n", substr($raw, 0, $headerSize)) as $line) {
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($value);
            }
        }
        return new Response((int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $headers, substr($raw, $headerSize));
    }

    public function request(string $method, string $path, ?string $body = null, array $headers = []): Response
    {
        $ch = $this->handle($method, $path, $body, $headers);
        $response = self::response($ch, curl_exec($ch));
        unset($ch); // το cookie jar γραφεται οταν κλεισει το handle
        return $response;
    }

    public function get(string $path): Response
    {
        return $this->request('GET', $path);
    }

    // φορμα με το csrf token της σελιδας $tokenPage
    public function form(string $path, array $fields, string $tokenPage): Response
    {
        return $this->request('POST', $path, http_build_query(['_csrf' => $this->csrf($tokenPage)] + $fields));
    }

    // JSON προς το API, με X-CSRF-Token απο τη σελιδα του editor
    public function api(string $method, string $path, ?array $data = null, bool $withCsrf = true): Response
    {
        $headers = ['Content-Type: application/json'];
        if ($withCsrf) {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf('entry/editor');
        }
        $body = $data === null ? null : (string) json_encode($data === [] ? new stdClass() : $data, JSON_UNESCAPED_UNICODE);
        return $this->request($method, $path, $body, $headers);
    }

    public function csrf(string $page): string
    {
        $html = $this->get($page)->body;
        if (preg_match('/name="_csrf" value="([^"]+)"|"X-CSRF-Token" : "([^"]+)"/', $html, $m) !== 1) {
            throw new AssertionFailed("Δεν βρέθηκε csrf token στη σελίδα {$page}");
        }
        return $m[1] !== '' ? $m[1] : $m[2];
    }
}

final class TestUser
{
    public function __construct(public int $id, public string $email, public string $password, public Http $http) {}

    // νεα συνδεση του ιδιου χρηστη, π.χ. δευτερη συσκευη
    public function login(?string $password = null, string $base = ''): array
    {
        $http = new Http($base);
        $response = $http->form('login', ['email' => $this->email, 'password' => $password ?? $this->password], 'login');
        return [$http, $response->redirect()];
    }
}

// εγγραφη + συνδεση. Καθαριζει τα ορια, γιατι καθε test φτιαχνει αρκετους χρηστες απο την ιδια IP
function make_user(?string $password = null): TestUser
{
    clear_rate_limits();
    $suffix = bin2hex(random_bytes(5));
    $email = "t{$suffix}@" . TEST_EMAIL_DOMAIN;
    $password ??= 'Test-' . $suffix . '!';

    $http = new Http();
    $http->form('register', ['username' => 't' . $suffix, 'name' => 'Test', 'email' => $email, 'password' => $password], 'register');

    $stmt = db()->prepare('SELECT id FROM users WHERE email = :email');
    $stmt->execute([':email' => $email]);
    $id = (int) $stmt->fetchColumn();
    db()->prepare('UPDATE users SET email_verified_at = NOW() WHERE id = :id')->execute([':id' => $id]);

    $login = $http->form('login', ['email' => $email, 'password' => $password], 'login');
    assert_same('dashboard', $login->redirect(), "login του {$email}");

    return new TestUser($id, $email, $password, $http);
}

// Ψευτικο AI στη διευθυνση του AI_SERVICE_URL, με τον ενσωματωμενο server της PHP
final class FakeAi
{
    private static mixed $process = null;

    public static function start(): void
    {
        if (self::$process !== null) {
            return;
        }
        [$host, $port] = self::address();
        if (self::reachable($host, $port)) {
            throw new RuntimeException("Κάτι ακούει ήδη στο {$host}:{$port} (μάλλον το AI service). Σταμάτησέ το για να τρέξουν τα tests.");
        }

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        self::$process = proc_open(
            [PHP_BINARY, '-S', "{$host}:{$port}", __DIR__ . DIRECTORY_SEPARATOR . 'fake_ai.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
            $pipes
        );

        for ($i = 0; $i < 50 && !self::reachable($host, $port); $i++) {
            usleep(100000);
        }
        if (!self::reachable($host, $port)) {
            self::stop();
            throw new RuntimeException("Το ψεύτικο AI δεν ξεκίνησε στο {$host}:{$port}.");
        }
    }

    public static function stop(): void
    {
        if (self::$process === null) {
            return;
        }
        proc_terminate(self::$process);
        proc_close(self::$process);
        self::$process = null;

        [$host, $port] = self::address();
        for ($i = 0; $i < 50 && self::reachable($host, $port); $i++) {
            usleep(100000);
        }
    }

    private static function address(): array
    {
        $url = parse_url((string) (getenv('AI_SERVICE_URL') ?: 'http://127.0.0.1:8000/analyze'));
        $host = (string) ($url['host'] ?? '127.0.0.1');
        if (!in_array($host, ['127.0.0.1', 'localhost'], true)) {
            skip("Το AI_SERVICE_URL δείχνει στο {$host}, το ψεύτικο AI τρέχει μόνο τοπικά.");
        }
        return [$host, (int) ($url['port'] ?? 80)];
    }

    private static function reachable(string $host, int $port): bool
    {
        $socket = @fsockopen($host, $port, $errno, $error, 0.3);
        if ($socket === false) {
            return false;
        }
        fclose($socket);
        return true;
    }
}
