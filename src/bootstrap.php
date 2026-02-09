<?php

declare(strict_types=1);

use ForbiddenChecker\App;
use ForbiddenChecker\Config;
use ForbiddenChecker\Http\Controllers\AuthController;
use ForbiddenChecker\Http\Controllers\ConfigController;
use ForbiddenChecker\Http\Controllers\HealthController;
use ForbiddenChecker\Http\Controllers\LocaleController;
use ForbiddenChecker\Http\Controllers\ReportController;
use ForbiddenChecker\Http\Controllers\ScanController;
use ForbiddenChecker\Http\Controllers\UiController;
use ForbiddenChecker\Http\Controllers\UpdateController;
use ForbiddenChecker\Http\Request;
use ForbiddenChecker\Http\Response;
use ForbiddenChecker\Http\Router;

if (!class_exists(App::class)) {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    } else {
        spl_autoload_register(static function (string $class): void {
            $prefix = 'ForbiddenChecker\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relative = substr($class, strlen($prefix));
            $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        });
    }
}

/**
 * @return array{0: App, 1: Router, 2: Request}
 */
function fcc_bootstrap(): array
{
    $config = Config::load();

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name((string) $config['session_name']);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $app = new App($config);
    $router = new Router();
    $request = Request::fromGlobals();

    $ui = new UiController($app);
    $auth = new AuthController($app);
    $locale = new LocaleController($app);
    $scan = new ScanController($app);
    $report = new ReportController($app);
    $health = new HealthController($app);
    $configController = new ConfigController($app);
    $updateController = new UpdateController($app);

    $router->add('GET', '/', [$ui, 'app']);
    $router->add('GET', '/index.php', [$ui, 'app']);
    $router->add('GET', '/forbidden_checker.php', [$ui, 'app']);

    $router->add('POST', '/api/v1/auth/login', [$auth, 'login']);
    $router->add('POST', '/api/v1/auth/logout', [$auth, 'logout']);
    $router->add('POST', '/api/v1/auth/tokens', [$auth, 'issueToken']);
    $router->add('GET', '/api/v1/me', [$auth, 'me']);

    $router->add('GET', '/api/v1/locales', [$locale, 'list']);

    $router->add('POST', '/api/v1/scans', [$scan, 'create']);
    $router->add('GET', '/api/v1/scans/{id}', [$scan, 'show']);
    $router->add('GET', '/api/v1/scans/{id}/results', [$scan, 'results']);
    $router->add('GET', '/api/v1/scans/{id}/diff/{baselineId}', [$scan, 'diff']);
    $router->add('POST', '/api/v1/scans/{id}/cancel', [$scan, 'cancel']);
    $router->add('GET', '/api/v1/analytics/trends', [$scan, 'trends']);

    $router->add('GET', '/api/v1/reports/{id}.{format}', [$report, 'download']);

    $router->add('GET', '/api/v1/domain-policies', [$configController, 'listDomainPolicies']);
    $router->add('POST', '/api/v1/domain-policies', [$configController, 'upsertDomainPolicy']);
    $router->add('GET', '/api/v1/suppression-rules', [$configController, 'listSuppressionRules']);
    $router->add('POST', '/api/v1/suppression-rules', [$configController, 'createSuppressionRule']);
    $router->add('GET', '/api/v1/scan-profiles', [$configController, 'listScanProfiles']);
    $router->add('POST', '/api/v1/scan-profiles', [$configController, 'createScanProfile']);
    $router->add('GET', '/api/v1/keyword-sets', [$configController, 'listKeywordSets']);
    $router->add('POST', '/api/v1/keyword-sets', [$configController, 'createKeywordSet']);

    $router->add('GET', '/api/v1/healthz', [$health, 'healthz']);
    $router->add('GET', '/api/v1/readyz', [$health, 'readyz']);
    $router->add('GET', '/api/v1/metrics', [$health, 'metrics']);

    $router->add('GET', '/api/v1/updates/status', [$updateController, 'status']);
    $router->add('POST', '/api/v1/updates/check', [$updateController, 'check']);
    $router->add('POST', '/api/v1/updates/approve', [$updateController, 'approve']);
    $router->add('POST', '/api/v1/updates/revoke-approval', [$updateController, 'revokeApproval']);

    return [$app, $router, $request];
}

/**
 * @param array<string, mixed>|null $legacyInput
 */
function fcc_legacy_scan_response(?array $legacyInput = null): void
{
    [$app] = fcc_bootstrap();

    $input = $legacyInput;
    if ($input === null) {
        $raw = file_get_contents('php://input') ?: '';
        $decoded = json_decode($raw, true);
        $input = is_array($decoded) ? $decoded : [];
    }

    $domain = trim((string) ($input['domain'] ?? ''));
    $extra = trim((string) ($input['extraKeywords'] ?? ''));

    if ($domain === '') {
        Response::json(['error' => 'Invalid or missing domain'], 400);
        return;
    }

    $keywords = ['casino'];
    if ($extra !== '') {
        foreach (explode(',', $extra) as $item) {
            $kw = trim($item);
            if ($kw !== '') {
                $keywords[] = $kw;
            }
        }
    }
    $keywords = array_values(array_unique($keywords));

    $legacyUserId = (int) ($app->pdo()->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1);

    $scan = $app->scan()->createScanJob($legacyUserId, [
        'targets' => [$domain],
        'keywords' => $keywords,
        'sync' => true,
        'keywordMode' => 'exact',
    ]);
    $job = $app->scan()->processScanJob((int) $scan['id'], 'compat-shim');

    $results = $app->scan()->getScanResults((int) $job['id']);

    $casino = [];
    $extraResults = [];
    $fetchDetails = [];
    $error = null;

    foreach ($results as $row) {
        if (isset($row['fetch_details_json']) && is_string($row['fetch_details_json'])) {
            $decodedFetch = json_decode($row['fetch_details_json'], true);
            if (is_array($decodedFetch)) {
                $fetchDetails = array_merge($fetchDetails, $decodedFetch);
            }
        }

        if (!empty($row['error_message']) && $error === null) {
            $error = (string) $row['error_message'];
        }

        if (!empty($row['keyword'])) {
            $tuple = [
                (string) ($row['title'] ?? ''),
                (string) ($row['url'] ?? ''),
                (string) ($row['keyword'] ?? ''),
            ];
            if (strcasecmp((string) $row['keyword'], 'casino') === 0) {
                $casino[] = $tuple;
            } else {
                $extraResults[] = $tuple;
            }
        }
    }

    $status = (string) ($job['status'] ?? 'failed');
    Response::json([
        'domain' => $domain,
        'baseUrl' => $results[0]['base_url'] ?? $domain,
        'casinoResults' => $casino,
        'extraKeywordResults' => $extraResults,
        'status' => in_array($status, ['completed', 'partial'], true) ? 'completed' : 'error',
        'error' => $error,
        'fetchDetails' => $fetchDetails,
        'deprecation' => 'Legacy endpoint is deprecated. Use /api/v1/scans.',
    ], 200, [
        'X-FCC-Deprecated' => 'true',
    ]);
}
