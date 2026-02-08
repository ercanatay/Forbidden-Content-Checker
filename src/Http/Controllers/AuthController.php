<?php

declare(strict_types=1);

namespace ForbiddenChecker\Http\Controllers;

use ForbiddenChecker\Http\Request;
use ForbiddenChecker\Http\Response;
use ForbiddenChecker\Support\Utils;

final class AuthController extends ApiController
{
    public function login(Request $request): void
    {
        $locale = $this->app->translator()->resolveLocale($request);
        if (!$this->checkAnonymousRateLimit($request, $locale)) {
            return;
        }

        $payload = $request->json();
        $email = trim((string) ($payload['email'] ?? ''));
        $password = (string) ($payload['password'] ?? '');
        $otpCode = isset($payload['otpCode']) ? (string) $payload['otpCode'] : null;

        if ($email === '' || $password === '') {
            Response::envelopeError(
                'validation_error',
                $this->app->translator()->t('error.validation', $locale),
                $locale,
                Utils::traceId(),
                ['email' => 'required', 'password' => 'required'],
                422
            );
            return;
        }

        try {
            $user = $this->app->auth()->login($email, $password, $otpCode, $request->ip(), $request->userAgent());
            Response::envelopeSuccess([
                'user' => $user,
                'csrfToken' => $this->app->csrf()->token(),
            ]);
        } catch (\Throwable $e) {
            Response::envelopeError(
                'auth_failed',
                $this->app->translator()->t('error.auth_failed', $locale),
                $locale,
                Utils::traceId(),
                ['reason' => $this->safeErrorMessage($e, 'auth.login')],
                401
            );
        }
    }

    public function logout(Request $request): void
    {
        $user = $this->requireAuth($request);
        if (!$user) {
            return;
        }

        $locale = $this->app->translator()->resolveLocale($request, $user['locale'] ?? null);
        if (!$this->requireCsrf($request, $locale)) {
            return;
        }

        $this->app->auth()->logout((int) $user['id']);
        Response::envelopeSuccess(['message' => $this->app->translator()->t('auth.logged_out', $locale)]);
    }

    public function me(Request $request): void
    {
        $user = $this->requireAuth($request);
        if (!$user) {
            return;
        }

        Response::envelopeSuccess([
            'user' => $user,
            'csrfToken' => $this->app->csrf()->token(),
        ]);
    }

    public function issueToken(Request $request): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst']);
        if (!$user) {
            return;
        }

        $locale = $this->app->translator()->resolveLocale($request, $user['locale'] ?? null);
        if (!$this->requireCsrf($request, $locale)) {
            return;
        }

        $payload = $request->json();
        $name = trim((string) ($payload['name'] ?? 'api-token'));
        $scope = trim((string) ($payload['scope'] ?? 'scan:read scan:write report:read'));
        $expiresAt = isset($payload['expiresAt']) ? trim((string) $payload['expiresAt']) : null;

        $token = $this->app->auth()->issueApiToken((int) $user['id'], $name, $scope, $expiresAt ?: null);

        Response::envelopeSuccess([
            'token' => $token,
            'name' => $name,
            'scope' => $scope,
            'expiresAt' => $expiresAt,
        ], [], 201);
    }
}
