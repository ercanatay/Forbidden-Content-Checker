<?php

declare(strict_types=1);

namespace ForbiddenChecker\Tests;

abstract class TestCase
{
    protected int $assertions = 0;

    protected function assertTrue(bool $condition, string $message = 'Expected condition to be true'): void
    {
        $this->assertions++;
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            $msg = $message !== '' ? $message : sprintf('Expected %s but got %s', var_export($expected, true), var_export($actual, true));
            throw new \RuntimeException($msg);
        }
    }

    protected function assertNotNull(mixed $actual, string $message = 'Expected value not to be null'): void
    {
        $this->assertions++;
        if ($actual === null) {
            throw new \RuntimeException($message);
        }
    }

    public function assertionCount(): int
    {
        return $this->assertions;
    }
}
