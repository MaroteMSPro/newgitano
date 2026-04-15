<?php

namespace App\Core;

class Router
{
    private array $routes = [];

    public function add(string $method, string $path, callable|array $handler, array $middleware = []): self
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
        return $this;
    }

    public function get(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->add('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, callable|array $handler, array $middleware = []): self
    {
        return $this->add('DELETE', $path, $handler, $middleware);
    }

    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method);
        $uri = '/' . trim(parse_url($uri, PHP_URL_PATH), '/');

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $params = $this->match($route['path'], $uri);
            if ($params === false) {
                continue;
            }

            // Run middleware
            foreach ($route['middleware'] as $mw) {
                if (is_array($mw)) {
                    [$class, $mwMethod] = $mw;
                    (new $class())->$mwMethod();
                } elseif (is_callable($mw)) {
                    $mw();
                }
            }

            // Run handler
            $handler = $route['handler'];
            if (is_array($handler)) {
                [$class, $handlerMethod] = $handler;
                $response = (new $class())->$handlerMethod(...$params);
            } else {
                $response = $handler(...$params);
            }

            if (is_array($response)) {
                self::json($response);
            }
            return;
        }

        http_response_code(404);
        self::json(['error' => 'Not found']);
    }

    private function match(string $pattern, string $uri): array|false
    {
        $pattern = '/' . trim($pattern, '/');

        // Convert :param to regex
        $regex = preg_replace('/\/:([a-zA-Z_]+)/', '/(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $uri, $matches)) {
            return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        }

        return false;
    }

    public static function json(mixed $data, int $code = 200): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
