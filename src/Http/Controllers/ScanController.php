<?php

declare(strict_types=1);

namespace ForbiddenChecker\Http\Controllers;

use ForbiddenChecker\Http\Request;
use ForbiddenChecker\Http\Response;
use ForbiddenChecker\Support\Utils;

final class ScanController extends ApiController
{
    public function create(Request $request): void
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
        try {
            $job = $this->app->scan()->createScanJob((int) $user['id'], $payload);
            $sync = (bool) ($payload['sync'] ?? false);

            if ($sync) {
                $job = $this->app->scan()->processScanJob((int) $job['id'], 'api-sync');
            }

            Response::envelopeSuccess(['scan' => $job], ['queued' => !$sync], 201);
        } catch (\Throwable $e) {
            Response::envelopeError(
                'scan_create_failed',
                $this->app->translator()->t('error.scan_create_failed', $locale),
                $locale,
                Utils::traceId(),
                ['reason' => $this->safeErrorMessage($e, 'scan.create')],
                422
            );
        }
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst', 'viewer']);
        if (!$user) {
            return;
        }

        $scanId = (int) ($params['id'] ?? 0);
        $scan = $this->app->scan()->getScanJob($scanId);
        if (!$scan) {
            $locale = $this->app->translator()->resolveLocale($request, $user['locale'] ?? null);
            Response::envelopeError('not_found', $this->app->translator()->t('error.not_found', $locale), $locale, Utils::traceId(), [], 404);
            return;
        }

        Response::envelopeSuccess(['scan' => $scan]);
    }

    /**
     * @param array<string, string> $params
     */
    public function results(Request $request, array $params): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst', 'viewer']);
        if (!$user) {
            return;
        }

        $scanId = (int) ($params['id'] ?? 0);
        Response::envelopeSuccess([
            'scanId' => $scanId,
            'results' => $this->app->scan()->getScanResults($scanId),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function diff(Request $request, array $params): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst', 'viewer']);
        if (!$user) {
            return;
        }

        $scanId = (int) ($params['id'] ?? 0);
        $baselineId = (int) ($params['baselineId'] ?? 0);

        try {
            $diff = $this->app->scan()->diffAgainstBaseline($scanId, $baselineId);
            Response::envelopeSuccess(['diff' => $diff]);
        } catch (\Throwable $e) {
            $locale = $this->app->translator()->resolveLocale($request, $user['locale'] ?? null);
            Response::envelopeError(
                'diff_failed',
                $this->app->translator()->t('error.diff_failed', $locale),
                $locale,
                Utils::traceId(),
                ['reason' => $this->safeErrorMessage($e, 'scan.diff')],
                422
            );
        }
    }

    public function trends(Request $request): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst', 'viewer']);
        if (!$user) {
            return;
        }

        $period = $request->query('period', 'day') ?? 'day';
        if (!in_array($period, ['day', 'week', 'month'], true)) {
            $period = 'day';
        }

        Response::envelopeSuccess([
            'period' => $period,
            'series' => $this->app->scan()->trend($period),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function cancel(Request $request, array $params): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst']);
        if (!$user) {
            return;
        }

        $locale = $this->app->translator()->resolveLocale($request, $user['locale'] ?? null);
        if (!$this->requireCsrf($request, $locale)) {
            return;
        }

        $scanId = (int) ($params['id'] ?? 0);
        $this->app->queue()->markCancelled($scanId);

        Response::envelopeSuccess([
            'scanId' => $scanId,
            'status' => 'cancelled',
        ]);
    }
}
