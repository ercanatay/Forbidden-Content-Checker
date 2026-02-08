<?php

declare(strict_types=1);

namespace ForbiddenChecker\Infrastructure\Queue;

use PDO;

final class QueueService
{
    public function __construct(private readonly PDO $pdo, private readonly int $staleAfterSec)
    {
    }

    public function enqueue(int $scanJobId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE scan_jobs
             SET status = 'queued', queued_at = datetime('now')
             WHERE id = :id"
        );
        $stmt->execute([':id' => $scanJobId]);
    }

    public function recoverStaleJobs(): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE scan_jobs
             SET status = 'queued', worker_id = NULL
             WHERE status = 'running'
               AND updated_at < datetime('now', :threshold)"
        );
        $stmt->execute([
            ':threshold' => '-' . $this->staleAfterSec . ' seconds',
        ]);

        return $stmt->rowCount();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function claimNext(string $workerId): ?array
    {
        $this->pdo->beginTransaction();

        $select = $this->pdo->query(
            "SELECT id
             FROM scan_jobs
             WHERE status = 'queued'
             ORDER BY created_at ASC
             LIMIT 1"
        );
        $jobId = $select->fetchColumn();

        if ($jobId === false) {
            $this->pdo->commit();
            return null;
        }

        $update = $this->pdo->prepare(
            "UPDATE scan_jobs
             SET status = 'running', worker_id = :worker_id, started_at = datetime('now'), updated_at = datetime('now')
             WHERE id = :id AND status = 'queued'"
        );
        $update->execute([
            ':id' => (int) $jobId,
            ':worker_id' => $workerId,
        ]);

        if ($update->rowCount() !== 1) {
            $this->pdo->rollBack();
            return null;
        }

        $jobStmt = $this->pdo->prepare('SELECT * FROM scan_jobs WHERE id = :id LIMIT 1');
        $jobStmt->execute([':id' => (int) $jobId]);
        $job = $jobStmt->fetch();

        $this->pdo->commit();

        return $job ?: null;
    }

    public function markCancelled(int $jobId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE scan_jobs
             SET status = 'cancelled', finished_at = datetime('now'), updated_at = datetime('now')
             WHERE id = :id"
        );
        $stmt->execute([':id' => $jobId]);
    }
}
