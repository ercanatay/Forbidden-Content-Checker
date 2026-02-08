<?php

declare(strict_types=1);

namespace ForbiddenChecker\Domain\Analytics;

use PDO;

final class TrendService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function trendBy(string $period): array
    {
        $period = strtolower($period);
        $format = match ($period) {
            'week' => '%Y-W%W',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        $stmt = $this->pdo->prepare(
            "SELECT strftime(:format, created_at) AS bucket,
                    COUNT(1) AS scan_count,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                    SUM(CASE WHEN status = 'partial' THEN 1 ELSE 0 END) AS partial_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count
             FROM scan_jobs
             GROUP BY bucket
             ORDER BY bucket ASC"
        );
        $stmt->execute([':format' => $format]);

        return $stmt->fetchAll() ?: [];
    }
}
