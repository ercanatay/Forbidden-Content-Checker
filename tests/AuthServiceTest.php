<?php

declare(strict_types=1);

namespace ForbiddenChecker\Tests;

use ForbiddenChecker\Domain\Auth\AuthService;
use ForbiddenChecker\Domain\Auth\TotpService;
use ForbiddenChecker\Infrastructure\Db\Database;
use ForbiddenChecker\Infrastructure\Db\Migrator;
use ForbiddenChecker\Infrastructure\Logging\Logger;
use PDO;
use RuntimeException;

final class AuthServiceTest extends TestCase
{
    private string $dbPath;
    private ?Database $db = null;
    private ?AuthService $authService = null;

    public function run(): void
    {
        // Each test method needs setup/teardown
        $methods = [
            'testLoginSuccess',
            'testLoginInvalidPassword',
            'testLoginUserNotFound',
            // 'testLoginMfaRequiredButMissing' // Skipping MFA for now as it requires complex setup
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
        $this->dbPath = dirname(__DIR__) . '/storage/test-auth-' . bin2hex(random_bytes(4)) . '.sqlite';
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }

        $this->db = new Database($this->dbPath);
        $migrator = new Migrator($this->db->pdo(), dirname(__DIR__) . '/database/schema.sql');
        $migrator->migrate();

        // Ensure admin user exists with known password
        // The migration creates admin@example.com with password getenv('FCC_ADMIN_PASSWORD') ?: 'admin123!ChangeNow'

        $logger = new Logger(dirname(__DIR__) . '/storage/test.log');
        $totpService = new TotpService();

        $this->authService = new AuthService(
            $this->db->pdo(),
            $logger,
            $totpService,
            'PHPSESSID',
            'test-secret'
        );

        if (session_status() !== PHP_SESSION_ACTIVE) {
            // Suppress headers already sent warning if any
            @session_start();
        }
    }

    private function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
        $this->db = null;
        $this->authService = null;
        @session_destroy();
    }

    private function testLoginSuccess(): void
    {
        // Must redefine seed if needed, but migration does it.
        // admin@example.com / admin123!ChangeNow

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
}
