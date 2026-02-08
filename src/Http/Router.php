<?php

declare(strict_types=1);

namespace ForbiddenChecker\Http;

final class Router
{
    /**
     * @var array<int, array{method: string, pattern: string, regex: string, params: array<int, string>, handler: callable}>
     */
    private array $routes = [];

    /**
     * @param callable(Request, array<string, string>): void $handler
     */
    public function add(string $method, string $pattern, callable $handler): void
    {
        [$regex, $params] = $this->compilePattern($pattern);
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'regex' => $regex,
            'params' => $params,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): bool
    {
        $path = rtrim($request->uriPath(), '/') ?: '/';
        $method = $request->method();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            $params = [];
            foreach ($route['params'] as $param) {
                $params[$param] = $matches[$param] ?? '';
            }

            ($route['handler'])($request, $params);
            return true;
        }

        return false;
    }

    /**
     * @return array{0: string, 1: array<int, string>}
     */
    private function compilePattern(string $pattern): array
    {
        $normalized = rtrim($pattern, '/') ?: '/';

        preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $normalized, $matches);
        $params = $matches[1] ?? [];

        $regex = preg_quote($normalized, '#');
        foreach ($params as $param) {
            $regex = str_replace('\\{' . $param . '\\}', '(?<' . $param . '>[^/]+)', $regex);
        }

        return ['#^' . $regex . '$#', $params];
    }
}
