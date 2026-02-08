<?php

declare(strict_types=1);

namespace ForbiddenChecker\Infrastructure\Security;

final class SsrfGuard
{
    public function __construct(private readonly bool $allowPrivateNetwork = false)
    {
    }

    /**
     * @return array{host: string, port: int, ip: string}
     */
    public function validateAndResolve(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            throw new \RuntimeException('Invalid URL host.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Only HTTP(S) URLs are allowed.');
        }

        $host = (string) $parts['host'];
        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        $ip = gethostbyname($host);
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \RuntimeException('Unable to resolve host IP.');
        }

        if (!$this->allowPrivateNetwork && $this->isPrivateOrReservedIp($ip)) {
            throw new \RuntimeException('Resolved IP is private or reserved.');
        }

        return ['host' => $host, 'port' => $port, 'ip' => $ip];
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
