<?php

declare(strict_types=1);

namespace ForbiddenChecker\Domain\Scan;

use ForbiddenChecker\Infrastructure\Logging\Logger;
use PDO;

final class ScheduleService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ScanService $scanService,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createSchedule(int $userId, array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $targets = $payload['targets'] ?? [];
        $keywords = $payload['keywords'] ?? [];
        $excludeKeywords = $payload['excludeKeywords'] ?? [];
        $options = $payload['options'] ?? [];
        $cron = trim((string) ($payload['cron'] ?? ''));

        if ($name === '') {
            throw new \RuntimeException('Schedule name is required.');
        }

        if (!is_array($targets) || count($targets) === 0) {
            throw new \RuntimeException('At least one target is required.');
        }

        if (!is_array($keywords) || count($keywords) === 0) {
            throw new \RuntimeException('At least one keyword is required.');
        }

        if (!$this->isValidCron($cron)) {
            throw new \RuntimeException('Invalid cron expression. Use: daily, weekly, monthly, or a 5-part cron expression.');
        }

        $nextRun = $this->calculateNextRun($cron);

        $stmt = $this->pdo->prepare(
            "INSERT INTO scheduled_scans
             (name, targets_json, keywords_json, exclude_keywords_json, options_json, schedule_cron, is_active, next_run_at, created_by, created_at, updated_at)
             VALUES (:name, :targets_json, :keywords_json, :exclude_keywords_json, :options_json, :schedule_cron, 1, :next_run_at, :created_by, datetime('now'), datetime('now'))"
        );

        $stmt->execute([
            ':name' => $name,
            ':targets_json' => json_encode(is_array($targets) ? $targets : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':keywords_json' => json_encode(is_array($keywords) ? $keywords : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':exclude_keywords_json' => json_encode(is_array($excludeKeywords) ? $excludeKeywords : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':options_json' => json_encode(is_array($options) ? $options : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':schedule_cron' => $cron,
            ':next_run_at' => $nextRun,
            ':created_by' => $userId,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        return $this->getSchedule($id) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSchedule(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM scheduled_scans WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        return $this->hydrate($row);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSchedules(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM scheduled_scans ORDER BY id DESC');
        $rows = $stmt->fetchAll() ?: [];
        return array_map([$this, 'hydrate'], $rows);
    }

    public function toggleSchedule(int $id, bool $active): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_scans SET is_active = :active, updated_at = datetime('now') WHERE id = :id"
        );
        $stmt->execute([':active' => $active ? 1 : 0, ':id' => $id]);
    }

    public function deleteSchedule(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM scheduled_scans WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Run all due scheduled scans. Called from CLI scheduler.
     *
     * @return array<int, array<string, mixed>>
     */
    public function runDueSchedules(): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "SELECT * FROM scheduled_scans WHERE is_active = 1 AND next_run_at <= :now ORDER BY next_run_at ASC"
        );
        $stmt->execute([':now' => $now]);
        $schedules = $stmt->fetchAll() ?: [];

        $results = [];
        foreach ($schedules as $schedule) {
            try {
                $result = $this->executeSingleSchedule($schedule);
                $results[] = $result;
            } catch (\Throwable $e) {
                $this->logger->error('Scheduled scan failed: ' . $e->getMessage(), [
                    'schedule_id' => $schedule['id'] ?? 0,
                ]);
                $results[] = [
                    'schedule_id' => (int) ($schedule['id'] ?? 0),
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $schedule
     * @return array<string, mixed>
     */
    private function executeSingleSchedule(array $schedule): array
    {
        $scheduleId = (int) ($schedule['id'] ?? 0);
        $userId = (int) ($schedule['created_by'] ?? 0);
        $targets = json_decode((string) ($schedule['targets_json'] ?? '[]'), true) ?: [];
        $keywords = json_decode((string) ($schedule['keywords_json'] ?? '[]'), true) ?: [];
        $excludeKeywords = json_decode((string) ($schedule['exclude_keywords_json'] ?? '[]'), true) ?: [];
        $options = json_decode((string) ($schedule['options_json'] ?? '{}'), true) ?: [];
        $cron = (string) ($schedule['schedule_cron'] ?? 'daily');

        $job = $this->scanService->createScanJob($userId, [
            'targets' => $targets,
            'keywords' => $keywords,
            'excludeKeywords' => $excludeKeywords,
            'sync' => true,
            ...$options,
        ]);

        $job = $this->scanService->processScanJob((int) $job['id'], 'scheduler');

        $nextRun = $this->calculateNextRun($cron);

        $update = $this->pdo->prepare(
            "UPDATE scheduled_scans
             SET last_run_at = datetime('now'), next_run_at = :next_run_at, last_scan_job_id = :job_id, updated_at = datetime('now')
             WHERE id = :id"
        );
        $update->execute([
            ':next_run_at' => $nextRun,
            ':job_id' => (int) $job['id'],
            ':id' => $scheduleId,
        ]);

        $this->logger->info('Scheduled scan completed', [
            'schedule_id' => $scheduleId,
            'scan_job_id' => (int) $job['id'],
        ]);

        return [
            'schedule_id' => $scheduleId,
            'scan_job_id' => (int) $job['id'],
            'status' => (string) ($job['status'] ?? 'unknown'),
        ];
    }

    private function isValidCron(string $cron): bool
    {
        $shortcuts = ['daily', 'weekly', 'monthly', 'hourly'];
        if (in_array($cron, $shortcuts, true)) {
            return true;
        }

        // Basic 5-part cron validation: minute hour day month weekday
        $parts = preg_split('/\s+/', trim($cron));
        return is_array($parts) && count($parts) === 5;
    }

    private function calculateNextRun(string $cron): string
    {
        $now = time();

        switch ($cron) {
            case 'hourly':
                return gmdate('Y-m-d H:i:s', $now + 3600);
            case 'daily':
                return gmdate('Y-m-d H:i:s', $now + 86400);
            case 'weekly':
                return gmdate('Y-m-d H:i:s', $now + 604800);
            case 'monthly':
                return gmdate('Y-m-d H:i:s', $now + 2592000);
            default:
                // For custom cron: approximate next run as 1 hour
                return gmdate('Y-m-d H:i:s', $now + 3600);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $row['targets'] = json_decode((string) ($row['targets_json'] ?? '[]'), true) ?: [];
        $row['keywords'] = json_decode((string) ($row['keywords_json'] ?? '[]'), true) ?: [];
        $row['exclude_keywords'] = json_decode((string) ($row['exclude_keywords_json'] ?? '[]'), true) ?: [];
        $row['options'] = json_decode((string) ($row['options_json'] ?? '{}'), true) ?: [];
        return $row;
    }
}
