<?php

declare(strict_types=1);

namespace ForbiddenChecker\Domain\Scan;

final class UrlNormalizer
{
    public function normalizeInput(string $input): ?string
    {
        $candidate = trim($input);
        if ($candidate === '') {
            return null;
        }

        if (!preg_match('~^(?:https?://)~i', $candidate)) {
            $candidate = 'https://' . $candidate;
        }

        $parts = parse_url($candidate);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
        $query = isset($parts['query']) ? (string) $parts['query'] : '';

        $url = $scheme . '://' . $host;
        if ($port !== null && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
            $url .= ':' . $port;
        }
        $url .= $path;
        if ($query !== '') {
            $url .= '?' . $query;
        }

        return $url;
    }

    public function baseUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $base = $scheme . '://' . strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $port = (int) $parts['port'];
            if (!(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
                $base .= ':' . $port;
            }
        }

        return $base;
    }

    public function resolveUrl(string $baseUrl, string $link): ?string
    {
        $link = trim($link);
        if ($link === '' || str_starts_with($link, '#') || str_starts_with(strtolower($link), 'javascript:')) {
            return null;
        }

        if (preg_match('~^https?://~i', $link)) {
            return $this->normalizeInput($link);
        }

        if (str_starts_with($link, '//')) {
            $baseParts = parse_url($baseUrl);
            $scheme = is_array($baseParts) ? strtolower((string) ($baseParts['scheme'] ?? 'https')) : 'https';
            return $this->normalizeInput($scheme . ':' . $link);
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || empty($base['host'])) {
            return null;
        }

        $origin = strtolower((string) ($base['scheme'] ?? 'https')) . '://' . strtolower((string) $base['host']);
        if (isset($base['port'])) {
            $port = (int) $base['port'];
            if (!(($base['scheme'] ?? 'https') === 'http' && $port === 80) && !(($base['scheme'] ?? 'https') === 'https' && $port === 443)) {
                $origin .= ':' . $port;
            }
        }

        $basePath = $base['path'] ?? '/';
        if (!is_string($basePath) || $basePath === '') {
            $basePath = '/';
        }

        if ($link[0] === '/') {
            return $this->normalizeInput($origin . $link);
        }

        $dir = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
        if ($dir === '' || $dir === '.') {
            $dir = '';
        }

        return $this->normalizeInput($origin . '/' . ltrim($dir . '/' . $link, '/'));
    }

    private function normalizePath(string $path): string
    {
        $path = $path === '' ? '/' : $path;
        $segments = explode('/', $path);
        $stack = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($stack);
                continue;
            }
            $stack[] = rawurlencode(rawurldecode($segment));
        }

        return '/' . implode('/', $stack);
    }
}
