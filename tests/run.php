<?php

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefixes = [
            'ForbiddenChecker\\Tests\\' => dirname(__DIR__) . '/tests/',
            'ForbiddenChecker\\' => dirname(__DIR__) . '/src/',
        ];

        foreach ($prefixes as $prefix => $basePath) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = substr($class, strlen($prefix));
            $path = $basePath . str_replace('\\', '/', $relative) . '.php';
            if (is_file($path)) {
                require_once $path;
                return;
            }
        }
    });
}

$tests = [
    ForbiddenChecker\Tests\UrlNormalizerTest::class,
    ForbiddenChecker\Tests\LocaleCompletenessTest::class,
    ForbiddenChecker\Tests\ResultScorerTest::class,
    ForbiddenChecker\Tests\TotpServiceTest::class,
    ForbiddenChecker\Tests\SchemaTest::class,
];

$totalAssertions = 0;
$failures = [];

foreach ($tests as $testClass) {
    try {
        $test = new $testClass();
        if (!method_exists($test, 'run')) {
            throw new RuntimeException('Missing run() method: ' . $testClass);
        }
        $test->run();
        $totalAssertions += $test->assertionCount();
        fwrite(STDOUT, "[PASS] {$testClass}\n");
    } catch (Throwable $e) {
        $failures[] = [$testClass, $e->getMessage()];
        fwrite(STDOUT, "[FAIL] {$testClass} - {$e->getMessage()}\n");
    }
}

if (count($failures) > 0) {
    fwrite(STDOUT, "\nFailures: " . count($failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "\nAll tests passed. Assertions: {$totalAssertions}\n");
