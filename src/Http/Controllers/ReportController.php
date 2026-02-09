<?php

declare(strict_types=1);

namespace ForbiddenChecker\Http\Controllers;

use ForbiddenChecker\Http\Request;
use ForbiddenChecker\Http\Response;
use ForbiddenChecker\Support\Utils;

final class ReportController extends ApiController
{
    /**
     * @param array<string, string> $params
     */
    public function download(Request $request, array $params): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst', 'viewer']);
        if (!$user) {
            return;
        }

        $scanId = (int) ($params['id'] ?? 0);
        $format = (string) ($params['format'] ?? 'csv');
        $locale = $this->app->translator()->resolveLocale($request, $user['locale'] ?? null);

        try {
            $report = $this->app->reports()->export($scanId, $format);
            Response::file($report['path'], $report['mime'], $report['name']);
        } catch (\Throwable $e) {
            Response::envelopeError(
                'report_export_failed',
                $this->app->translator()->t('error.report_export_failed', $locale),
                $locale,
                Utils::traceId(),
                ['reason' => $this->safeErrorMessage($e, 'report.export')],
                422
            );
        }
    }
}
