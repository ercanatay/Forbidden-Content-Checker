<?php

declare(strict_types=1);

namespace ForbiddenChecker\Tests;

use ForbiddenChecker\Config;
use ForbiddenChecker\Infrastructure\Db\Database;
use ForbiddenChecker\Infrastructure\Db\Migrator;

final class SchemaTest extends TestCase
{
    public function run(): void
    {
        $config = Config::load();
        $dbPath = dirname(__DIR__) . '/storage/test-checker.sqlite';
        @unlink($dbPath);

        $db = new Database($dbPath);
        $migrator = new Migrator($db->pdo(), dirname(__DIR__) . '/database/schema.sql');
        $migrator->migrate();

        $tables = [
            'users', 'roles', 'user_roles', 'sessions', 'api_tokens',
            'scan_profiles', 'scan_jobs', 'scan_targets', 'scan_results', 'scan_matches',
            'keyword_sets', 'keyword_terms', 'suppression_rules',
            'notifications', 'webhooks', 'email_subscriptions',
            'audit_logs', 'system_settings', 'migration_versions', 'rate_limits'
        ];

        foreach ($tables as $table) {
            $stmt = $db->pdo()->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name");
            $stmt->execute([':name' => $table]);
            $this->assertSame($table, $stmt->fetchColumn(), 'Missing table: ' . $table);
        }

        $admin = $db->pdo()->query("SELECT email FROM users WHERE email = 'admin@example.com' LIMIT 1")->fetchColumn();
        $this->assertSame('admin@example.com', $admin, 'Default admin user should be seeded.');
    }
}
