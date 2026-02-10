<?php

declare(strict_types=1);

namespace ForbiddenChecker\Tests;

use ForbiddenChecker\Domain\Scan\TagService;
use ForbiddenChecker\Infrastructure\Db\Database;
use ForbiddenChecker\Infrastructure\Db\Migrator;

final class TagServiceTest extends TestCase
{
    public function run(): void
    {
        $dbPath = dirname(__DIR__) . '/storage/test-tags.sqlite';
        @unlink($dbPath);

        $db = new Database($dbPath);
        $migrator = new Migrator($db->pdo(), dirname(__DIR__) . '/database/schema.sql');
        $migrator->migrate();

        $service = new TagService($db->pdo());

        // List empty
        $tags = $service->listTags();
        $this->assertSame(0, count($tags), 'Tags should be empty initially');

        // Create tag
        $tag = $service->createTag(1, 'production', '#ef4444');
        $this->assertSame('production', $tag['name']);
        $this->assertSame('#ef4444', $tag['color']);

        // Create another tag
        $tag2 = $service->createTag(1, 'staging', '#3b82f6');
        $this->assertSame('staging', $tag2['name']);

        // List tags
        $tags = $service->listTags();
        $this->assertSame(2, count($tags), 'Should have 2 tags');

        // Get tag
        $fetched = $service->getTag((int) $tag['id']);
        $this->assertNotNull($fetched);
        $this->assertSame('production', $fetched['name']);

        // Delete tag
        $service->deleteTag((int) $tag2['id']);
        $tags = $service->listTags();
        $this->assertSame(1, count($tags), 'Should have 1 tag after deletion');

        // Invalid color falls back to default
        $tag3 = $service->createTag(1, 'test', 'not-a-color');
        $this->assertSame('#6b7280', $tag3['color']);

        @unlink($dbPath);
    }
}
