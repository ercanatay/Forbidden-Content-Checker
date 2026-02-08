<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$isLegacyAjax = $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isLegacyAjax) {
    fcc_legacy_scan_response();
    exit;
}

header('X-FCC-Deprecated: true');
header('Location: /', true, 302);
exit;
