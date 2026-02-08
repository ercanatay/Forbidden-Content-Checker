<?php

declare(strict_types=1);

namespace ForbiddenChecker\Infrastructure\Security;

final class CsrfTokenManager
{
    private const SESSION_KEY = '_csrf_token';

    public function token(): string
    {
        if (!isset($_SESSION[self::SESSION_KEY]) || !is_string($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    public function validate(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        $current = $_SESSION[self::SESSION_KEY] ?? null;
        return is_string($current) && hash_equals($current, $token);
    }
}
