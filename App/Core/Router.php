<?php
declare(strict_types=1);

namespace App\Core;

use Closure;
use InvalidArgumentException;
use Throwable;

class Router
{
    private array $routes = [];
    private array $namedRoutes = [];
    private array $pendingMiddleware = [];

    private array $groupMiddleware = [] ;

    public function get(string $uri, mixed $action, ?string $name = null): self
    {
        return $this->register('GET', $uri, $action, $name);
    }
    public function post(string $uri, mixed $action, ?string $name = null): self
    {
        return $this->register('POST', $uri, $action, $name);
    }
    public function put(string $uri, mixed $action, ?string $name = null): self
    {
        return $this->register('PUT', $uri, $action, $name);
    }
    public function delete(string $uri, mixed $action, ?string $name = null): self
    {
        return $this->register('DELETE', $uri, $action, $name);
    }
    public function patch(string $uri, mixed $action, ?string $name = null): self
    {
        return $this->register('PATCH', $uri, $action, $name);
    }

    public function any(string $uri, mixed $action, ?string $name = null): self
    {
        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
        $snapshot = $this->pendingMiddleware;


        foreach ($methods as $method) {
            $this->pendingMiddleware = $snapshot;
            $this->register($method, $uri, $action, null);
        }

        if ($name !== null) {
            $this->namedRoutes[$name] = ['uri'=> $this->normalizeUri($uri)];
        }
        return $this;
    }



    public function middleware(array $middlewares): self
    {
        $this->pendingMiddleware = $middlewares;
        return $this;
    }

    public function group ( array $options, \Closure $callback ): void  {
        $groupMiddleware = $options ['middleware'] ?? [];
        $saved = $this ->pendingMiddleware;

        $proxy = clone $this;
        $proxy -> pendingMiddleware = $groupMiddleware;

        $this->groupMiddleware = $groupMiddleware;
        $callback ($this);
        $this->groupMiddleware = [];
        $this-> pendingMiddleware = $saved;

    }

    public function hasRoute(string $name): bool
    {
        return isset($this->namedRoutes[$name]);
    }

    public function url(string $name, array $params = []): string
    {
        if (!isset($this->namedRoutes[$name])) {
            throw new InvalidArgumentException("Route '{$name}' not found.");
        }

        $uri = $this->namedRoutes[$name]['uri'];

        foreach ($params as $key => $value) {
            $uri = str_replace('{' . $key . '}', (string) $value, $uri);
        }
        return $uri;
    }

    public function getRoutes(): array
    {
        return $this->routes;
    }
    public function getNamedRoutes(): array
    {
        return $this->namedRoutes;
    }

    private function register(string $method, string $uri, mixed $action, ?string $name = null): self
    {
        $uri = $this->normalizeUri($uri);
        $middleware= array_merge($this->groupMiddleware ?? [] , $this->pendingMiddleware);

        $this->routes[$method][$uri] = ['action' => $action, 'middleware' =>  $middleware];

        if ($name !== null) {
            $this->namedRoutes[$name] = ['uri' => $uri];
        }
        $this->pendingMiddleware = [];

        return $this;
    }

    private function normalizeUri(string $uri): string
    {
        $uri = trim($uri);

        if ($uri === '') {
            return '/';
        }

        $uri = rtrim($uri, '/');

        return $uri === '' ? '/' : $uri;
    }

    public function dispatch(string $requestMethod, string $requestUri): void
    {
        $method = strtoupper($requestMethod);
        $path = (string) parse_url($requestUri, PHP_URL_PATH);
        $uri = $this->normalizeUri($path);
        $isApi = str_starts_with($uri, '/api/');

        // HEAD απανταει οπως το GET, ο Apache κραταει μονο τα headers
        $matched = $this->findRoute($method, $uri)
            ?? ($method === 'HEAD' ? $this->findRoute('GET', $uri) : null);

        if ($matched === null) {
            $allowed = $this->allowedMethods($uri);

            if ($allowed !== []) {
                header('Allow: ' . implode(', ', $allowed));
                $this->sendError(405, 'Η μέθοδος δεν επιτρέπεται.', $isApi);
                return;
            }

            $this->sendError(404, 'Η σελίδα δεν βρέθηκε.', $isApi);
            return;
        }

        // και τα middleware μεσα στο try, γιατι κι αυτα πιανουν τη βαση
        try {
            if (!$this->runMiddleware($matched['route']['middleware'])) {
                $this->sendError(403, 'Δεν επιτρέπεται η πρόσβαση.', $isApi);
                return;
            }

            $this->executeAction($matched['route']['action'], $matched['params']);
        } catch (Throwable $e) {
            error_log(($e->getMessage() . PHP_EOL . $e->getTraceAsString()));
            $this->sendError(500, 'Εσωτερικό σφάλμα διακομιστή.', $isApi);
        }
    }

    private function findRoute(string $method, string $uri): ?array
    {
        if (isset($this->routes[$method][$uri])) {
            return ['route' => $this->routes[$method][$uri], 'params' => []];
        }

        return $this->matchRoute($method, $uri);
    }

    private function allowedMethods(string $uri): array
    {
        $allowed = [];

        foreach (array_keys($this->routes) as $method) {
            if ($this->findRoute($method, $uri) !== null) {
                $allowed[] = $method;
            }
        }

        if (in_array('GET', $allowed, true)) {
            $allowed[] = 'HEAD';
        }

        return $allowed;
    }

    private function runMiddleware(array $middlewares): bool
    {
        foreach ($middlewares as $middlewareClass) {

            if (!class_exists($middlewareClass)) {
                throw new InvalidArgumentException("Middleware '{$middlewareClass}'  not found.");
            }

            $middleware = new $middlewareClass();

            if (!method_exists($middleware, 'handle')) {
                throw new InvalidArgumentException(
                    "Middleware '{$middlewareClass}' must have a handle() method."
                );
            }

            if ($middleware->handle() !== true) {
                return false;
            }
        }
        return true;
    }


    private function matchRoute(string $method, string $uri): ?array
    {

        $patterns = array_keys($this->routes[$method] ?? []);
        usort($patterns, static function (string $a, string $b): int {
            $wildA = substr_count($a, '{');
            $wildB = substr_count($b, '{');

            if ($wildA !== $wildB) {
                return $wildA <=> $wildB;
            }

            return strlen($b) <=> strlen($a);
        });

        foreach ($patterns as $pattern) {
            $regex = preg_replace('/{([a-zA-Z_][a-zA-Z0-9_]*)}/', '(?P<$1>[^/]+)', $pattern);

            if (!is_string($regex)) {
                continue;
            }

            $regex = '#^' . $regex . '$#';

            if (preg_match($regex, $uri, $matches) !== 1) {
                continue;
            }

            $params = [];

            foreach ($matches as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = (string) $value;
                }
            }

            return ['route' => $this->routes[$method][$pattern], 'params' => $params,];
        }
        return null;
    }

    private function executeAction(mixed $action, array $params = []): void
    {
        if ($action instanceof Closure) {
            $action(...array_values($params));
            return;
        }
        if (is_array($action)) {
            if (count($action) !== 2) {
                throw new InvalidArgumentException('Invalid controller action array.');
            }

            [$controller, $method] = $action;
            if (!class_exists($controller)) {
                throw new InvalidArgumentException("Controller '{$controller}' not  found");
            }

            $instance = new $controller();

            if (!method_exists($instance, $method)) {
                throw new InvalidArgumentException("Method '{$method}' not found.");
            }
            call_user_func_array([$instance, $method], $params);

            return;
        }


        if (is_string($action) && str_contains($action, '@')) {
            [$controller, $method] = explode('@', $action, 2);
            $controllerClass = "App\\Controllers\\" . $controller;

            if (!class_exists($controllerClass)) {
                throw new InvalidArgumentException("Controller '{$controllerClass}' not found.");
            }

            $instance = new $controllerClass();
            if (!method_exists($instance, $method)) {
                throw new InvalidArgumentException("Method '{$method}' not found. ");
            }

            call_user_func_array([$instance, $method], $params);
            return;
        }
        throw new InvalidArgumentException('Invalid route action format');
    }

    private function sendError(int $statusCode, string $message, bool $isApi = false): void
    {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header($isApi ? 'Content-type: application/json; charset=utf-8' : 'Content-type: text/html; charset=utf-8');
        } else {
            http_response_code($statusCode);
        }

        // ιδιο σχημα με τα υπολοιπα λαθη του API
        if ($isApi) {
            echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
            return;
        }

        error_page($statusCode, [
            403 => 'Δεν έχεις πρόσβαση σε αυτή τη σελίδα.',
            404 => 'Ίσως ο σύνδεσμος είναι λάθος ή η σελίδα δεν υπάρχει πια.',
            405 => 'Αυτή η ενέργεια δεν γίνεται σε αυτή τη διεύθυνση.',
            500 => 'Συνέβη ένα απρόσμενο σφάλμα από τη μεριά μας. Δοκίμασε ξανά σε λίγο.',
        ][$statusCode] ?? $message);
    }
}
