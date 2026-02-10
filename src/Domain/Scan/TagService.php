<?php

declare(strict_types=1);

namespace ForbiddenChecker\Domain\Scan;

use PDO;

final class TagService
{
    public function __construct(
        private readonly PDO $pdo
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTags(): array
    {
        $stmt = $this->pdo->query(
            'SELECT t.*, COUNT(sjt.scan_job_id) AS scan_count
             FROM tags t
             LEFT JOIN scan_job_tags sjt ON sjt.tag_id = t.id
             GROUP BY t.id
             ORDER BY t.name ASC'
        );
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    public function createTag(int $userId, string $name, string $color = '#6b7280'): array
    {
        $name = trim($name);
        if ($name === '') {
            throw new \RuntimeException('Tag name is required.');
        }

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#6b7280';
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO tags (name, color, created_by, created_at)
             VALUES (:name, :color, :created_by, datetime('now'))"
        );
        $stmt->execute([
            ':name' => $name,
            ':color' => $color,
            ':created_by' => $userId,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        return $this->getTag($id) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTag(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tags WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function deleteTag(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM tags WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * @param array<int, int> $tagIds
     */
    public function attachTags(int $scanJobId, array $tagIds): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO scan_job_tags (scan_job_id, tag_id) VALUES (:scan_job_id, :tag_id)'
        );
        foreach ($tagIds as $tagId) {
            $stmt->execute([
                ':scan_job_id' => $scanJobId,
                ':tag_id' => (int) $tagId,
            ]);
        }
    }

    /**
     * @param array<int, int> $tagIds
     */
    public function detachTags(int $scanJobId, array $tagIds): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM scan_job_tags WHERE scan_job_id = :scan_job_id AND tag_id = :tag_id'
        );
        foreach ($tagIds as $tagId) {
            $stmt->execute([
                ':scan_job_id' => $scanJobId,
                ':tag_id' => (int) $tagId,
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTagsForScanJob(int $scanJobId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.* FROM tags t
             JOIN scan_job_tags sjt ON sjt.tag_id = t.id
             WHERE sjt.scan_job_id = :scan_job_id
             ORDER BY t.name ASC'
        );
        $stmt->execute([':scan_job_id' => $scanJobId]);
        return $stmt->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getScanJobsByTag(int $tagId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sj.* FROM scan_jobs sj
             JOIN scan_job_tags sjt ON sjt.scan_job_id = sj.id
             WHERE sjt.tag_id = :tag_id
             ORDER BY sj.created_at DESC'
        );
        $stmt->execute([':tag_id' => $tagId]);
        return $stmt->fetchAll() ?: [];
    }
}
