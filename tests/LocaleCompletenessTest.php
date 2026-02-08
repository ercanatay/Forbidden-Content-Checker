<?php

declare(strict_types=1);

namespace ForbiddenChecker\Tests;

final class LocaleCompletenessTest extends TestCase
{
    public function run(): void
    {
        $localeDir = dirname(__DIR__) . '/locales';
        $files = glob($localeDir . '/*.json');
        sort($files);

        $this->assertTrue($files !== false && count($files) === 10, 'Expected exactly 10 locale files.');

        $base = json_decode((string) file_get_contents($localeDir . '/en-US.json'), true);
        $this->assertTrue(is_array($base), 'Invalid en-US locale file.');

        $baseKeys = array_keys($base);
        sort($baseKeys);

        foreach ($files as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            $this->assertTrue(is_array($data), 'Invalid locale JSON: ' . basename($file));

            $keys = array_keys($data);
            sort($keys);
            $this->assertSame($baseKeys, $keys, 'Locale key mismatch for ' . basename($file));
        }
    }
}
