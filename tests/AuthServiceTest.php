<?php

declare(strict_types=1);

namespace ForbiddenChecker\Tests;

use ForbiddenChecker\Domain\Auth\AuthService;
use ForbiddenChecker\Domain\Auth\TotpService;
use ForbiddenChecker\Infrastructure\Db\Database;
use ForbiddenChecker\Infrastructure\Db\Migrator;
use ForbiddenChecker\Infrastructure\Logging\Logger;
use PDO;

final class AuthServiceTest extends TestCase
{
    private ?AuthService $auth = null;
    private string $dbPath;

    public function __construct()
    {
        $this->dbPath = dirname(__DIR__) . '/storage/test_auth_' . uniqid() . '.sqlite';
    }

    public function run(): void
    {
        try {
            $this->runTest('testValidLogin');
            $this->runTest('testInvalidPassword');
            $this->runTest('testInvalidUser');
        } finally {
            if (file_exists($this->dbPath)) {
                @unlink($this->dbPath);
            }
        }
    }

    private function runTest(string $method): void
    {
        $this->setUp();
        try {
            $this->$method();
        } finally {
            $this->tearDown();
        }
    }

    private function setUp(): void
    {
        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }

        $db = new Database($this->dbPath);
        $pdo = $db->pdo();

        $migrator = new Migrator($pdo, dirname(__DIR__) . '/database/schema.sql');
        $migrator->migrate();

        // Admin: admin@example.com / admin123!ChangeNow created by migrator

        $logger = new Logger(dirname(__DIR__) . '/storage/logs/test.log', true);
        $totp = new TotpService();

        $this->auth = new AuthService(
            $pdo,
            $logger,
            $totp,
            'test_session',
            'test_secret'
        );

        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
    }

    private function tearDown(): void
    {
        $_SESSION = [];
        // DB file cleaned up at end of run or start of next setup
    }

    private function testValidLogin(): void
    {
        $user = $this->auth->login('admin@example.com', 'admin123!ChangeNow', null, '127.0.0.1', 'test-agent');

        $this->assertSame('admin@example.com', $user['email'], 'Valid login should return correct email.');
        $this->assertTrue(isset($_SESSION['user']), 'Session should be populated.');
        $this->assertSame($user['id'], $_SESSION['user']['id'], 'Session user ID should match.');
    }

    private function testInvalidPassword(): void
    {
        try {
            $this->auth->login('admin@example.com', 'wrongpassword', null, '127.0.0.1', 'test-agent');
            $this->assertTrue(false, 'Login with wrong password should fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Invalid credentials.', $e->getMessage(), 'Error message mismatch.');
        }
    }

    private function testInvalidUser(): void
    {
        try {
            $this->auth->login('nonexistent@example.com', 'anypassword', null, '127.0.0.1', 'test-agent');
            $this->assertTrue(false, 'Login with non-existent user should fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Invalid credentials.', $e->getMessage(), 'Error message mismatch for non-existent user.');
        }
    }
}
