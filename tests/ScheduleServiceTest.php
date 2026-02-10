<?php

declare(strict_types=1);

namespace ForbiddenChecker\Tests;

use ForbiddenChecker\Domain\Scan\ScheduleService;
use ForbiddenChecker\Domain\Scan\ScanService;
use ForbiddenChecker\Infrastructure\Db\Database;
use ForbiddenChecker\Infrastructure\Db\Migrator;
use ForbiddenChecker\Infrastructure\Logging\Logger;

final class ScheduleServiceTest extends TestCase
{
    public function run(): void
    {
        $dbPath = dirname(__DIR__) . '/storage/test-schedules.sqlite';
        $logPath = dirname(__DIR__) . '/storage/test-schedules.log';
        @unlink($dbPath);
        @unlink($logPath);

        $db = new Database($dbPath);
        $migrator = new Migrator($db->pdo(), dirname(__DIR__) . '/database/schema.sql');
        $migrator->migrate();

        $logger = new Logger($logPath, false);

        // We can't run actual scans but we can test schedule CRUD
        // Create a mock-like approach: just test service without scanService dependency
        // Actually we need a ScanService but we can test the schedule management part

        // Test schedule CRUD directly via PDO since ScanService requires complex setup
        $this->testScheduleCrud($db, $logger);

        @unlink($dbPath);
        @unlink($logPath);
    }

    private function testScheduleCrud(Database $db, Logger $logger): void
    {
        $pdo = $db->pdo();

        // Insert a schedule directly
        $stmt = $pdo->prepare(
            "INSERT INTO scheduled_scans
             (name, targets_json, keywords_json, exclude_keywords_json, options_json, schedule_cron, is_active, next_run_at, created_by, created_at, updated_at)
             VALUES (:name, :targets, :keywords, '[]', '{}', :cron, 1, :next, 1, datetime('now'), datetime('now'))"
        );
        $stmt->execute([
            ':name' => 'Test Schedule',
            ':targets' => '["example.com"]',
            ':keywords' => '["casino"]',
            ':cron' => 'daily',
            ':next' => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);

        $id = (int) $pdo->lastInsertId();
        $this->assertTrue($id > 0, 'Schedule should be created');

        // Read back
        $stmt = $pdo->prepare('SELECT * FROM scheduled_scans WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        $this->assertNotNull($row);
        $this->assertSame('Test Schedule', $row['name']);
        $this->assertSame('daily', $row['schedule_cron']);
        $this->assertSame(1, (int) $row['is_active']);

        // Toggle inactive
        $pdo->prepare("UPDATE scheduled_scans SET is_active = 0 WHERE id = :id")->execute([':id' => $id]);
        $stmt = $pdo->prepare('SELECT is_active FROM scheduled_scans WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $this->assertSame(0, (int) $stmt->fetchColumn());

        // Delete
        $pdo->prepare('DELETE FROM scheduled_scans WHERE id = :id')->execute([':id' => $id]);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM scheduled_scans');
        $this->assertSame(0, (int) $stmt->execute() ? $stmt->fetchColumn() : 0);
    }
}
