<?php

declare(strict_types=1);

namespace ForbiddenChecker\Http\Controllers;

use ForbiddenChecker\Http\Request;
use ForbiddenChecker\Http\Response;
use ForbiddenChecker\Support\Utils;

final class ConfigController extends ApiController
{
    public function listDomainPolicies(Request $request): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst', 'viewer']);
        if (!$user) {
            return;
        }

        $stmt = $this->app->pdo()->query('SELECT id, list_type, domain, created_by, created_at FROM domain_policies ORDER BY list_type, domain');
        Response::envelopeSuccess(['items' => $stmt->fetchAll() ?: []]);
    }

    public function upsertDomainPolicy(Request $request): void
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
        $listType = strtolower(trim((string) ($payload['listType'] ?? '')));
        $domain = strtolower(trim((string) ($payload['domain'] ?? '')));

        if (!in_array($listType, ['allow', 'deny'], true) || $domain === '') {
            Response::envelopeError('validation_error', $this->app->translator()->t('error.validation', $locale), $locale, Utils::traceId(), [], 422);
            return;
        }

        $stmt = $this->app->pdo()->prepare(
            "INSERT INTO domain_policies (list_type, domain, created_by, created_at)
             VALUES (:list_type, :domain, :created_by, datetime('now'))"
        );
        $stmt->execute([
            ':list_type' => $listType,
            ':domain' => $domain,
            ':created_by' => (int) $user['id'],
        ]);

        Response::envelopeSuccess(['id' => (int) $this->app->pdo()->lastInsertId()], [], 201);
    }

    public function listSuppressionRules(Request $request): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst', 'viewer']);
        if (!$user) {
            return;
        }

        $stmt = $this->app->pdo()->query('SELECT id, name, pattern, scope_domain, is_active, created_by, created_at FROM suppression_rules ORDER BY id DESC');
        Response::envelopeSuccess(['items' => $stmt->fetchAll() ?: []]);
    }

    public function createSuppressionRule(Request $request): void
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
        $pattern = trim((string) ($payload['pattern'] ?? ''));
        $scopeDomain = trim((string) ($payload['scopeDomain'] ?? ''));

        if ($name === '' || $pattern === '') {
            Response::envelopeError('validation_error', $this->app->translator()->t('error.validation', $locale), $locale, Utils::traceId(), [], 422);
            return;
        }

        $stmt = $this->app->pdo()->prepare(
            "INSERT INTO suppression_rules
             (name, pattern, scope_domain, is_active, created_by, created_at)
             VALUES (:name, :pattern, :scope_domain, 1, :created_by, datetime('now'))"
        );
        $stmt->execute([
            ':name' => $name,
            ':pattern' => $pattern,
            ':scope_domain' => $scopeDomain === '' ? null : $scopeDomain,
            ':created_by' => (int) $user['id'],
        ]);

        Response::envelopeSuccess(['id' => (int) $this->app->pdo()->lastInsertId()], [], 201);
    }

    public function listScanProfiles(Request $request): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst', 'viewer']);
        if (!$user) {
            return;
        }

        $stmt = $this->app->pdo()->query('SELECT id, name, keywords_json, options_json, created_by, created_at FROM scan_profiles ORDER BY id DESC');
        $rows = $stmt->fetchAll() ?: [];
        foreach ($rows as &$row) {
            $row['keywords'] = json_decode((string) ($row['keywords_json'] ?? '[]'), true) ?: [];
            $row['options'] = json_decode((string) ($row['options_json'] ?? '{}'), true) ?: [];
        }
        unset($row);

        Response::envelopeSuccess(['items' => $rows]);
    }

    public function createScanProfile(Request $request): void
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
        $keywords = $payload['keywords'] ?? [];
        $options = $payload['options'] ?? [];

        if ($name === '' || !is_array($keywords) || count($keywords) === 0) {
            Response::envelopeError('validation_error', $this->app->translator()->t('error.validation', $locale), $locale, Utils::traceId(), [], 422);
            return;
        }

        $stmt = $this->app->pdo()->prepare(
            "INSERT INTO scan_profiles (name, keywords_json, options_json, created_by, created_at)
             VALUES (:name, :keywords_json, :options_json, :created_by, datetime('now'))"
        );
        $stmt->execute([
            ':name' => $name,
            ':keywords_json' => json_encode($keywords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':options_json' => json_encode(is_array($options) ? $options : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':created_by' => (int) $user['id'],
        ]);

        Response::envelopeSuccess(['id' => (int) $this->app->pdo()->lastInsertId()], [], 201);
    }

    public function listKeywordSets(Request $request): void
    {
        $user = $this->requireAuth($request, ['admin', 'analyst', 'viewer']);
        if (!$user) {
            return;
        }

        $setsStmt = $this->app->pdo()->query('SELECT id, name, description, created_by, created_at FROM keyword_sets ORDER BY id DESC');
        $sets = $setsStmt->fetchAll() ?: [];

        foreach ($sets as &$set) {
            $termsStmt = $this->app->pdo()->prepare('SELECT id, keyword, group_type FROM keyword_terms WHERE keyword_set_id = :id ORDER BY id ASC');
            $termsStmt->execute([':id' => (int) $set['id']]);
            $set['terms'] = $termsStmt->fetchAll() ?: [];
        }
        unset($set);

        Response::envelopeSuccess(['items' => $sets]);
    }

    public function createKeywordSet(Request $request): void
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
        $description = trim((string) ($payload['description'] ?? ''));
        $terms = $payload['terms'] ?? [];

        if ($name === '' || !is_array($terms) || count($terms) === 0) {
            Response::envelopeError('validation_error', $this->app->translator()->t('error.validation', $locale), $locale, Utils::traceId(), [], 422);
            return;
        }

        $this->app->pdo()->beginTransaction();

        $setStmt = $this->app->pdo()->prepare(
            "INSERT INTO keyword_sets (name, description, created_by, created_at)
             VALUES (:name, :description, :created_by, datetime('now'))"
        );
        $setStmt->execute([
            ':name' => $name,
            ':description' => $description,
            ':created_by' => (int) $user['id'],
        ]);

        $setId = (int) $this->app->pdo()->lastInsertId();
        $termStmt = $this->app->pdo()->prepare(
            "INSERT INTO keyword_terms (keyword_set_id, keyword, group_type, created_at)
             VALUES (:keyword_set_id, :keyword, :group_type, datetime('now'))"
        );

        foreach ($terms as $term) {
            if (!is_array($term)) {
                continue;
            }
            $keyword = trim((string) ($term['keyword'] ?? ''));
            $groupType = strtolower(trim((string) ($term['groupType'] ?? 'include')));
            if ($keyword === '' || !in_array($groupType, ['include', 'exclude'], true)) {
                continue;
            }
            $termStmt->execute([
                ':keyword_set_id' => $setId,
                ':keyword' => $keyword,
                ':group_type' => $groupType,
            ]);
        }

        $this->app->pdo()->commit();

        Response::envelopeSuccess(['id' => $setId], [], 201);
    }
}
