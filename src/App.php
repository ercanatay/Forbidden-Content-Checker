<?php

declare(strict_types=1);

namespace ForbiddenChecker;

use ForbiddenChecker\Domain\Analytics\TrendService;
use ForbiddenChecker\Domain\Auth\AuthService;
use ForbiddenChecker\Domain\Auth\TotpService;
use ForbiddenChecker\Domain\I18n\Translator;
use ForbiddenChecker\Domain\Scan\ResultScorer;
use ForbiddenChecker\Domain\Scan\ScanService;
use ForbiddenChecker\Domain\Scan\Scanner;
use ForbiddenChecker\Domain\Scan\SuppressionService;
use ForbiddenChecker\Domain\Scan\UrlNormalizer;
use ForbiddenChecker\Http\Request;
use ForbiddenChecker\Infrastructure\Db\Database;
use ForbiddenChecker\Infrastructure\Db\Migrator;
use ForbiddenChecker\Infrastructure\Export\ReportExporter;
use ForbiddenChecker\Infrastructure\Logging\Logger;
use ForbiddenChecker\Infrastructure\Notification\NotificationService;
use ForbiddenChecker\Infrastructure\Observability\MetricsService;
use ForbiddenChecker\Infrastructure\Queue\QueueService;
use ForbiddenChecker\Infrastructure\Security\CsrfTokenManager;
use ForbiddenChecker\Infrastructure\Security\RateLimiter;
use ForbiddenChecker\Infrastructure\Security\SsrfGuard;
use PDO;

final class App
{
    private Database $db;
    private Logger $logger;
    private Translator $translator;
    private CsrfTokenManager $csrfTokenManager;
    private RateLimiter $rateLimiter;
    private AuthService $authService;
    private ScanService $scanService;
    private QueueService $queueService;
    private MetricsService $metricsService;
    private ReportExporter $reportExporter;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(private readonly array $config)
    {
        $this->db = new Database((string) $config['db_path']);
        $this->logger = new Logger((string) $config['log_file'], (bool) $config['app_debug']);
        $this->translator = new Translator($config, dirname(__DIR__) . '/locales');
        $this->csrfTokenManager = new CsrfTokenManager();
        $this->rateLimiter = new RateLimiter($this->db->pdo(), (int) $config['rate_limit_window_sec']);

        $migrator = new Migrator($this->db->pdo(), dirname(__DIR__) . '/database/schema.sql');
        $migrator->migrate();

        $this->queueService = new QueueService($this->db->pdo(), (int) $config['worker_stale_after_sec']);
        $this->metricsService = new MetricsService($this->db->pdo());

        $totpService = new TotpService();
        $this->authService = new AuthService(
            $this->db->pdo(),
            $this->logger,
            $totpService,
            (string) $config['session_name'],
            (string) $config['app_secret']
        );

        $urlNormalizer = new UrlNormalizer();
        $scanService = new ScanService(
            $this->db->pdo(),
            new Scanner(
                $urlNormalizer,
                new ResultScorer(),
                new SuppressionService($this->db->pdo()),
                new SsrfGuard((bool) $config['allow_private_network']),
                $this->logger,
                (int) $config['request_timeout'],
                (int) $config['max_retries'],
                (int) $config['max_pages'],
                (int) $config['max_results_per_keyword']
            ),
            $urlNormalizer,
            $this->queueService,
            new NotificationService(
                $this->db->pdo(),
                $this->logger,
                (string) $config['webhook_url'],
                (bool) $config['email_enabled'],
                (string) $config['email_from']
            ),
            new TrendService($this->db->pdo()),
            $this->logger
        );
        $this->scanService = $scanService;

        $this->reportExporter = new ReportExporter($this->db->pdo(), (string) $config['report_dir'], (string) $config['app_secret']);
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    public function pdo(): PDO
    {
        return $this->db->pdo();
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    public function translator(): Translator
    {
        return $this->translator;
    }

    public function csrf(): CsrfTokenManager
    {
        return $this->csrfTokenManager;
    }

    public function auth(): AuthService
    {
        return $this->authService;
    }

    public function scan(): ScanService
    {
        return $this->scanService;
    }

    public function queue(): QueueService
    {
        return $this->queueService;
    }

    public function metrics(): MetricsService
    {
        return $this->metricsService;
    }

    public function reports(): ReportExporter
    {
        return $this->reportExporter;
    }

    /**
     * @param array<string, mixed>|null $user
     */
    public function enforceRateLimit(Request $request, ?array $user): bool
    {
        $globalBucket = 'global:' . date('YmdHi');
        if (!$this->rateLimiter->check($globalBucket, (int) $this->config['rate_limit_global'])) {
            return false;
        }

        $identifier = $user ? 'user:' . (string) $user['id'] : 'ip:' . $request->ip();
        return $this->rateLimiter->check($identifier . ':' . date('YmdHi'), (int) $this->config['rate_limit_user']);
    }
}
