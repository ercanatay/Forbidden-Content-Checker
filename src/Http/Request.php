<?php

declare(strict_types=1);

namespace ForbiddenChecker\Http;

final class Request
{
    /** @var array<string, mixed>|null */
    private ?array $jsonCache = null;

    public function __construct(
        private readonly array $server,
        private readonly array $get,
        private readonly array $post,
        private readonly array $cookie,
        private readonly array $files,
        private readonly string $rawBody
    ) {
    }

    public static function fromGlobals(): self
    {
        return new self($_SERVER, $_GET, $_POST, $_COOKIE, $_FILES, file_get_contents('php://input') ?: '');
    }

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function uriPath(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);

        return is_string($path) ? $path : '/';
    }

    public function header(string $name): ?string
    {
        $normalized = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($this->server[$normalized])) {
            return (string) $this->server[$normalized];
        }

        if ($name === 'Content-Type' && isset($this->server['CONTENT_TYPE'])) {
            return (string) $this->server['CONTENT_TYPE'];
        }

        return null;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->get[$key] ?? $default;
        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : $default;
    }

    public function post(string $key, ?string $default = null): ?string
    {
        $value = $this->post[$key] ?? $default;
        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }

        if ($this->rawBody === '') {
            $this->jsonCache = [];
            return $this->jsonCache;
        }

        $decoded = json_decode($this->rawBody, true);
        $this->jsonCache = is_array($decoded) ? $decoded : [];

        return $this->jsonCache;
    }

    public function body(): string
    {
        return $this->rawBody;
    }

    public function cookie(string $name): ?string
    {
        $value = $this->cookie[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    public function ip(): string
    {
        $ip = $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($ip) ? $ip : '0.0.0.0';
    }

    public function userAgent(): string
    {
        $ua = $this->server['HTTP_USER_AGENT'] ?? '';
        return is_string($ua) ? $ua : '';
    }

    public function acceptsJson(): bool
    {
        $accept = $this->header('Accept') ?? '';
        return str_contains($accept, 'application/json');
    }

    public function isApiRequest(): bool
    {
        return str_starts_with($this->uriPath(), '/api/');
    }
}
