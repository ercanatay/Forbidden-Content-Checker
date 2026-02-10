<?php

declare(strict_types=1);

namespace ForbiddenChecker\Tests;

use ForbiddenChecker\Domain\Analytics\DashboardService;
use ForbiddenChecker\Infrastructure\Db\Database;
use ForbiddenChecker\Infrastructure\Db\Migrator;

final class DashboardServiceTest extends TestCase
{
    public function run(): void
    {
        $dbPath = dirname(__DIR__) . '/storage/test-dashboard.sqlite';
        @unlink($dbPath);

        $db = new Database($dbPath);
        $migrator = new Migrator($db->pdo(), dirname(__DIR__) . '/database/schema.sql');
        $migrator->migrate();

        $service = new DashboardService($db->pdo());

        // Get summary with empty database
        $summary = $service->getSummary();

        $this->assertTrue(isset($summary['overview']), 'Summary should have overview');
        $this->assertTrue(isset($summary['recent_scans']), 'Summary should have recent_scans');
        $this->assertTrue(isset($summary['top_keywords']), 'Summary should have top_keywords');
        $this->assertTrue(isset($summary['status_breakdown']), 'Summary should have status_breakdown');
        $this->assertTrue(isset($summary['daily_activity']), 'Summary should have daily_activity');

        $overview = $summary['overview'];
        $this->assertSame(0, $overview['total_scans']);
        $this->assertSame(0, $overview['total_targets']);
        $this->assertSame(0, $overview['total_matches']);
        $this->assertSame(0, $overview['active_schedules']);
        $this->assertSame(0, $overview['total_tags']);

        $this->assertSame(0, count($summary['recent_scans']));
        $this->assertSame(0, count($summary['top_keywords']));

        @unlink($dbPath);
    }
}
