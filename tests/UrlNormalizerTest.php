<?php

declare(strict_types=1);

namespace ForbiddenChecker\Tests;

use ForbiddenChecker\Domain\Scan\UrlNormalizer;

final class UrlNormalizerTest extends TestCase
{
    public function run(): void
    {
        $n = new UrlNormalizer();

        $this->assertSame('https://example.com/', $n->normalizeInput('example.com'));
        $this->assertSame('https://example.com/path/to/page', $n->normalizeInput('https://example.com/path/./to/../to/page'));
        $this->assertSame('https://example.com:8443/', $n->normalizeInput('https://example.com:8443'));
        $this->assertSame('https://example.com/news', $n->resolveUrl('https://example.com/blog/post', '../news'));
        $this->assertSame('https://example.com/about', $n->resolveUrl('https://example.com', '/about'));
    }
}
