<?php

declare(strict_types=1);

namespace ForbiddenChecker\Infrastructure\Update;

final class CommandRunner
{
    /**
     * @param array<int, string> $command
     * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool, command: string}
     */
    public function run(array $command, string $cwd, int $timeoutSec = 120): array
    {
        if (count($command) === 0) {
            throw new \InvalidArgumentException('Command cannot be empty.');
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start process.');
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $start = microtime(true);

        while (true) {
            $read = [];
            if (is_resource($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (is_resource($pipes[2])) {
                $read[] = $pipes[2];
            }

            if (count($read) > 0) {
                $write = null;
                $except = null;
                @stream_select($read, $write, $except, 0, 200000);

                foreach ($read as $stream) {
                    $chunk = stream_get_contents($stream);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }

                    if ($stream === $pipes[1]) {
                        $stdout .= $chunk;
                    } else {
                        $stderr .= $chunk;
                    }
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }

            if ((microtime(true) - $start) > $timeoutSec) {
                $timedOut = true;
                proc_terminate($process, 15);
                usleep(300000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                break;
            }
        }

        $remainingStdout = stream_get_contents($pipes[1]);
        if ($remainingStdout !== false) {
            $stdout .= $remainingStdout;
        }

        $remainingStderr = stream_get_contents($pipes[2]);
        if ($remainingStderr !== false) {
            $stderr .= $remainingStderr;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($timedOut && $exitCode === 0) {
            $exitCode = 124;
        }

        return [
            'exitCode' => $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timedOut' => $timedOut,
            'command' => implode(' ', array_map(static fn (string $part): string => escapeshellarg($part), $command)),
        ];
    }
}
