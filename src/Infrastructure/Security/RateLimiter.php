<?php

declare(strict_types=1);

namespace ForbiddenChecker\Infrastructure\Security;

use PDO;

final class RateLimiter
{
    public function __construct(private readonly PDO $pdo, private readonly int $windowSec)
    {
    }

    public function check(string $bucket, int $maxHits): bool
    {
        $now = time();

        $cleanup = $this->pdo->prepare('DELETE FROM rate_limits WHERE reset_at <= :now');
        $cleanup->execute([':now' => $now]);

        $stmt = $this->pdo->prepare('SELECT hits, reset_at FROM rate_limits WHERE bucket = :bucket LIMIT 1');
        $stmt->execute([':bucket' => $bucket]);
        $row = $stmt->fetch();

        if (!$row) {
            $insert = $this->pdo->prepare('INSERT INTO rate_limits (bucket, hits, reset_at) VALUES (:bucket, 1, :reset_at)');
            $insert->execute([
                ':bucket' => $bucket,
                ':reset_at' => $now + $this->windowSec,
            ]);
            return true;
        }

        if ((int) $row['hits'] >= $maxHits) {
            return false;
        }

        $update = $this->pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = :bucket');
        $update->execute([':bucket' => $bucket]);

        return true;
    }
}
