#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

[$app] = fcc_bootstrap();

$workerId = 'worker-' . getmypid();
$queue = $app->queue();
$scanService = $app->scan();

$queue->recoverStaleJobs();

$once = in_array('--once', $argv, true);

while (true) {
    $job = $queue->claimNext($workerId);
    if ($job === null) {
        if ($once) {
            exit(0);
        }
        sleep(2);
        continue;
    }

    $scanJobId = (int) $job['id'];
    try {
        $scanService->processScanJob($scanJobId, $workerId);
        echo '[' . gmdate('c') . '] processed scan job #' . $scanJobId . PHP_EOL;
    } catch (Throwable $e) {
        $stmt = $app->pdo()->prepare(
            "UPDATE scan_jobs
             SET status = 'failed',
                 finished_at = datetime('now'),
                 updated_at = datetime('now')
             WHERE id = :id"
        );
        $stmt->execute([':id' => $scanJobId]);

        $app->logger()->error('Worker failed to process job.', [
            'scanJobId' => $scanJobId,
            'message' => $e->getMessage(),
        ]);

        echo '[' . gmdate('c') . '] failed scan job #' . $scanJobId . ' - ' . $e->getMessage() . PHP_EOL;
    }

    if ($once) {
        exit(0);
    }
}
