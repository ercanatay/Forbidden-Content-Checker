<?php

declare(strict_types=1);

namespace ForbiddenChecker\Tests;

use ForbiddenChecker\Infrastructure\Db\Database;
use ForbiddenChecker\Infrastructure\Db\Migrator;
use ForbiddenChecker\Infrastructure\Update\UpdateStateRepository;

final class UpdateStateRepositoryTest extends TestCase
{
    public function run(): void
    {
        $dbPath = dirname(__DIR__) . '/storage/test-update-state.sqlite';
        @unlink($dbPath);

        $db = new Database($dbPath);
        $migrator = new Migrator($db->pdo(), dirname(__DIR__) . '/database/schema.sql');
        $migrator->migrate();

        $repo = new UpdateStateRepository($db->pdo());

        $initial = $repo->getState();
        $this->assertSame('idle', $initial['status']);
        $this->assertSame(null, $initial['latestVersion']);

        $updated = $repo->patchState([
            'latestVersion' => '3.1.0',
            'latestTag' => 'v3.1.0',
            'status' => 'update_available',
            'approvedVersion' => '3.1.0',
        ]);

        $this->assertSame('3.1.0', $updated['latestVersion']);
        $this->assertSame('update_available', $updated['status']);

        $reloaded = $repo->getState();
        $this->assertSame('3.1.0', $reloaded['latestVersion']);
        $this->assertSame('v3.1.0', $reloaded['latestTag']);
        $this->assertSame('3.1.0', $reloaded['approvedVersion']);
    }
}
