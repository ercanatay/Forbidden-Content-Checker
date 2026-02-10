<?php

declare(strict_types=1);

namespace ForbiddenChecker\Domain\Analytics;

use PDO;

final class DashboardService
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        return [
            'overview' => $this->getOverview(),
            'recent_scans' => $this->getRecentScans(),
            'top_keywords' => $this->getTopKeywords(),
            'status_breakdown' => $this->getStatusBreakdown(),
            'daily_activity' => $this->getDailyActivity(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getOverview(): array
    {
        $totalScans = $this->pdo->query('SELECT COUNT(*) FROM scan_jobs')->fetchColumn();
        $totalTargets = $this->pdo->query('SELECT SUM(target_count) FROM scan_jobs')->fetchColumn();
        $totalMatches = $this->pdo->query('SELECT SUM(match_count) FROM scan_jobs')->fetchColumn();
        $activeSchedules = $this->pdo->query('SELECT COUNT(*) FROM scheduled_scans WHERE is_active = 1')->fetchColumn();
        $totalTags = $this->pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn();

        return [
            'total_scans' => (int) ($totalScans ?: 0),
            'total_targets' => (int) ($totalTargets ?: 0),
            'total_matches' => (int) ($totalMatches ?: 0),
            'active_schedules' => (int) ($activeSchedules ?: 0),
            'total_tags' => (int) ($totalTags ?: 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRecentScans(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, status, target_count, match_count, created_at, finished_at
             FROM scan_jobs
             ORDER BY created_at DESC
             LIMIT 10'
        );
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getTopKeywords(): array
    {
        $stmt = $this->pdo->query(
            'SELECT keyword, COUNT(*) AS match_count
             FROM scan_matches
             WHERE is_suppressed = 0
             GROUP BY keyword
             ORDER BY match_count DESC
             LIMIT 10'
        );
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getStatusBreakdown(): array
    {
        $stmt = $this->pdo->query(
            'SELECT status, COUNT(*) AS count
             FROM scan_jobs
             GROUP BY status
             ORDER BY count DESC'
        );
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getDailyActivity(): array
    {
        $stmt = $this->pdo->query(
            "SELECT DATE(created_at) AS day, COUNT(*) AS scan_count, SUM(match_count) AS total_matches
             FROM scan_jobs
             WHERE created_at >= datetime('now', '-30 days')
             GROUP BY DATE(created_at)
             ORDER BY day DESC"
        );
        return $stmt->fetchAll() ?: [];
    }
}
