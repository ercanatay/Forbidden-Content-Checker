<?php

declare(strict_types=1);

namespace ForbiddenChecker\Http\Controllers;

use ForbiddenChecker\App;
use ForbiddenChecker\Http\Request;
use ForbiddenChecker\Http\Response;
use ForbiddenChecker\Support\Utils;

abstract class ApiController
{
    public function __construct(protected readonly App $app)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function user(Request $request): ?array
    {
        return $this->app->auth()->currentUser($request);
    }

    /**
     * @param array<int, string> $roles
     * @return array<string, mixed>|null
     */
    protected function requireAuth(Request $request, array $roles = []): ?array
    {
        $traceId = Utils::traceId();
        $user = $this->user($request);
        $locale = $this->app->translator()->resolveLocale($request, $user['locale'] ?? null);

        if (!$user) {
            Response::envelopeError(
                'unauthorized',
                $this->app->translator()->t('error.unauthorized', $locale),
                $locale,
                $traceId,
                [],
                401
            );
            return null;
        }

        if (!$this->app->enforceRateLimit($request, $user)) {
            Response::envelopeError(
                'rate_limited',
                $this->app->translator()->t('error.rate_limited', $locale),
                $locale,
                $traceId,
                [],
                429
            );
            return null;
        }

        if (count($roles) > 0 && !Utils::hasAnyRole((array) ($user['roles'] ?? []), $roles)) {
            Response::envelopeError(
                'forbidden',
                $this->app->translator()->t('error.forbidden', $locale),
                $locale,
                $traceId,
                ['requiredRoles' => $roles],
                403
            );
            return null;
        }

        return $user;
    }

    protected function requireCsrf(Request $request, string $locale): bool
    {
        $traceId = Utils::traceId();
        $method = $request->method();
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return true;
        }

        $authHeader = $request->header('Authorization') ?? '';
        if (str_starts_with(strtolower($authHeader), 'bearer ')) {
            return true;
        }

        $token = $request->header('X-CSRF-Token');
        if ($this->app->csrf()->validate($token)) {
            return true;
        }

        Response::envelopeError(
            'csrf_invalid',
            $this->app->translator()->t('error.csrf_invalid', $locale),
            $locale,
            $traceId,
            [],
            419
        );

        return false;
    }

    protected function checkAnonymousRateLimit(Request $request, string $locale): bool
    {
        if ($this->app->enforceRateLimit($request, null)) {
            return true;
        }

        Response::envelopeError(
            'rate_limited',
            $this->app->translator()->t('error.rate_limited', $locale),
            $locale,
            Utils::traceId(),
            [],
            429
        );
        return false;
    }
}
