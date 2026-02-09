<?php

declare(strict_types=1);

namespace ForbiddenChecker\Tests;

use ForbiddenChecker\Infrastructure\Update\VersionComparator;

final class VersionComparatorTest extends TestCase
{
    public function run(): void
    {
        $cmp = new VersionComparator();

        $this->assertSame('3.0.0', $cmp->normalize('3.0.0'));
        $this->assertSame('3.0.0', $cmp->normalize('v3.0.0'));
        $this->assertSame(null, $cmp->normalize('3.0.0-rc1'));

        $this->assertTrue($cmp->compare('3.0.9', '3.0.10') < 0, '3.0.9 should be lower than 3.0.10');
        $this->assertTrue($cmp->compare('3.1.0', '3.0.99') > 0, '3.1.0 should be greater than 3.0.99');

        $latest = $cmp->latestStableFromTags([
            'v3.0.1-rc1',
            'v3.0.1',
            'v3.1.0-beta',
            'v3.0.2',
        ]);

        $this->assertNotNull($latest, 'Latest stable tag should be resolved.');
        $this->assertSame('v3.0.2', $latest['tag']);
        $this->assertSame('3.0.2', $latest['version']);
    }
}
