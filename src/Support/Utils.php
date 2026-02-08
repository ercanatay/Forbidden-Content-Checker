<?php

declare(strict_types=1);

namespace ForbiddenChecker\Support;

final class Utils
{
    public static function traceId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param array<int, string> $roles
     */
    public static function hasAnyRole(array $roles, array $required): bool
    {
        foreach ($required as $role) {
            if (in_array($role, $roles, true)) {
                return true;
            }
        }

        return false;
    }

    public static function nowIso(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    public static function randomBase32Secret(int $length = 20): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $secret;
    }

    public static function normalizeWhitespace(string $input): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $input));
    }
}
