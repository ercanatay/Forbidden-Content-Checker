<?php

declare(strict_types=1);

namespace ForbiddenChecker\Tests;

use ForbiddenChecker\Domain\Auth\AuthService;
use ForbiddenChecker\Domain\Auth\TotpService;
use ForbiddenChecker\Infrastructure\Db\Database;
use ForbiddenChecker\Infrastructure\Db\Migrator;
use ForbiddenChecker\Infrastructure\Logging\Logger;
use RuntimeException;

final class AuthServiceTest extends TestCase
{
    private string $dbPath;
    private string $logPath;
    private ?Database $db = null;
    private ?AuthService $authService = null;

    public function run(): void
    {
        $methods = [
            'testLoginSuccess',
            'testLoginInvalidPassword',
            'testLoginUserNotFound',
        ];

        foreach ($methods as $method) {
            $this->setUp();
            try {
                $this->$method();
            } finally {
                $this->tearDown();
            }
        }
    }

    private function setUp(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->dbPath = dirname(__DIR__) . '/storage/test-auth-' . $suffix . '.sqlite';
        $this->removeSqliteArtifacts($this->dbPath);

        $logDir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }
        $this->logPath = $logDir . '/test-auth-' . $suffix . '.log';

        $this->db = new Database($this->dbPath);
        $migrator = new Migrator($this->db->pdo(), dirname(__DIR__) . '/database/schema.sql');
        $migrator->migrate();

        $logger = new Logger($this->logPath, true);
        $totpService = new TotpService();

        $this->authService = new AuthService(
            $this->db->pdo(),
            $logger,
            $totpService,
            'PHPSESSID',
            'test-secret'
        );

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
    }

    private function tearDown(): void
    {
        $this->removeSqliteArtifacts($this->dbPath);
        if (isset($this->logPath) && $this->logPath !== '' && is_file($this->logPath)) {
            unlink($this->logPath);
        }

        $this->db = null;
        $this->authService = null;

        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
    }

    private function testLoginSuccess(): void
    {
        $user = $this->authService->login(
            'admin@example.com',
            getenv('FCC_ADMIN_PASSWORD') ?: 'admin123!ChangeNow',
            null,
            '127.0.0.1',
            'TestAgent'
        );

        $this->assertSame('admin@example.com', $user['email']);
        $this->assertSame('session', $user['auth_type']);
    }

    private function testLoginInvalidPassword(): void
    {
        try {
            $this->authService->login(
                'admin@example.com',
                'wrongpassword',
                null,
                '127.0.0.1',
                'TestAgent'
            );
            throw new RuntimeException('Should have thrown exception for invalid password');
        } catch (RuntimeException $e) {
            $this->assertSame('Invalid credentials.', $e->getMessage());
        }
    }

    private function testLoginUserNotFound(): void
    {
        try {
            $this->authService->login(
                'nonexistent@example.com',
                'somepassword',
                null,
                '127.0.0.1',
                'TestAgent'
            );
            throw new RuntimeException('Should have thrown exception for non-existent user');
        } catch (RuntimeException $e) {
            $this->assertSame('Invalid credentials.', $e->getMessage());
        }
    }

    private function removeSqliteArtifacts(string $dbPath): void
    {
        $paths = [
            $dbPath,
            $dbPath . '-wal',
            $dbPath . '-shm',
        ];

        foreach ($paths as $path) {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }
    }
}
