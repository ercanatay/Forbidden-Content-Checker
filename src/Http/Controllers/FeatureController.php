<?php

declare(strict_types=1);

namespace ForbiddenChecker\Http\Controllers;

use ForbiddenChecker\Http\Request;
use ForbiddenChecker\Http\Response;
use ForbiddenChecker\Support\Utils;

final class FeatureController extends ApiController
{
    // ── Sitemap Discovery ───────────────────────────────────────

    public function discoverSitemap(Request $request): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst']);
        if (!$user) {
            return;
        }

        $locale = $this->app->translator()->resolveLocale($request, $user['locale'] ?? null);
        $payload = $request->json();
        $domain = trim((string) ($payload['domain'] ?? ''));

        if ($domain === '') {
            Response::envelopeError('validation_error', $this->app->translator()->t('error.validation', $locale), $locale, Utils::traceId(), [], 422);
            return;
        }

        try {
            $result = $this->app->sitemapDiscovery()->discover($domain);
            Response::envelopeSuccess([
                'domain' => $domain,
                'urls' => $result['urls'],
                'count' => count($result['urls']),
                'errors' => $result['errors'],
            ]);
        } catch (\Throwable $e) {
            Response::envelopeError(
                'sitemap_discovery_failed',
                $this->app->translator()->t('error.sitemap_failed', $locale),
                $locale,
                Utils::traceId(),
                ['reason' => $this->safeErrorMessage($e, 'sitemap.discover')],
                422
            );
        }
    }

    // ── Bulk Import ─────────────────────────────────────────────

    public function bulkImport(Request $request): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst']);
        if (!$user) {
            return;
        }

        $locale = $this->app->translator()->resolveLocale($request, $user['locale'] ?? null);
        $payload = $request->json();
        $content = (string) ($payload['content'] ?? '');
        $format = (string) ($payload['format'] ?? 'auto');

        if ($content === '') {
            Response::envelopeError('validation_error', $this->app->translator()->t('error.validation', $locale), $locale, Utils::traceId(), [], 422);
            return;
        }

        try {
            $result = $this->app->bulkImport()->parseUrls($content, $format);
            Response::envelopeSuccess([
                'urls' => $result['urls'],
                'count' => count($result['urls']),
                'skipped' => $result['skipped'],
                'errors' => $result['errors'],
            ]);
        } catch (\Throwable $e) {
            Response::envelopeError(
                'bulk_import_failed',
                $this->app->translator()->t('error.bulk_import_failed', $locale),
                $locale,
                Utils::traceId(),
                ['reason' => $this->safeErrorMessage($e, 'bulk.import')],
                422
            );
        }
    }

    // ── Tags ────────────────────────────────────────────────────

    public function listTags(Request $request): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst', 'viewer']);
        if (!$user) {
            return;
        }

        Response::envelopeSuccess(['items' => $this->app->tags()->listTags()]);
    }

    public function createTag(Request $request): void
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
        $name = trim((string) ($payload['name'] ?? ''));
        $color = trim((string) ($payload['color'] ?? '#6b7280'));

        if ($name === '') {
            Response::envelopeError('validation_error', $this->app->translator()->t('error.validation', $locale), $locale, Utils::traceId(), [], 422);
            return;
        }

        try {
            $tag = $this->app->tags()->createTag((int) $user['id'], $name, $color);
            Response::envelopeSuccess(['tag' => $tag], [], 201);
        } catch (\Throwable $e) {
            Response::envelopeError(
                'tag_create_failed',
                $this->app->translator()->t('error.validation', $locale),
                $locale,
                Utils::traceId(),
                ['reason' => $this->safeErrorMessage($e, 'tag.create')],
                422
            );
        }
    }

    public function deleteTag(Request $request, array $params): void
    {
        $user = $this->requireAuth($request, ['admin']);
        if (!$user) {
            return;
        }

        $locale = $this->app->translator()->resolveLocale($request, $user['locale'] ?? null);
        if (!$this->requireCsrf($request, $locale)) {
            return;
        }

        $tagId = (int) ($params['id'] ?? 0);
        $this->app->tags()->deleteTag($tagId);
        Response::envelopeSuccess(['deleted' => true]);
    }

    /**
     * @param array<string, string> $params
     */
    public function attachTags(Request $request, array $params): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst']);
        if (!$user) {
            return;
        }

        $locale = $this->app->translator()->resolveLocale($request, $user['locale'] ?? null);
        if (!$this->requireCsrf($request, $locale)) {
            return;
        }

        $scanJobId = (int) ($params['id'] ?? 0);
        $payload = $request->json();
        $tagIds = $payload['tagIds'] ?? [];

        if (!is_array($tagIds) || count($tagIds) === 0) {
            Response::envelopeError('validation_error', $this->app->translator()->t('error.validation', $locale), $locale, Utils::traceId(), [], 422);
            return;
        }

        $this->app->tags()->attachTags($scanJobId, array_map('intval', $tagIds));
        $tags = $this->app->tags()->getTagsForScanJob($scanJobId);
        Response::envelopeSuccess(['scanJobId' => $scanJobId, 'tags' => $tags]);
    }

    /**
     * @param array<string, string> $params
     */
    public function scanJobTags(Request $request, array $params): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst', 'viewer']);
        if (!$user) {
            return;
        }

        $scanJobId = (int) ($params['id'] ?? 0);
        $tags = $this->app->tags()->getTagsForScanJob($scanJobId);
        Response::envelopeSuccess(['scanJobId' => $scanJobId, 'tags' => $tags]);
    }

    // ── Scheduled Scans ─────────────────────────────────────────

    public function listSchedules(Request $request): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst', 'viewer']);
        if (!$user) {
            return;
        }

        Response::envelopeSuccess(['items' => $this->app->schedules()->listSchedules()]);
    }

    public function createSchedule(Request $request): void
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
            $schedule = $this->app->schedules()->createSchedule((int) $user['id'], $payload);
            Response::envelopeSuccess(['schedule' => $schedule], [], 201);
        } catch (\Throwable $e) {
            Response::envelopeError(
                'schedule_create_failed',
                $this->app->translator()->t('error.validation', $locale),
                $locale,
                Utils::traceId(),
                ['reason' => $this->safeErrorMessage($e, 'schedule.create')],
                422
            );
        }
    }

    /**
     * @param array<string, string> $params
     */
    public function toggleSchedule(Request $request, array $params): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst']);
        if (!$user) {
            return;
        }

        $locale = $this->app->translator()->resolveLocale($request, $user['locale'] ?? null);
        if (!$this->requireCsrf($request, $locale)) {
            return;
        }

        $scheduleId = (int) ($params['id'] ?? 0);
        $payload = $request->json();
        $active = (bool) ($payload['active'] ?? true);

        $this->app->schedules()->toggleSchedule($scheduleId, $active);
        $schedule = $this->app->schedules()->getSchedule($scheduleId);
        Response::envelopeSuccess(['schedule' => $schedule]);
    }

    /**
     * @param array<string, string> $params
     */
    public function deleteSchedule(Request $request, array $params): void
    {
        $user = $this->requireAuth($request, ['admin']);
        if (!$user) {
            return;
        }

        $locale = $this->app->translator()->resolveLocale($request, $user['locale'] ?? null);
        if (!$this->requireCsrf($request, $locale)) {
            return;
        }

        $scheduleId = (int) ($params['id'] ?? 0);
        $this->app->schedules()->deleteSchedule($scheduleId);
        Response::envelopeSuccess(['deleted' => true]);
    }

    // ── Dashboard ───────────────────────────────────────────────

    public function dashboard(Request $request): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst', 'viewer']);
        if (!$user) {
            return;
        }

        Response::envelopeSuccess($this->app->dashboard()->getSummary());
    }
}
