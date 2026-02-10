<?php

declare(strict_types=1);

namespace ForbiddenChecker\Tests;

use ForbiddenChecker\Domain\Scan\BulkImportService;
use ForbiddenChecker\Domain\Scan\UrlNormalizer;

final class BulkImportServiceTest extends TestCase
{
    public function run(): void
    {
        $service = new BulkImportService(new UrlNormalizer());

        // Empty content
        $result = $service->parseUrls('');
        $this->assertSame(0, count($result['urls']), 'Empty content should yield no URLs');
        $this->assertTrue(count($result['errors']) > 0, 'Empty content should yield an error');

        // Line-separated URLs
        $result = $service->parseUrls("example.com\nhttps://test.org\nexample.com");
        $this->assertSame(2, count($result['urls']), 'Should deduplicate URLs');
        $this->assertSame(1, $result['skipped'], 'Should skip 1 duplicate');

        // Comments and blank lines
        $result = $service->parseUrls("# Header comment\nexample.com\n\n# Another comment\ntest.org");
        $this->assertSame(2, count($result['urls']), 'Should skip comments and blank lines');

        // CSV format
        $result = $service->parseUrls("example.com,test.org,blog.example.com", 'csv');
        $this->assertSame(3, count($result['urls']), 'CSV should parse comma-separated');

        // Auto-detect text format
        $result = $service->parseUrls("https://example.com\nhttps://test.org", 'auto');
        $this->assertSame(2, count($result['urls']), 'Auto-detect should parse line-separated');

        // Duplicate entries should be skipped
        $result = $service->parseUrls("example.com\nexample.com\nexample.com");
        $this->assertSame(1, count($result['urls']), 'Duplicates should be removed');
        $this->assertSame(2, $result['skipped'], 'Duplicate entries should be counted as skipped');
    }
}
