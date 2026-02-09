<?php

declare(strict_types=1);

namespace ForbiddenChecker\Infrastructure\Update;

use PDO;

final class UpdateStateRepository
{
    private const SETTINGS_KEY = 'updater.state';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function getState(): array
    {
        $stmt = $this->pdo->prepare('SELECT value FROM system_settings WHERE key = :key LIMIT 1');
        $stmt->execute([':key' => self::SETTINGS_KEY]);

        $raw = $stmt->fetchColumn();
        if (!is_string($raw) || trim($raw) === '') {
            return $this->defaultState();
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->defaultState();
        }

        return array_replace($this->defaultState(), $decoded);
    }

    /**
     * @param array<string, mixed> $state
     */
    public function saveState(array $state): void
    {
        $payload = json_encode(array_replace($this->defaultState(), $state), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stmt = $this->pdo->prepare(
            "INSERT INTO system_settings (key, value, updated_at)
             VALUES (:key, :value, datetime('now'))
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')"
        );

        $stmt->execute([
            ':key' => self::SETTINGS_KEY,
            ':value' => $payload === false ? '{}' : $payload,
        ]);
    }

    /**
     * @param array<string, mixed> $patch
     * @return array<string, mixed>
     */
    public function patchState(array $patch): array
    {
        $state = $this->getState();
        $merged = array_replace($state, $patch);
        $this->saveState($merged);

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultState(): array
    {
        return [
            'installedVersion' => null,
            'latestVersion' => null,
            'latestTag' => null,
            'status' => 'idle',
            'lastCheckAt' => null,
            'lastApplyAt' => null,
            'approvedVersion' => null,
            'approvedBy' => null,
            'approvedAt' => null,
            'lastError' => null,
            'lastTransport' => null,
            'rollbackMeta' => null,
        ];
    }
}
