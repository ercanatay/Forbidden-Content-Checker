#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

[$app] = fcc_bootstrap();

$argvList = $argv ?? [];

$showStatus = in_array('--status', $argvList, true);
$runCheck = in_array('--check', $argvList, true);
$force = in_array('--force', $argvList, true);
$applyApproved = in_array('--apply-approved', $argvList, true);

if (!$showStatus && !$runCheck && !$applyApproved) {
    fwrite(STDOUT, "Cybokron Forbidden Content Checker Updater\n\n");
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php bin/updater.php --status\n");
    fwrite(STDOUT, "  php bin/updater.php --check [--force]\n");
    fwrite(STDOUT, "  php bin/updater.php --apply-approved\n");
    exit(1);
}

try {
    $state = null;

    if ($runCheck) {
        $state = $app->updates()->checkForUpdates($force, null);
        fwrite(STDOUT, sprintf('[%s] Update check completed. status=%s latest=%s installed=%s%s',
            gmdate('c'),
            (string) ($state['status'] ?? 'unknown'),
            (string) ($state['latestVersion'] ?? '-'),
            (string) ($state['installedVersion'] ?? '-'),
            PHP_EOL
        ));
    }

    if ($applyApproved) {
        $state = $app->updateApplier()->applyApprovedUpdate(null);
        fwrite(STDOUT, sprintf('[%s] Apply approved completed. status=%s installed=%s transport=%s%s',
            gmdate('c'),
            (string) ($state['status'] ?? 'unknown'),
            (string) ($state['installedVersion'] ?? '-'),
            (string) ($state['lastTransport'] ?? '-'),
            PHP_EOL
        ));
    }

    if ($showStatus || $state !== null) {
        $status = $showStatus ? $app->updates()->status() : $state;
        fwrite(STDOUT, json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[ERROR] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
