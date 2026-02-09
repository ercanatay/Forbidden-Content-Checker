<?php

declare(strict_types=1);

namespace ForbiddenChecker\Http\Controllers;

use ForbiddenChecker\Http\Request;
use ForbiddenChecker\Http\Response;
use ForbiddenChecker\Support\Utils;

final class UpdateController extends ApiController
{
    public function status(Request $request): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst', 'viewer']);
        if (!$user) {
            return;
        }

        Response::envelopeSuccess([
            'update' => $this->app->updates()->status(),
        ]);
    }

    public function check(Request $request): void
    {
        $user = $this->requireAuth($request, ['admin']);
        if (!$user) {
            return;
        }

        $locale = $this->app->translator()->resolveLocale($request, $user['locale'] ?? null);
        if (!$this->requireCsrf($request, $locale)) {
            return;
        }

        $payload = $request->json();
        $force = isset($payload['force']) ? (bool) $payload['force'] : false;

        try {
            $state = $this->app->updates()->checkForUpdates($force, (int) $user['id']);
            Response::envelopeSuccess(['update' => $state]);
        } catch (\Throwable $e) {
            Response::envelopeError(
                'update_check_failed',
                $e->getMessage(),
                $locale,
                Utils::traceId(),
                [],
                500
            );
        }
    }

    public function approve(Request $request): void
    {
        $user = $this->requireAuth($request, ['admin']);
        if (!$user) {
            return;
        }

        $locale = $this->app->translator()->resolveLocale($request, $user['locale'] ?? null);
        if (!$this->requireCsrf($request, $locale)) {
            return;
        }

        $payload = $request->json();
        $version = trim((string) ($payload['version'] ?? ''));
        if ($version === '') {
            Response::envelopeError(
                'validation_error',
                $this->app->translator()->t('error.validation', $locale),
                $locale,
                Utils::traceId(),
                ['version' => 'required'],
                422
            );
            return;
        }

        try {
            $state = $this->app->updates()->approveUpdate($version, (int) $user['id']);
            Response::envelopeSuccess(['update' => $state]);
        } catch (\Throwable $e) {
            Response::envelopeError(
                'update_approval_failed',
                $e->getMessage(),
                $locale,
                Utils::traceId(),
                [],
                422
            );
        }
    }

    public function revokeApproval(Request $request): void
    {
        $user = $this->requireAuth($request, ['admin']);
        if (!$user) {
            return;
        }

        $locale = $this->app->translator()->resolveLocale($request, $user['locale'] ?? null);
        if (!$this->requireCsrf($request, $locale)) {
            return;
        }

        $payload = $request->json();
        $versionRaw = trim((string) ($payload['version'] ?? ''));
        $version = $versionRaw === '' ? null : $versionRaw;

        try {
            $state = $this->app->updates()->revokeApproval($version, (int) $user['id']);
            Response::envelopeSuccess(['update' => $state]);
        } catch (\Throwable $e) {
            Response::envelopeError(
                'update_revoke_failed',
                $e->getMessage(),
                $locale,
                Utils::traceId(),
                [],
                422
            );
        }
    }
}
