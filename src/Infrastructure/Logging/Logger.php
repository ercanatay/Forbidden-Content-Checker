<?php

declare(strict_types=1);

namespace ForbiddenChecker\Infrastructure\Logging;

final class Logger
{
    public function __construct(private readonly string $file, private readonly bool $debug = false)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        if (!$this->debug) {
            return;
        }

        $this->write('DEBUG', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        $timestamp = gmdate('Y-m-d\TH:i:s\Z');
        $line = sprintf(
            "[%s] %s %s %s\n",
            $timestamp,
            $level,
            $message,
            $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''
        );

        file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }
}
