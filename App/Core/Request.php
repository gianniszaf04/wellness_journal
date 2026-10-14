<?php
declare(strict_types=1);

namespace App\Core;

class Request
{
    private array $data = [];
    private array $query = [];
    private string $method;
    private string $uri;

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $this->uri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->query = $_GET;

        $this->parseData();
    }

    private function parseData(): void
    {
        $this->data = $_POST;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains(strtolower($contentType), 'application/json')) {
            $content = file_get_contents('php://input');

            if ($content !== false && $content !== '') {
                $decoded = json_decode($content, true);

                if (is_array($decoded)) {
                    $this->data = array_merge($this->data, $decoded);
                }
            }
        }
    }

    public function method(): string
    {
        return strtoupper($this->method);
    }

    public function path(): string
    {
        $path = parse_url($this->uri, PHP_URL_PATH);
        return is_string($path) ? $path : '/';
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $this->query[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]) || isset($this->query[$key]);
    }

    public function isJson(): bool
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        return str_contains(strtolower($contentType), 'application/json');
    }

    public function all(): array
    {
        return array_merge($this->query, $this->data);
    }

    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }
}