<?php

declare(strict_types=1);

namespace ForbiddenChecker\Infrastructure\Observability;

use PDO;

final class MetricsService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function health(): array
    {
        try {
            $this->pdo->query('SELECT 1')->fetchColumn();
            return ['ok' => true, 'database' => 'up', 'timestamp' => gmdate('c')];
        } catch (\Throwable) {
            return ['ok' => false, 'database' => 'down', 'timestamp' => gmdate('c')];
        }
    }

    public function readiness(): array
    {
        $pending = (int) $this->pdo->query("SELECT COUNT(1) FROM scan_jobs WHERE status IN ('queued', 'running')")->fetchColumn();
        return [
            'ready' => true,
            'pending_jobs' => $pending,
            'timestamp' => gmdate('c'),
        ];
    }

    public function prometheus(): string
    {
        $totalJobs = (int) $this->pdo->query('SELECT COUNT(1) FROM scan_jobs')->fetchColumn();
        $completed = (int) $this->pdo->query("SELECT COUNT(1) FROM scan_jobs WHERE status = 'completed'")->fetchColumn();
        $partial = (int) $this->pdo->query("SELECT COUNT(1) FROM scan_jobs WHERE status = 'partial'")->fetchColumn();
        $failed = (int) $this->pdo->query("SELECT COUNT(1) FROM scan_jobs WHERE status = 'failed'")->fetchColumn();
        $queued = (int) $this->pdo->query("SELECT COUNT(1) FROM scan_jobs WHERE status = 'queued'")->fetchColumn();
        $running = (int) $this->pdo->query("SELECT COUNT(1) FROM scan_jobs WHERE status = 'running'")->fetchColumn();

        $output = [];
        $output[] = '# HELP fcc_scan_jobs_total Total scan jobs.';
        $output[] = '# TYPE fcc_scan_jobs_total counter';
        $output[] = 'fcc_scan_jobs_total ' . $totalJobs;
        $output[] = '# HELP fcc_scan_jobs_status Scan jobs by status.';
        $output[] = '# TYPE fcc_scan_jobs_status gauge';
        $output[] = 'fcc_scan_jobs_status{status="completed"} ' . $completed;
        $output[] = 'fcc_scan_jobs_status{status="partial"} ' . $partial;
        $output[] = 'fcc_scan_jobs_status{status="failed"} ' . $failed;
        $output[] = 'fcc_scan_jobs_status{status="queued"} ' . $queued;
        $output[] = 'fcc_scan_jobs_status{status="running"} ' . $running;

        return implode("\n", $output) . "\n";
    }
}
