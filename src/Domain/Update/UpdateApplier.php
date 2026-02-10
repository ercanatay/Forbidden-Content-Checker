<?php

declare(strict_types=1);

namespace ForbiddenChecker\Domain\Update;

use ForbiddenChecker\Infrastructure\Logging\Logger;
use ForbiddenChecker\Infrastructure\Update\CommandRunner;
use ForbiddenChecker\Infrastructure\Update\UpdateStateRepository;
use ForbiddenChecker\Infrastructure\Update\VersionComparator;
use Closure;
use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

final class UpdateApplier
{
    /**
     * @param callable(string, string, string, ?string): void|null $zipFetcher
     */
    public function __construct(
        private readonly UpdateStateRepository $stateRepository,
        private readonly CommandRunner $commandRunner,
        private readonly VersionComparator $versionComparator,
        private readonly Logger $logger,
        private readonly PDO $pdo,
        private readonly string $projectRoot,
        private readonly string $dbPath,
        private readonly bool $enabled,
        private readonly bool $allowZipFallback,
        private readonly string $updateRepo,
        private readonly string $updateRemote,
        private readonly string $updateBranch,
        private readonly ?string $githubToken,
        private readonly ?Closure $zipFetcher = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function applyApprovedUpdate(?int $actorUserId = null): array
    {
        if (!$this->enabled) {
            return $this->stateRepository->getState();
        }

        return $this->withLock(function () use ($actorUserId): array {
            $state = $this->stateRepository->getState();
            $installedVersion = $this->readInstalledVersion();
            $state['installedVersion'] = $installedVersion;

            $approvedVersion = $this->versionComparator->normalize((string) ($state['approvedVersion'] ?? ''));
            if ($approvedVersion === null) {
                $this->stateRepository->saveState($state);
                return $state;
            }

            $latestVersion = $this->versionComparator->normalize((string) ($state['latestVersion'] ?? ''));
            if ($latestVersion !== null && $latestVersion !== $approvedVersion) {
                throw new \RuntimeException('Approved version does not match latest pending version.');
            }

            $runningJobs = $this->countActiveScanJobs();
            if ($runningJobs > 0) {
                $state['status'] = 'failed';
                $state['lastError'] = 'Cannot apply update while scan jobs are active.';
                $this->stateRepository->saveState($state);

                $this->logger->warning('Update apply aborted due to active scan jobs.', ['activeJobs' => $runningJobs]);
                throw new \RuntimeException('Cannot apply update while scan jobs are active.');
            }

            $targetTag = $this->resolveTargetTag($state, $approvedVersion);
            $state['status'] = 'applying';
            $state['lastError'] = null;
            $state['rollbackMeta'] = null;
            $this->stateRepository->saveState($state);

            $this->audit($actorUserId, 'update.apply.started', [
                'approvedVersion' => $approvedVersion,
                'targetTag' => $targetTag,
            ]);

            $backupMeta = [];
            $transport = null;
            $gitMeta = [
                'isRepository' => false,
                'head' => null,
                'branch' => null,
            ];

            try {
                $backupMeta = $this->prepareBackups($approvedVersion, $targetTag);

                $gitMeta = $this->captureGitMeta();

                if ($gitMeta['isRepository']) {
                    try {
                        $this->applyViaGit($targetTag);
                        $transport = 'git';
                    } catch (\Throwable $gitError) {
                        $isDirtyTree = str_contains($gitError->getMessage(), 'working tree is not clean');
                        if ($isDirtyTree || !$this->allowZipFallback) {
                            throw $gitError;
                        }

                        $this->logger->warning('Git apply failed, falling back to zip.', [
                            'targetTag' => $targetTag,
                            'error' => $gitError->getMessage(),
                        ]);

                        $this->applyViaZip($targetTag);
                        $transport = 'zip';
                    }
                } else {
                    if (!$this->allowZipFallback) {
                        throw new \RuntimeException('Git repository unavailable and zip fallback is disabled.');
                    }

                    $this->applyViaZip($targetTag);
                    $transport = 'zip';
                }

                $this->runPostApplyValidation();

                $updatedVersion = $this->readInstalledVersion();
                if ($this->versionComparator->compare($updatedVersion, $approvedVersion) < 0) {
                    throw new \RuntimeException('Installed version did not advance after update apply.');
                }

                $state = $this->stateRepository->getState();
                $state['installedVersion'] = $updatedVersion;
                $state['status'] = 'applied';
                $state['lastApplyAt'] = gmdate('c');
                $state['lastError'] = null;
                $state['lastTransport'] = $transport;
                $state['rollbackMeta'] = $backupMeta;
                $state['approvedVersion'] = null;
                $state['approvedBy'] = null;
                $state['approvedAt'] = null;
                $state['latestVersion'] = $updatedVersion;
                $state['latestTag'] = 'v' . $updatedVersion;
                $this->stateRepository->saveState($state);

                $this->audit($actorUserId, 'update.apply.succeeded', [
                    'approvedVersion' => $approvedVersion,
                    'installedVersion' => $updatedVersion,
                    'transport' => $transport,
                ]);

                return $state;
            } catch (\Throwable $e) {
                $rollbackSucceeded = false;
                $rollbackError = null;

                try {
                    $this->rollback($transport, $gitMeta, $backupMeta);
                    $rollbackSucceeded = true;
                } catch (\Throwable $rollbackException) {
                    $rollbackError = $rollbackException->getMessage();
                }

                $state = $this->stateRepository->getState();
                $state['installedVersion'] = $this->readInstalledVersion();
                $state['status'] = $rollbackSucceeded ? 'rolled_back' : 'failed';
                $state['lastApplyAt'] = gmdate('c');
                $state['lastError'] = $e->getMessage();
                $state['lastTransport'] = $transport;
                $state['rollbackMeta'] = array_replace($backupMeta, [
                    'rollbackSucceeded' => $rollbackSucceeded,
                    'rollbackError' => $rollbackError,
                ]);
                $this->stateRepository->saveState($state);

                $this->audit($actorUserId, 'update.apply.failed', [
                    'error' => $e->getMessage(),
                    'transport' => $transport,
                    'rollbackSucceeded' => $rollbackSucceeded,
                ]);

                if ($rollbackSucceeded) {
                    $this->audit($actorUserId, 'update.rollback.succeeded', [
                        'transport' => $transport,
                    ]);
                } else {
                    $this->audit($actorUserId, 'update.rollback.failed', [
                        'transport' => $transport,
                        'error' => $rollbackError,
                    ]);
                }

                throw new \RuntimeException('Update apply failed: ' . $e->getMessage(), 0, $e);
            }
        });
    }

    private function resolveTargetTag(array $state, string $approvedVersion): string
    {
        $latestTag = (string) ($state['latestTag'] ?? '');
        if ($this->versionComparator->isStableTag($latestTag)) {
            $latestTagVersion = $this->versionComparator->versionFromTag($latestTag);
            if ($latestTagVersion === $approvedVersion) {
                return $latestTag;
            }
        }

        return 'v' . $approvedVersion;
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareBackups(string $approvedVersion, string $targetTag): array
    {
        $backupDir = $this->projectRoot . '/storage/backups/updater';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0775, true);
        }

        $stamp = gmdate('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $dbBackupPath = $backupDir . '/checker-' . $stamp . '.sqlite';
        $codeSnapshotPath = $backupDir . '/code-' . $stamp . '.zip';

        $resolvedDbPath = $this->resolvePath($this->dbPath);
        if (!is_file($resolvedDbPath)) {
            throw new \RuntimeException('Database file does not exist for backup: ' . $resolvedDbPath);
        }

        if (!copy($resolvedDbPath, $dbBackupPath)) {
            throw new \RuntimeException('Unable to create database backup.');
        }

        $this->createCodeSnapshot($codeSnapshotPath);

        return [
            'createdAt' => gmdate('c'),
            'approvedVersion' => $approvedVersion,
            'targetTag' => $targetTag,
            'dbBackupPath' => $dbBackupPath,
            'codeSnapshotPath' => $codeSnapshotPath,
        ];
    }

    /**
     * @return array{isRepository: bool, head: string|null, branch: string|null}
     */
    private function captureGitMeta(): array
    {
        $inside = $this->commandRunner->run(['git', 'rev-parse', '--is-inside-work-tree'], $this->projectRoot, 20);
        if ($inside['exitCode'] !== 0 || trim($inside['stdout']) !== 'true') {
            return [
                'isRepository' => false,
                'head' => null,
                'branch' => null,
            ];
        }

        $topLevelRes = $this->commandRunner->run(['git', 'rev-parse', '--show-toplevel'], $this->projectRoot, 20);
        if ($topLevelRes['exitCode'] !== 0 || !$this->isGitRepositoryRoot(trim($topLevelRes['stdout']))) {
            return [
                'isRepository' => false,
                'head' => null,
                'branch' => null,
            ];
        }

        $headRes = $this->commandRunner->run(['git', 'rev-parse', 'HEAD'], $this->projectRoot, 20);
        if ($headRes['exitCode'] !== 0) {
            throw new \RuntimeException('Unable to resolve current git HEAD before update.');
        }

        $branchRes = $this->commandRunner->run(['git', 'branch', '--show-current'], $this->projectRoot, 20);
        $branch = $branchRes['exitCode'] === 0 ? trim($branchRes['stdout']) : '';

        return [
            'isRepository' => true,
            'head' => trim($headRes['stdout']),
            'branch' => $branch !== '' ? $branch : null,
        ];
    }

    private function isGitRepositoryRoot(string $topLevelPath): bool
    {
        if ($topLevelPath === '') {
            return false;
        }

        $resolvedProjectRoot = realpath($this->projectRoot);
        $resolvedTopLevel = realpath($topLevelPath);
        if ($resolvedProjectRoot === false || $resolvedTopLevel === false) {
            return false;
        }

        $normalizedProjectRoot = $this->normalizePath($resolvedProjectRoot);
        $normalizedTopLevel = $this->normalizePath($resolvedTopLevel);

        if (DIRECTORY_SEPARATOR === '\\') {
            return strtolower($normalizedProjectRoot) === strtolower($normalizedTopLevel);
        }

        return $normalizedProjectRoot === $normalizedTopLevel;
    }

    private function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $trimmed = rtrim($normalized, '/');

        return $trimmed === '' ? '/' : $trimmed;
    }

    private function applyViaGit(string $targetTag): void
    {
        $statusRes = $this->commandRunner->run(['git', 'status', '--porcelain'], $this->projectRoot, 20);
        if ($statusRes['exitCode'] !== 0) {
            throw new \RuntimeException('Unable to inspect git working tree status.');
        }

        if (trim($statusRes['stdout']) !== '') {
            throw new \RuntimeException('Git working tree is not clean; refusing to apply update.');
        }

        $this->runCommandOrThrow(['git', 'fetch', '--tags', $this->updateRemote], 90, 'Failed to fetch tags from remote.');
        $this->runCommandOrThrow(['git', 'checkout', $this->updateBranch], 60, 'Failed to checkout update branch.');
        $this->runCommandOrThrow(['git', 'merge', '--ff-only', $targetTag], 60, 'Failed to fast-forward merge target tag.');
    }

    private function applyViaZip(string $targetTag): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive extension is required for zip fallback updates.');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'fcc-update-');
        if ($zipPath === false) {
            throw new \RuntimeException('Unable to allocate temporary file for release zip.');
        }

        $tempExtractRoot = sys_get_temp_dir() . '/fcc-update-extract-' . bin2hex(random_bytes(6));
        mkdir($tempExtractRoot, 0775, true);

        try {
            $this->downloadReleaseZip($targetTag, $zipPath);

            $zip = new ZipArchive();
            $opened = $zip->open($zipPath);
            if ($opened !== true) {
                throw new \RuntimeException('Unable to open downloaded release zip.');
            }

            if (!$zip->extractTo($tempExtractRoot)) {
                $zip->close();
                throw new \RuntimeException('Unable to extract release zip archive.');
            }

            $zip->close();

            $sourceRoot = $this->detectExtractedRoot($tempExtractRoot);
            $this->replaceCodeFromSource($sourceRoot);
        } finally {
            @unlink($zipPath);
            $this->removeTree($tempExtractRoot);
        }
    }

    private function downloadReleaseZip(string $tag, string $destination): void
    {
        if (is_callable($this->zipFetcher)) {
            ($this->zipFetcher)($this->updateRepo, $tag, $destination, $this->githubToken);
            if (!is_file($destination) || filesize($destination) === 0) {
                throw new \RuntimeException('Custom zip fetcher did not produce a valid archive file.');
            }

            return;
        }

        $repoPath = implode('/', array_map('rawurlencode', explode('/', $this->updateRepo)));
        $url = sprintf('https://github.com/%s/archive/refs/tags/%s.zip', $repoPath, rawurlencode($tag));

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize cURL for release zip download.');
        }

        $fp = fopen($destination, 'wb');
        if ($fp === false) {
            curl_close($ch);
            throw new \RuntimeException('Unable to open destination file for release zip download.');
        }

        $headers = [
            'Accept: application/octet-stream',
            'User-Agent: ForbiddenContentChecker-Updater/1.0',
        ];
        if ($this->githubToken !== null && $this->githubToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->githubToken;
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $ok = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        curl_close($ch);
        fclose($fp);

        if ($ok === false || $statusCode < 200 || $statusCode >= 300) {
            @unlink($destination);
            $detail = $error !== '' ? $error : ('HTTP ' . $statusCode);
            throw new \RuntimeException('Release zip download failed: ' . $detail);
        }
    }

    private function runPostApplyValidation(): void
    {
        $criticalFiles = [
            $this->projectRoot . '/public/index.php',
            $this->projectRoot . '/src/bootstrap.php',
            $this->projectRoot . '/src/App.php',
        ];

        foreach ($criticalFiles as $file) {
            if (!is_file($file)) {
                throw new \RuntimeException('Missing critical file after update: ' . $file);
            }

            $result = $this->commandRunner->run(['php', '-l', $file], $this->projectRoot, 30);
            if ($result['exitCode'] !== 0) {
                throw new \RuntimeException('PHP lint failed for ' . $file . ': ' . trim($result['stderr'] . ' ' . $result['stdout']));
            }
        }

        $testCommand = ['php', $this->projectRoot . '/tests/run.php'];
        $testRes = $this->commandRunner->run($testCommand, $this->projectRoot, 300);
        if ($testRes['exitCode'] !== 0) {
            throw new \RuntimeException('Post-update test suite failed: ' . trim($testRes['stderr'] . ' ' . $testRes['stdout']));
        }
    }

    /**
     * @param array{isRepository: bool, head: string|null, branch: string|null} $gitMeta
     * @param array<string, mixed> $backupMeta
     */
    private function rollback(?string $transport, array $gitMeta, array $backupMeta): void
    {
        if ($transport === 'git' && $gitMeta['isRepository'] && is_string($gitMeta['head']) && $gitMeta['head'] !== '') {
            if (is_string($gitMeta['branch']) && $gitMeta['branch'] !== '') {
                $this->runCommandOrThrow(['git', 'checkout', $gitMeta['branch']], 60, 'Rollback failed while checking out previous branch.');
            }
            $this->runCommandOrThrow(['git', 'reset', '--hard', $gitMeta['head']], 60, 'Rollback failed while resetting git HEAD.');
        }

        if ($transport === 'zip' && isset($backupMeta['codeSnapshotPath']) && is_string($backupMeta['codeSnapshotPath'])) {
            $this->restoreCodeSnapshot($backupMeta['codeSnapshotPath']);
        }

        if (isset($backupMeta['dbBackupPath']) && is_string($backupMeta['dbBackupPath']) && is_file($backupMeta['dbBackupPath'])) {
            $resolvedDbPath = $this->resolvePath($this->dbPath);
            if (!copy($backupMeta['dbBackupPath'], $resolvedDbPath)) {
                throw new \RuntimeException('Rollback failed while restoring database backup.');
            }
        }
    }

    private function restoreCodeSnapshot(string $snapshotPath): void
    {
        if (!is_file($snapshotPath)) {
            throw new \RuntimeException('Code snapshot is missing for rollback.');
        }

        $extractRoot = sys_get_temp_dir() . '/fcc-rollback-' . bin2hex(random_bytes(6));
        mkdir($extractRoot, 0775, true);

        try {
            $zip = new ZipArchive();
            $opened = $zip->open($snapshotPath);
            if ($opened !== true) {
                throw new \RuntimeException('Unable to open rollback snapshot zip.');
            }

            if (!$zip->extractTo($extractRoot)) {
                $zip->close();
                throw new \RuntimeException('Unable to extract rollback snapshot zip.');
            }

            $zip->close();

            $sourceRoot = $this->detectExtractedRoot($extractRoot);
            $this->replaceCodeFromSource($sourceRoot);
        } finally {
            $this->removeTree($extractRoot);
        }
    }

    private function createCodeSnapshot(string $destinationPath): void
    {
        $zip = new ZipArchive();
        $opened = $zip->open($destinationPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new \RuntimeException('Unable to create code snapshot archive.');
        }

        $snapshotRoot = 'snapshot';
        $zip->addEmptyDir($snapshotRoot);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->projectRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $relative = ltrim(substr($path, strlen($this->projectRoot)), DIRECTORY_SEPARATOR);
            if ($relative === '') {
                continue;
            }

            if ($this->isExcludedTopLevelPath($relative)) {
                continue;
            }

            $zipPath = $snapshotRoot . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            if ($item->isDir()) {
                $zip->addEmptyDir($zipPath);
                continue;
            }

            $zip->addFile($path, $zipPath);
        }

        $zip->close();
    }

    private function detectExtractedRoot(string $extractRoot): string
    {
        $entries = [];
        foreach (new FilesystemIterator($extractRoot, FilesystemIterator::SKIP_DOTS) as $item) {
            $entries[] = $item->getPathname();
        }

        if (count($entries) !== 1 || !is_dir($entries[0])) {
            throw new \RuntimeException('Unexpected archive layout while extracting update package.');
        }

        return $entries[0];
    }

    private function replaceCodeFromSource(string $sourceRoot): void
    {
        $this->clearProjectRootExceptPreserved();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            $relative = ltrim(substr($sourcePath, strlen($sourceRoot)), DIRECTORY_SEPARATOR);
            if ($relative === '') {
                continue;
            }

            if ($this->isExcludedTopLevelPath($relative)) {
                continue;
            }

            $destinationPath = $this->projectRoot . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            if ($item->isDir()) {
                if (!is_dir($destinationPath)) {
                    mkdir($destinationPath, 0775, true);
                }
                continue;
            }

            $parent = dirname($destinationPath);
            if (!is_dir($parent)) {
                mkdir($parent, 0775, true);
            }

            if (!copy($sourcePath, $destinationPath)) {
                throw new \RuntimeException('Failed to copy file during update apply: ' . $relative);
            }
        }
    }

    private function clearProjectRootExceptPreserved(): void
    {
        $iterator = new FilesystemIterator($this->projectRoot, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            $name = $item->getFilename();
            if (in_array($name, ['.git', 'storage', '.env'], true)) {
                continue;
            }

            $this->removeTree($item->getPathname());
        }
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }

    private function isExcludedTopLevelPath(string $relativePath): bool
    {
        $normalized = str_replace('\\', '/', $relativePath);
        $topLevel = explode('/', $normalized)[0] ?? $normalized;

        return in_array($topLevel, ['.git', 'storage', '.env'], true);
    }

    private function readInstalledVersion(): string
    {
        $versionPath = $this->projectRoot . '/VERSION';
        if (!is_file($versionPath)) {
            return '0.0.0';
        }

        $raw = trim((string) file_get_contents($versionPath));
        $normalized = $this->versionComparator->normalize($raw);

        return $normalized ?? '0.0.0';
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        if (preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }

        return $this->projectRoot . '/' . ltrim($path, '/');
    }

    private function countActiveScanJobs(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(1) FROM scan_jobs WHERE status IN ('queued', 'running')")->fetchColumn();
    }

    /**
     * @param array<int, string> $command
     */
    private function runCommandOrThrow(array $command, int $timeoutSec, string $errorPrefix): void
    {
        $result = $this->commandRunner->run($command, $this->projectRoot, $timeoutSec);
        if ($result['exitCode'] === 0) {
            return;
        }

        $detail = trim($result['stderr'] . "\n" . $result['stdout']);
        throw new \RuntimeException($errorPrefix . ' ' . $detail);
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
