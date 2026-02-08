<?php

declare(strict_types=1);

namespace ForbiddenChecker\Domain\Scan;

use ForbiddenChecker\Domain\Analytics\TrendService;
use ForbiddenChecker\Infrastructure\Logging\Logger;
use ForbiddenChecker\Infrastructure\Notification\NotificationService;
use ForbiddenChecker\Infrastructure\Queue\QueueService;
use PDO;

final class ScanService
{
    /** @var array{allow: array<int, string>, deny: array<int, string>}|null Cached domain policies to avoid per-target DB queries */
    private ?array $domainPoliciesCache = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Scanner $scanner,
        private readonly UrlNormalizer $urlNormalizer,
        private readonly QueueService $queueService,
        private readonly NotificationService $notificationService,
        private readonly TrendService $trendService,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createScanJob(int $userId, array $payload): array
    {
        $targets = $this->normalizeTargets($payload['targets'] ?? []);
        if (count($targets) === 0) {
            throw new \RuntimeException('At least one target is required.');
        }

        [$keywords, $excludeKeywords] = $this->resolveKeywords($payload);
        if (count($keywords) === 0) {
            throw new \RuntimeException('At least one keyword is required after exclusions.');
        }

        $keywordMode = (string) ($payload['keywordMode'] ?? 'exact');
        if (!in_array($keywordMode, ['exact', 'regex'], true)) {
            $keywordMode = 'exact';
        }

        $options = [
            'keyword_mode' => $keywordMode,
            'exact_match' => (bool) ($payload['exactMatch'] ?? false),
            'max_pages' => max(1, min(10, (int) ($payload['maxPages'] ?? 3))),
            'max_results_per_keyword' => max(1, min(50, (int) ($payload['maxResultsPerKeyword'] ?? 5))),
            'baseline_scan_job_id' => isset($payload['baselineScanJobId']) ? (int) $payload['baselineScanJobId'] : null,
            'scan_profile_id' => isset($payload['scanProfileId']) ? (int) $payload['scanProfileId'] : null,
        ];

        $this->pdo->beginTransaction();

        $insert = $this->pdo->prepare(
            "INSERT INTO scan_jobs
             (created_by, status, target_count, keywords_json, exclude_keywords_json, options_json, created_at, updated_at)
             VALUES (:created_by, 'pending', :target_count, :keywords_json, :exclude_keywords_json, :options_json, datetime('now'), datetime('now'))"
        );
        $insert->execute([
            ':created_by' => $userId,
            ':target_count' => count($targets),
            ':keywords_json' => json_encode(array_values($keywords), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':exclude_keywords_json' => json_encode(array_values($excludeKeywords), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':options_json' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $scanJobId = (int) $this->pdo->lastInsertId();
        $targetInsert = $this->pdo->prepare(
            "INSERT INTO scan_targets (scan_job_id, raw_target, normalized_target, created_at)
             VALUES (:scan_job_id, :raw_target, :normalized_target, datetime('now'))"
        );

        foreach ($targets as $target) {
            $targetInsert->execute([
                ':scan_job_id' => $scanJobId,
                ':raw_target' => $target['raw'],
                ':normalized_target' => $target['normalized'],
            ]);
        }

        $this->pdo->commit();

        $this->queueService->enqueue($scanJobId);

        return $this->getScanJob($scanJobId) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getScanJob(int $scanJobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM scan_jobs WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $scanJobId]);
        $job = $stmt->fetch();

        if (!$job) {
            return null;
        }

        $job['keywords'] = json_decode((string) ($job['keywords_json'] ?? '[]'), true) ?: [];
        $job['exclude_keywords'] = json_decode((string) ($job['exclude_keywords_json'] ?? '[]'), true) ?: [];
        $job['options'] = json_decode((string) ($job['options_json'] ?? '{}'), true) ?: [];

        return $job;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getScanResults(int $scanJobId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT sr.*, sm.keyword, sm.title, sm.url, sm.source, sm.severity, sm.is_suppressed
             FROM scan_results sr
             LEFT JOIN scan_matches sm ON sm.scan_result_id = sr.id
             WHERE sr.scan_job_id = :scan_job_id
             ORDER BY sr.id ASC, sm.id ASC"
        );
        $stmt->execute([':scan_job_id' => $scanJobId]);

        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    public function processScanJob(int $scanJobId, ?string $workerId = null): array
    {
        $job = $this->getScanJob($scanJobId);
        if (!$job) {
            throw new \RuntimeException('Scan job not found.');
        }

        if (in_array((string) $job['status'], ['cancelled', 'completed', 'failed', 'partial'], true)) {
            return $job;
        }

        $this->updateJobStatus($scanJobId, 'running', [
            'worker_id' => $workerId,
            'started_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $keywords = is_array($job['keywords']) ? $job['keywords'] : [];
        $options = is_array($job['options']) ? $job['options'] : [];

        $targetsStmt = $this->pdo->prepare('SELECT * FROM scan_targets WHERE scan_job_id = :scan_job_id ORDER BY id ASC');
        $targetsStmt->execute([':scan_job_id' => $scanJobId]);
        $targets = $targetsStmt->fetchAll() ?: [];

        $summary = [
            'completed' => 0,
            'partial' => 0,
            'failed' => 0,
            'cancelled' => 0,
            'matches' => 0,
        ];

        foreach ($targets as $targetRow) {
            $normalizedTarget = (string) ($targetRow['normalized_target'] ?? '');
            $baseUrl = $this->urlNormalizer->baseUrl($normalizedTarget);
            $domain = $baseUrl ? (string) parse_url($baseUrl, PHP_URL_HOST) : '';

            if (!$this->isDomainAllowed($domain)) {
                $this->insertScanResult($scanJobId, (string) $targetRow['raw_target'], $baseUrl, 'cancelled', 'domain_policy_blocked', 'Target blocked by allowlist/denylist policy.', []);
                $summary['cancelled']++;
                continue;
            }

            if ($this->isCircuitOpen($domain)) {
                $this->insertScanResult($scanJobId, (string) $targetRow['raw_target'], $baseUrl, 'cancelled', 'circuit_open', 'Domain circuit breaker is open.', []);
                $summary['cancelled']++;
                continue;
            }

            $scan = $this->scanner->scanTarget($normalizedTarget, $keywords, $options);
            $status = (string) ($scan['status'] ?? 'failed');

            if ($status === 'failed') {
                $this->recordDomainFailure($domain);
            } else {
                $this->resetDomainFailure($domain);
            }

            $errorCode = null;
            $errorMessage = null;
            $errors = $scan['errors'] ?? [];
            if (is_array($errors) && count($errors) > 0) {
                $first = $errors[0];
                if (is_array($first)) {
                    $errorCode = (string) ($first['code'] ?? 'scan_error');
                    $errorMessage = (string) ($first['message'] ?? 'Scan error');
                }
            }

            $scanResultId = $this->insertScanResult(
                $scanJobId,
                (string) $targetRow['raw_target'],
                (string) ($scan['base_url'] ?? $baseUrl),
                $status,
                $errorCode,
                $errorMessage,
                $scan['fetch_details'] ?? []
            );

            $matchCount = 0;
            $matches = $scan['matches'] ?? [];
            if (is_array($matches)) {
                foreach ($matches as $match) {
                    if (!is_array($match)) {
                        continue;
                    }
                    $this->insertMatch($scanResultId, $match);
                    $matchCount++;
                }
            }

            $summary['matches'] += $matchCount;
            if (isset($summary[$status])) {
                $summary[$status]++;
            } else {
                $summary['failed']++;
            }
        }

        $finalStatus = 'completed';
        if ($summary['failed'] > 0 && ($summary['completed'] > 0 || $summary['partial'] > 0)) {
            $finalStatus = 'partial';
        } elseif ($summary['failed'] > 0 && $summary['completed'] === 0 && $summary['partial'] === 0) {
            $finalStatus = 'failed';
        } elseif ($summary['cancelled'] > 0 && $summary['completed'] === 0 && $summary['partial'] === 0 && $summary['failed'] === 0) {
            $finalStatus = 'cancelled';
        } elseif ($summary['partial'] > 0) {
            $finalStatus = 'partial';
        }

        $update = $this->pdo->prepare(
            "UPDATE scan_jobs
             SET status = :status,
                 completed_count = :completed_count,
                 partial_count = :partial_count,
                 failed_count = :failed_count,
                 cancelled_count = :cancelled_count,
                 match_count = :match_count,
                 finished_at = datetime('now'),
                 updated_at = datetime('now')
             WHERE id = :id"
        );
        $update->execute([
            ':status' => $finalStatus,
            ':completed_count' => $summary['completed'],
            ':partial_count' => $summary['partial'],
            ':failed_count' => $summary['failed'],
            ':cancelled_count' => $summary['cancelled'],
            ':match_count' => $summary['matches'],
            ':id' => $scanJobId,
        ]);

        $finalJob = $this->getScanJob($scanJobId) ?? [];
        $this->notificationService->notifyScanCompleted($scanJobId, [
            'status' => $finalStatus,
            'summary' => $summary,
        ]);

        return $finalJob;
    }

    /**
     * @return array<string, mixed>
     */
    public function diffAgainstBaseline(int $scanJobId, int $baselineScanJobId): array
    {
        $current = $this->collectMatchSignatures($scanJobId);
        $baseline = $this->collectMatchSignatures($baselineScanJobId);

        $new = array_values(array_diff(array_keys($current), array_keys($baseline)));
        $resolved = array_values(array_diff(array_keys($baseline), array_keys($current)));
        $unchanged = array_values(array_intersect(array_keys($current), array_keys($baseline)));

        return [
            'scanJobId' => $scanJobId,
            'baselineScanJobId' => $baselineScanJobId,
            'new' => array_map(static fn (string $signature): array => $current[$signature], $new),
            'resolved' => array_map(static fn (string $signature): array => $baseline[$signature], $resolved),
            'unchanged_count' => count($unchanged),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function trend(string $period): array
    {
        return $this->trendService->trendBy($period);
    }

    private function updateJobStatus(int $scanJobId, string $status, array $extra = []): void
    {
        $fields = ['status = :status', "updated_at = datetime('now')"];
        $params = [':status' => $status, ':id' => $scanJobId];

        foreach ($extra as $key => $value) {
            $fields[] = $key . ' = :' . $key;
            $params[':' . $key] = $value;
        }

        $sql = 'UPDATE scan_jobs SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * @param array<string, mixed> $match
     */
    private function insertMatch(int $scanResultId, array $match): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO scan_matches
             (scan_result_id, keyword, title, url, source, severity, is_suppressed, created_at)
             VALUES (:scan_result_id, :keyword, :title, :url, :source, :severity, :is_suppressed, datetime('now'))"
        );
        $stmt->execute([
            ':scan_result_id' => $scanResultId,
            ':keyword' => (string) ($match['keyword'] ?? ''),
            ':title' => (string) ($match['title'] ?? ''),
            ':url' => (string) ($match['url'] ?? ''),
            ':source' => (string) ($match['source'] ?? 'unknown'),
            ':severity' => (int) ($match['severity'] ?? 0),
            ':is_suppressed' => (int) (($match['suppressed'] ?? false) ? 1 : 0),
        ]);
    }

    /**
     * @param array<int, mixed> $fetchDetails
     */
    private function insertScanResult(
        int $scanJobId,
        string $target,
        ?string $baseUrl,
        string $status,
        ?string $errorCode,
        ?string $errorMessage,
        array $fetchDetails
    ): int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO scan_results
             (scan_job_id, target, base_url, status, error_code, error_message, fetch_details_json, created_at)
             VALUES (:scan_job_id, :target, :base_url, :status, :error_code, :error_message, :fetch_details_json, datetime('now'))"
        );

        $stmt->execute([
            ':scan_job_id' => $scanJobId,
            ':target' => $target,
            ':base_url' => $baseUrl,
            ':status' => $status,
            ':error_code' => $errorCode,
            ':error_message' => $errorMessage,
            ':fetch_details_json' => json_encode($fetchDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    private function resolveKeywords(array $payload): array
    {
        $keywords = [];
        $excludeKeywords = [];

        if (isset($payload['scanProfileId'])) {
            $profileId = (int) $payload['scanProfileId'];
            $stmt = $this->pdo->prepare('SELECT * FROM scan_profiles WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $profileId]);
            $profile = $stmt->fetch();
            if ($profile) {
                $fromProfile = json_decode((string) ($profile['keywords_json'] ?? '[]'), true);
                if (is_array($fromProfile)) {
                    foreach ($fromProfile as $kw) {
                        $kw = trim((string) $kw);
                        if ($kw !== '') {
                            $keywords[] = $kw;
                        }
                    }
                }
            }
        }

        if (isset($payload['keywordSetId'])) {
            $keywordSetId = (int) $payload['keywordSetId'];
            $stmt = $this->pdo->prepare('SELECT keyword, group_type FROM keyword_terms WHERE keyword_set_id = :id');
            $stmt->execute([':id' => $keywordSetId]);
            $rows = $stmt->fetchAll() ?: [];
            foreach ($rows as $row) {
                $kw = trim((string) ($row['keyword'] ?? ''));
                if ($kw === '') {
                    continue;
                }
                if ((string) ($row['group_type'] ?? 'include') === 'exclude') {
                    $excludeKeywords[] = $kw;
                } else {
                    $keywords[] = $kw;
                }
            }
        }

        $payloadKeywords = $payload['keywords'] ?? ['casino'];
        if (is_string($payloadKeywords)) {
            $payloadKeywords = explode(',', $payloadKeywords);
        }
        if (is_array($payloadKeywords)) {
            foreach ($payloadKeywords as $kw) {
                $kw = trim((string) $kw);
                if ($kw !== '') {
                    $keywords[] = $kw;
                }
            }
        }

        $payloadExcludes = $payload['excludeKeywords'] ?? [];
        if (is_string($payloadExcludes)) {
            $payloadExcludes = explode(',', $payloadExcludes);
        }
        if (is_array($payloadExcludes)) {
            foreach ($payloadExcludes as $kw) {
                $kw = trim((string) $kw);
                if ($kw !== '') {
                    $excludeKeywords[] = $kw;
                }
            }
        }

        $keywords = array_values(array_unique($keywords));
        $excludeKeywords = array_values(array_unique($excludeKeywords));

        if (count($excludeKeywords) > 0) {
            $keywords = array_values(array_filter($keywords, static fn (string $kw): bool => !in_array($kw, $excludeKeywords, true)));
        }

        return [$keywords, $excludeKeywords];
    }

    /**
     * @param mixed $targetsRaw
     * @return array<int, array{raw: string, normalized: string}>
     */
    private function normalizeTargets(mixed $targetsRaw): array
    {
        $targets = [];
        if (is_string($targetsRaw)) {
            $targetsRaw = preg_split('/\r\n|\n|\r/', $targetsRaw) ?: [];
        }

        if (!is_array($targetsRaw)) {
            return [];
        }

        foreach ($targetsRaw as $target) {
            $raw = trim((string) $target);
            if ($raw === '') {
                continue;
            }
            $normalized = $this->urlNormalizer->normalizeInput($raw);
            if ($normalized === null) {
                continue;
            }
            $targets[] = [
                'raw' => $raw,
                'normalized' => $normalized,
            ];
        }

        $unique = [];
        foreach ($targets as $t) {
            $unique[$t['normalized']] = $t;
        }

        return array_values($unique);
    }

    /**
     * Loads and caches domain policies (allow/deny lists) from the database.
     * Policies are stable during a scan run, so a single query replaces O(T)
     * queries where T = number of targets.
     *
     * @return array{allow: array<int, string>, deny: array<int, string>}
     */
    private function loadDomainPolicies(): array
    {
        if ($this->domainPoliciesCache !== null) {
            return $this->domainPoliciesCache;
        }

        $stmt = $this->pdo->query("SELECT list_type, domain FROM domain_policies");
        $rows = $stmt->fetchAll() ?: [];

        $allow = [];
        $deny = [];
        foreach ($rows as $row) {
            $d = strtolower((string) ($row['domain'] ?? ''));
            if ((string) ($row['list_type'] ?? '') === 'allow') {
                $allow[] = $d;
            } else {
                $deny[] = $d;
            }
        }

        $this->domainPoliciesCache = ['allow' => $allow, 'deny' => $deny];

        return $this->domainPoliciesCache;
    }

    private function isDomainAllowed(string $domain): bool
    {
        if ($domain === '') {
            return false;
        }

        $policies = $this->loadDomainPolicies();
        $lowerDomain = strtolower($domain);

        // If an allowlist exists, the domain must be on it
        if (count($policies['allow']) > 0) {
            if (!in_array($lowerDomain, $policies['allow'], true)) {
                return false;
            }
        }

        // Domain must not be on the denylist
        return !in_array($lowerDomain, $policies['deny'], true);
    }

    private function isCircuitOpen(string $domain): bool
    {
        if ($domain === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            "SELECT open_until FROM domain_circuit WHERE domain = :domain LIMIT 1"
        );
        $stmt->execute([':domain' => strtolower($domain)]);
        $row = $stmt->fetch();

        if (!$row || !isset($row['open_until']) || $row['open_until'] === null) {
            return false;
        }

        return strtotime((string) $row['open_until']) > time();
    }

    private function recordDomainFailure(string $domain): void
    {
        if ($domain === '') {
            return;
        }

        $domain = strtolower($domain);
        $stmt = $this->pdo->prepare('SELECT failure_count FROM domain_circuit WHERE domain = :domain LIMIT 1');
        $stmt->execute([':domain' => $domain]);
        $row = $stmt->fetch();

        $failureCount = 1;
        if ($row) {
            $failureCount = ((int) $row['failure_count']) + 1;
            $openUntil = $failureCount >= 3 ? gmdate('Y-m-d H:i:s', time() + 300) : null;
            $update = $this->pdo->prepare(
                "UPDATE domain_circuit
                 SET failure_count = :failure_count, last_failure_at = datetime('now'), open_until = :open_until
                 WHERE domain = :domain"
            );
            $update->execute([
                ':failure_count' => $failureCount,
                ':open_until' => $openUntil,
                ':domain' => $domain,
            ]);
            return;
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO domain_circuit (domain, failure_count, last_failure_at, open_until)
             VALUES (:domain, 1, datetime('now'), NULL)"
        );
        $insert->execute([':domain' => $domain]);
    }

    private function resetDomainFailure(string $domain): void
    {
        if ($domain === '') {
            return;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE domain_circuit
             SET failure_count = 0, open_until = NULL
             WHERE domain = :domain"
        );
        $stmt->execute([':domain' => strtolower($domain)]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function collectMatchSignatures(int $scanJobId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT sm.keyword, sm.url, sm.title, sm.severity, sr.target
             FROM scan_results sr
             JOIN scan_matches sm ON sm.scan_result_id = sr.id
             WHERE sr.scan_job_id = :scan_job_id AND sm.is_suppressed = 0"
        );
        $stmt->execute([':scan_job_id' => $scanJobId]);
        $rows = $stmt->fetchAll() ?: [];

        $map = [];
        foreach ($rows as $row) {
            $signature = strtolower((string) $row['keyword']) . '|' . strtolower((string) $row['url']);
            $map[$signature] = [
                'keyword' => (string) $row['keyword'],
                'url' => (string) $row['url'],
                'title' => (string) $row['title'],
                'severity' => (int) $row['severity'],
                'target' => (string) $row['target'],
            ];
        }

        return $map;
    }
}
