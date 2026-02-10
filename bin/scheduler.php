<?php

declare(strict_types=1);

/**
 * Scheduled scan runner.
 *
 * Usage:
 *   php bin/scheduler.php              Run all due scheduled scans
 *   php bin/scheduler.php --list       List all scheduled scans
 *   php bin/scheduler.php --once       Run due scans once and exit
 *
 * Recommended: Add to crontab for periodic execution:
 *   * * * * * php /path/to/bin/scheduler.php --once >> /path/to/storage/scheduler.log 2>&1
 */

require_once __DIR__ . '/../src/bootstrap.php';

// Minimal bootstrap without session
$config = ForbiddenChecker\Config::load();
$app = new ForbiddenChecker\App($config);

$action = $argv[1] ?? '--once';

switch ($action) {
    case '--list':
        $schedules = $app->schedules()->listSchedules();
        echo "Scheduled scans: " . count($schedules) . "\n";
        echo str_repeat('-', 80) . "\n";
        foreach ($schedules as $s) {
            $active = ((int) ($s['is_active'] ?? 0)) === 1 ? 'ACTIVE' : 'PAUSED';
            echo sprintf(
                "[%d] %s (%s) cron=%s next=%s last=%s\n",
                (int) ($s['id'] ?? 0),
                (string) ($s['name'] ?? ''),
                $active,
                (string) ($s['schedule_cron'] ?? ''),
                (string) ($s['next_run_at'] ?? 'never'),
                (string) ($s['last_run_at'] ?? 'never')
            );
        }
        break;

    case '--once':
    default:
        echo "[" . gmdate('Y-m-d H:i:s') . "] Running due scheduled scans...\n";
        $results = $app->schedules()->runDueSchedules();
        echo "[" . gmdate('Y-m-d H:i:s') . "] Completed: " . count($results) . " schedule(s) processed.\n";
        foreach ($results as $r) {
            echo sprintf(
                "  Schedule #%d => Scan Job #%d (%s)\n",
                (int) ($r['schedule_id'] ?? 0),
                (int) ($r['scan_job_id'] ?? 0),
                (string) ($r['status'] ?? 'unknown')
            );
        }
        break;
}
