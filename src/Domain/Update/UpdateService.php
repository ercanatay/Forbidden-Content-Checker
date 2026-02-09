<?php

declare(strict_types=1);

namespace ForbiddenChecker\Domain\Update;

use ForbiddenChecker\Infrastructure\Logging\Logger;
use ForbiddenChecker\Infrastructure\Update\ReleaseClientInterface;
use ForbiddenChecker\Infrastructure\Update\UpdateStateRepository;
use ForbiddenChecker\Infrastructure\Update\VersionComparator;
use PDO;

final class UpdateService
{
    public function __construct(
        private readonly UpdateStateRepository $stateRepository,
        private readonly ReleaseClientInterface $releaseClient,
        private readonly VersionComparator $versionComparator,
        private readonly Logger $logger,
        private readonly PDO $pdo,
        private readonly string $projectRoot,
        private readonly bool $enabled,
        private readonly int $checkIntervalSec,
        private readonly bool $requireApproval
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $state = $this->stateRepository->getState();
        $installedVersion = $this->readInstalledVersion();

        if (($state['installedVersion'] ?? null) !== $installedVersion) {
            $state = $this->stateRepository->patchState([
                'installedVersion' => $installedVersion,
            ]);
        }

        return array_replace($state, [
            'enabled' => $this->enabled,
            'checkIntervalSec' => $this->checkIntervalSec,
            'requireApproval' => $this->requireApproval,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function checkForUpdates(bool $force = false, ?int $actorUserId = null): array
    {
        if (!$this->enabled) {
            return $this->status();
        }

        return $this->withLock(function () use ($force, $actorUserId): array {
            $state = $this->stateRepository->getState();
            $installedVersion = $this->readInstalledVersion();
            $state['installedVersion'] = $installedVersion;

            if (!$force && !$this->isCheckDue($state['lastCheckAt'] ?? null)) {
                $this->stateRepository->saveState($state);
                return array_replace($state, [
                    'enabled' => $this->enabled,
                    'checkIntervalSec' => $this->checkIntervalSec,
                    'requireApproval' => $this->requireApproval,
                ]);
            }

            $now = gmdate('c');

            try {
                $latest = $this->releaseClient->latestStableTag();
            } catch (\Throwable $e) {
                $state['status'] = 'failed';
                $state['lastCheckAt'] = $now;
                $state['lastError'] = $e->getMessage();
                $state['installedVersion'] = $installedVersion;
                $this->stateRepository->saveState($state);

                $this->logger->error('Update check failed.', ['error' => $e->getMessage()]);
                $this->audit($actorUserId, 'update.check.performed', [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'force' => $force,
                ]);

                return array_replace($state, [
                    'enabled' => $this->enabled,
                    'checkIntervalSec' => $this->checkIntervalSec,
                    'requireApproval' => $this->requireApproval,
                ]);
            }

            $state['lastCheckAt'] = $now;
            $state['lastError'] = null;

            if ($latest === null) {
                $state['latestVersion'] = null;
                $state['latestTag'] = null;
                $state['status'] = 'idle';
                $this->clearApprovalState($state);
                $this->stateRepository->saveState($state);

                $this->audit($actorUserId, 'update.check.performed', [
                    'success' => true,
                    'force' => $force,
                    'latestVersion' => null,
                ]);

                return array_replace($state, [
                    'enabled' => $this->enabled,
                    'checkIntervalSec' => $this->checkIntervalSec,
                    'requireApproval' => $this->requireApproval,
                ]);
            }

            $latestVersion = $this->versionComparator->normalize((string) $latest['version']);
            if ($latestVersion === null) {
                $state['status'] = 'failed';
                $state['lastError'] = 'Invalid version payload returned by release client.';
                $this->stateRepository->saveState($state);

                return array_replace($state, [
                    'enabled' => $this->enabled,
                    'checkIntervalSec' => $this->checkIntervalSec,
                    'requireApproval' => $this->requireApproval,
                ]);
            }

            $state['latestVersion'] = $latestVersion;
            $state['latestTag'] = (string) $latest['tag'];
            $state['installedVersion'] = $installedVersion;

            $isUpdateAvailable = $this->versionComparator->compare($latestVersion, $installedVersion) > 0;

            if ($isUpdateAvailable) {
                if (($state['approvedVersion'] ?? null) !== null && (string) $state['approvedVersion'] !== $latestVersion) {
                    $this->clearApprovalState($state);
                }

                if (!$this->requireApproval) {
                    $state['approvedVersion'] = $latestVersion;
                    $state['approvedBy'] = $actorUserId;
                    $state['approvedAt'] = $now;
                }

                $state['status'] = ($state['approvedVersion'] ?? null) === $latestVersion ? 'approved' : 'update_available';

                $this->audit($actorUserId, 'update.available', [
                    'installedVersion' => $installedVersion,
                    'latestVersion' => $latestVersion,
                    'latestTag' => (string) $latest['tag'],
                ]);
            } else {
                $this->clearApprovalState($state);
                $state['status'] = 'idle';
            }

            $this->stateRepository->saveState($state);

            $this->audit($actorUserId, 'update.check.performed', [
                'success' => true,
                'force' => $force,
                'latestVersion' => $latestVersion,
                'updateAvailable' => $isUpdateAvailable,
            ]);

            return array_replace($state, [
                'enabled' => $this->enabled,
                'checkIntervalSec' => $this->checkIntervalSec,
                'requireApproval' => $this->requireApproval,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function approveUpdate(string $version, int $actorUserId): array
    {
        if (!$this->enabled) {
            throw new \RuntimeException('Updater is disabled.');
        }

        return $this->withLock(function () use ($version, $actorUserId): array {
            $state = $this->stateRepository->getState();
            $normalized = $this->versionComparator->normalize($version);
            if ($normalized === null) {
                throw new \RuntimeException('Invalid version format for approval.');
            }

            $latestVersion = $state['latestVersion'] ?? null;
            if (!is_string($latestVersion) || $latestVersion === '') {
                throw new \RuntimeException('No pending update to approve.');
            }

            $installedVersion = $this->versionComparator->normalize((string) ($state['installedVersion'] ?? $this->readInstalledVersion()));
            if ($installedVersion === null || $this->versionComparator->compare($latestVersion, $installedVersion) <= 0) {
                throw new \RuntimeException('No pending update to approve.');
            }

            if ($normalized !== $latestVersion) {
                throw new \RuntimeException('Version does not match the latest pending update.');
            }

            $state['approvedVersion'] = $normalized;
            $state['approvedBy'] = $actorUserId;
            $state['approvedAt'] = gmdate('c');
            $state['status'] = 'approved';
            $state['lastError'] = null;

            $this->stateRepository->saveState($state);

            $this->audit($actorUserId, 'update.approved', [
                'version' => $normalized,
                'latestTag' => $state['latestTag'] ?? null,
            ]);

            return array_replace($state, [
                'enabled' => $this->enabled,
                'checkIntervalSec' => $this->checkIntervalSec,
                'requireApproval' => $this->requireApproval,
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function revokeApproval(?string $version, int $actorUserId): array
    {
        return $this->withLock(function () use ($version, $actorUserId): array {
            $state = $this->stateRepository->getState();

            $approved = $state['approvedVersion'] ?? null;
            if ($approved === null) {
                return array_replace($state, [
                    'enabled' => $this->enabled,
                    'checkIntervalSec' => $this->checkIntervalSec,
                    'requireApproval' => $this->requireApproval,
                ]);
            }

            if ($version !== null) {
                $normalized = $this->versionComparator->normalize($version);
                if ($normalized === null || $normalized !== $approved) {
                    throw new \RuntimeException('Approval version mismatch.');
                }
            }

            $this->clearApprovalState($state);

            $latestVersion = is_string($state['latestVersion'] ?? null) ? (string) $state['latestVersion'] : null;
            $installedVersion = is_string($state['installedVersion'] ?? null) ? (string) $state['installedVersion'] : $this->readInstalledVersion();

            if ($latestVersion !== null && $this->versionComparator->compare($latestVersion, $installedVersion) > 0) {
                $state['status'] = 'update_available';
            } else {
                $state['status'] = 'idle';
            }

            $this->stateRepository->saveState($state);

            $this->audit($actorUserId, 'update.approval.revoked', [
                'version' => $approved,
            ]);

            return array_replace($state, [
                'enabled' => $this->enabled,
                'checkIntervalSec' => $this->checkIntervalSec,
                'requireApproval' => $this->requireApproval,
            ]);
        });
    }

    private function readInstalledVersion(): string
    {
        $path = $this->projectRoot . '/VERSION';
        if (!is_file($path)) {
            return '0.0.0';
        }

        $raw = trim((string) file_get_contents($path));
        $normalized = $this->versionComparator->normalize($raw);

        return $normalized ?? '0.0.0';
    }

    /**
     * @param array<string, mixed> $state
     */
    private function clearApprovalState(array &$state): void
    {
        $state['approvedVersion'] = null;
        $state['approvedBy'] = null;
        $state['approvedAt'] = null;
    }

    private function isCheckDue(mixed $lastCheckAt): bool
    {
        if (!is_string($lastCheckAt) || trim($lastCheckAt) === '') {
            return true;
        }

        $lastTimestamp = strtotime($lastCheckAt);
        if ($lastTimestamp === false) {
            return true;
        }

        if ($this->checkIntervalSec <= 0) {
            return true;
        }

        return (time() - $lastTimestamp) >= $this->checkIntervalSec;
    }

    /**
     * @param array<string, mixed> $details
     */
    private function audit(?int $userId, string $event, array $details): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO audit_logs (user_id, event, details_json, created_at)
             VALUES (:user_id, :event, :details_json, datetime('now'))"
        );

        $stmt->execute([
            ':user_id' => $userId,
            ':event' => $event,
            ':details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        $lockPath = $this->projectRoot . '/storage/updater.lock';
        $dir = dirname($lockPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open updater lock file.');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Updater is locked by another process.');
        }

        try {
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
