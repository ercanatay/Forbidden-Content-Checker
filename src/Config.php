<?php

declare(strict_types=1);

namespace ForbiddenChecker;

final class Config
{
    /**
     * @return array<string, mixed>
     */
    public static function load(): array
    {
        $root = dirname(__DIR__);
        $storageDir = $root . '/storage';
        if (!is_dir($storageDir)) {
            mkdir($storageDir, 0775, true);
        }

        $logDir = $storageDir . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $reportDir = $storageDir . '/reports';
        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0775, true);
        }

        return [
            'app_name' => getenv('FCC_APP_NAME') ?: 'Forbidden Content Checker v3',
            'app_env' => getenv('FCC_APP_ENV') ?: 'production',
            'app_debug' => filter_var(getenv('FCC_APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOL),
            'app_secret' => getenv('FCC_APP_SECRET') ?: 'change-me-in-production',
            'default_locale' => getenv('FCC_DEFAULT_LOCALE') ?: 'en-US',
            'db_path' => getenv('FCC_DB_PATH') ?: $storageDir . '/checker.sqlite',
            'log_file' => getenv('FCC_LOG_FILE') ?: $logDir . '/app.log',
            'report_dir' => $reportDir,
            'max_concurrency' => (int) (getenv('FCC_MAX_CONCURRENCY') ?: 5),
            'request_timeout' => (int) (getenv('FCC_REQUEST_TIMEOUT') ?: 15),
            'max_pages' => (int) (getenv('FCC_MAX_PAGES') ?: 3),
            'max_results_per_keyword' => (int) (getenv('FCC_MAX_RESULTS_PER_KEYWORD') ?: 5),
            'max_retries' => (int) (getenv('FCC_MAX_RETRIES') ?: 2),
            'allow_private_network' => filter_var(getenv('FCC_ALLOW_PRIVATE_NETWORK') ?: '0', FILTER_VALIDATE_BOOL),
            'session_name' => getenv('FCC_SESSION_NAME') ?: 'fcc_session',
            'webhook_url' => getenv('FCC_WEBHOOK_URL') ?: '',
            'email_from' => getenv('FCC_EMAIL_FROM') ?: 'noreply@example.com',
            'email_enabled' => filter_var(getenv('FCC_EMAIL_ENABLED') ?: '0', FILTER_VALIDATE_BOOL),
            'rate_limit_global' => (int) (getenv('FCC_RATE_LIMIT_GLOBAL') ?: 300),
            'rate_limit_user' => (int) (getenv('FCC_RATE_LIMIT_USER') ?: 120),
            'rate_limit_window_sec' => (int) (getenv('FCC_RATE_LIMIT_WINDOW_SEC') ?: 60),
            'worker_stale_after_sec' => (int) (getenv('FCC_WORKER_STALE_AFTER_SEC') ?: 120),
            'compat_legacy_enabled' => filter_var(getenv('FCC_COMPAT_LEGACY_ENABLED') ?: '1', FILTER_VALIDATE_BOOL),
            'update_enabled' => filter_var(getenv('FCC_UPDATE_ENABLED') ?: '1', FILTER_VALIDATE_BOOL),
            'update_repo' => getenv('FCC_UPDATE_REPO') ?: 'ercanatay/Forbidden-Content-Checker',
            'update_remote' => getenv('FCC_UPDATE_REMOTE') ?: 'origin',
            'update_branch' => getenv('FCC_UPDATE_BRANCH') ?: 'main',
            'update_check_interval_sec' => (int) (getenv('FCC_UPDATE_CHECK_INTERVAL_SEC') ?: 21600),
            'update_require_approval' => filter_var(getenv('FCC_UPDATE_REQUIRE_APPROVAL') ?: '1', FILTER_VALIDATE_BOOL),
            'update_allow_zip_fallback' => filter_var(getenv('FCC_UPDATE_ALLOW_ZIP_FALLBACK') ?: '1', FILTER_VALIDATE_BOOL),
            'github_token' => getenv('FCC_GITHUB_TOKEN') ?: '',
            'supported_locales' => [
                'en-US',
                'tr-TR',
                'es-ES',
                'fr-FR',
                'de-DE',
                'it-IT',
                'pt-BR',
                'nl-NL',
                'ar-SA',
                'ru-RU',
            ],
        ];
    }
}
