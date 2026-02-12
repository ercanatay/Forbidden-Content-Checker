<?php

declare(strict_types=1);

namespace ForbiddenChecker\Tests;

use ForbiddenChecker\Domain\Update\UpdateApplier;
use ForbiddenChecker\Infrastructure\Db\Database;
use ForbiddenChecker\Infrastructure\Db\Migrator;
use ForbiddenChecker\Infrastructure\Logging\Logger;
use ForbiddenChecker\Infrastructure\Update\CommandRunner;
use ForbiddenChecker\Infrastructure\Update\UpdateStateRepository;
use ForbiddenChecker\Infrastructure\Update\VersionComparator;

final class UpdateApplierTest extends TestCase
{
    public function run(): void
    {
        $this->testNoApprovalNoop();
        $this->testGitApplySuccess();
        $this->testGitFailureZipFallbackSuccess();
        $this->testValidationFailureRollbackSuccess();
    }

    private function testNoApprovalNoop(): void
    {
        [$root, $pdo, $stateRepo] = $this->createFixtureProject('no-approval', '1.0.0', true);
        $applier = $this->buildApplier($root, $pdo, null);

        $state = $applier->applyApprovedUpdate(1);
        $this->assertSame('1.0.0', trim((string) file_get_contents($root . '/VERSION')));
        $this->assertSame(null, $state['approvedVersion']);
    }

    private function testGitApplySuccess(): void
    {
        [$root, $pdo, $stateRepo] = $this->createFixtureProject('git-success', '1.0.0', true);
        $this->initGitRepository($root);

        $this->writeProjectFiles($root, '1.0.1', true);
        $this->runOrFail(['git', 'add', '.'], $root);
        $this->runOrFail(['git', 'commit', '-m', 'release 1.0.1'], $root);
        $this->runOrFail(['git', 'tag', 'v1.0.1'], $root);

        $this->runOrFail(['git', 'reset', '--hard', 'v1.0.0'], $root);
        $this->runOrFail(['git', 'remote', 'add', 'origin', '.'], $root);

        $stateRepo->saveState([
            'installedVersion' => '1.0.0',
            'latestVersion' => '1.0.1',
            'latestTag' => 'v1.0.1',
            'status' => 'approved',
            'approvedVersion' => '1.0.1',
            'approvedBy' => 1,
            'approvedAt' => gmdate('c'),
        ]);

        $applier = $this->buildApplier($root, $pdo, null, true, 'origin', 'main');
        $state = $applier->applyApprovedUpdate(1);

        $this->assertSame('applied', $state['status']);
        $this->assertSame('git', $state['lastTransport']);
        $this->assertSame('1.0.1', trim((string) file_get_contents($root . '/VERSION')));
    }

    private function testGitFailureZipFallbackSuccess(): void
    {
        [$root, $pdo, $stateRepo] = $this->createFixtureProject('git-fallback', '1.0.0', true);
        $this->initGitRepository($root);
        $this->runOrFail(['git', 'remote', 'add', 'origin', '/tmp/non-existent-fcc-remote'], $root);

        $releaseZip = $this->createReleaseZip('1.0.1', true);

        $stateRepo->saveState([
            'installedVersion' => '1.0.0',
            'latestVersion' => '1.0.1',
            'latestTag' => 'v1.0.1',
            'status' => 'approved',
            'approvedVersion' => '1.0.1',
            'approvedBy' => 1,
            'approvedAt' => gmdate('c'),
        ]);

        $zipFetcher = static function (string $repo, string $tag, string $destination) use ($releaseZip): void {
            copy($releaseZip, $destination);
        };

        $applier = $this->buildApplier($root, $pdo, $zipFetcher, true, 'origin', 'main');
        $state = $applier->applyApprovedUpdate(1);

        $this->assertSame('applied', $state['status']);
        $this->assertSame('zip', $state['lastTransport']);
        $this->assertSame('1.0.1', trim((string) file_get_contents($root . '/VERSION')));
    }

    private function testValidationFailureRollbackSuccess(): void
    {
        [$root, $pdo, $stateRepo] = $this->createFixtureProject('rollback', '1.0.0', true);

        $releaseZip = $this->createReleaseZip('1.0.1', false);

        $stateRepo->saveState([
            'installedVersion' => '1.0.0',
            'latestVersion' => '1.0.1',
            'latestTag' => 'v1.0.1',
            'status' => 'approved',
            'approvedVersion' => '1.0.1',
            'approvedBy' => 1,
            'approvedAt' => gmdate('c'),
        ]);

        $zipFetcher = static function (string $repo, string $tag, string $destination) use ($releaseZip): void {
            copy($releaseZip, $destination);
        };

        $applier = $this->buildApplier($root, $pdo, $zipFetcher, true, 'origin', 'main');

        $failed = false;
        try {
            $applier->applyApprovedUpdate(1);
        } catch (\RuntimeException) {
            $failed = true;
        }

        $this->assertTrue($failed, 'Apply should fail when post-update validation fails.');

        $state = $stateRepo->getState();
        $this->assertSame('rolled_back', $state['status']);
        $this->assertSame('1.0.0', trim((string) file_get_contents($root . '/VERSION')));

        $testResult = $this->runCommand(['php', $root . '/tests/run.php'], $root);
        $this->assertSame(0, $testResult['code'], 'Rollback should restore working tests.');
    }

    /**
     * @return array{0: string, 1: \PDO, 2: UpdateStateRepository}
     */
    private function createFixtureProject(string $prefix, string $version, bool $testsPass): array
    {
        $root = dirname(__DIR__) . '/storage/test-fixtures/' . $prefix . '-' . bin2hex(random_bytes(4));

        if (!is_dir($root)) {
            mkdir($root, 0775, true);
        }

        $this->writeProjectFiles($root, $version, $testsPass);

        $dbPath = $root . '/storage/checker.sqlite';
        $db = new Database($dbPath);
        $migrator = new Migrator($db->pdo(), dirname(__DIR__) . '/database/schema.sql');
        $migrator->migrate();

        return [$root, $db->pdo(), new UpdateStateRepository($db->pdo())];
    }

    private function writeProjectFiles(string $root, string $version, bool $testsPass): void
    {
        @mkdir($root . '/public', 0775, true);
        @mkdir($root . '/src', 0775, true);
        @mkdir($root . '/tests', 0775, true);
        @mkdir($root . '/storage/logs', 0775, true);

        file_put_contents($root . '/VERSION', $version . "\n");
        file_put_contents($root . '/.gitignore', "storage/\n.env\n");
        file_put_contents($root . '/public/index.php', "<?php\n\ndeclare(strict_types=1);\n\necho 'ok';\n");
        file_put_contents($root . '/src/bootstrap.php', "<?php\n\ndeclare(strict_types=1);\n\n");
        file_put_contents($root . '/src/App.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixture;\n\nfinal class App {}\n");
        file_put_contents($root . '/tests/run.php', "<?php\n\ndeclare(strict_types=1);\n\nexit(" . ($testsPass ? '0' : '1') . ");\n");
    }

    /**
     * @param callable(string, string, string, ?string): void|null $zipFetcher
     */
    private function buildApplier(
        string $root,
        \PDO $pdo,
        ?callable $zipFetcher,
        bool $allowZipFallback = true,
        string $remote = 'origin',
        string $branch = 'main'
    ): UpdateApplier {
        return new UpdateApplier(
            new UpdateStateRepository($pdo),
            new CommandRunner(),
            new VersionComparator(),
            new Logger($root . '/storage/logs/app.log', true),
            $pdo,
            $root,
            $root . '/storage/checker.sqlite',
            true,
            $allowZipFallback,
            'ercanatay/cybokron-forbidden-content-checker',
            $remote,
            $branch,
            null,
            $zipFetcher
        );
    }

    private function initGitRepository(string $root): void
    {
        $this->runOrFail(['git', 'init', '-b', 'main'], $root);
        $this->runOrFail(['git', 'config', 'user.email', 'tester@example.com'], $root);
        $this->runOrFail(['git', 'config', 'user.name', 'Tester'], $root);
        $this->runOrFail(['git', 'add', '.'], $root);
        $this->runOrFail(['git', 'commit', '-m', 'release 1.0.0'], $root);
        $this->runOrFail(['git', 'tag', 'v1.0.0'], $root);
    }

    private function createReleaseZip(string $version, bool $testsPass): string
    {
        $tempRoot = sys_get_temp_dir() . '/fcc-release-src-' . bin2hex(random_bytes(4));
        $topDirName = 'cybokron-forbidden-content-checker-v' . $version;
        $releaseRoot = $tempRoot . '/' . $topDirName;
        mkdir($releaseRoot . '/public', 0775, true);
        mkdir($releaseRoot . '/src', 0775, true);
        mkdir($releaseRoot . '/tests', 0775, true);

        file_put_contents($releaseRoot . '/VERSION', $version . "\n");
        file_put_contents($releaseRoot . '/public/index.php', "<?php\n\ndeclare(strict_types=1);\n\necho 'ok';\n");
        file_put_contents($releaseRoot . '/src/bootstrap.php', "<?php\n\ndeclare(strict_types=1);\n\n");
        file_put_contents($releaseRoot . '/src/App.php', "<?php\n\ndeclare(strict_types=1);\n\nnamespace Fixture;\n\nfinal class App {}\n");
        file_put_contents($releaseRoot . '/tests/run.php', "<?php\n\ndeclare(strict_types=1);\n\nexit(" . ($testsPass ? '0' : '1') . ");\n");

        $zipPath = sys_get_temp_dir() . '/fcc-release-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new \ZipArchive();
        $opened = $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new \RuntimeException('Unable to create test release zip.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tempRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $relative = ltrim(substr($path, strlen($tempRoot)), DIRECTORY_SEPARATOR);
            $zipPathEntry = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            if ($item->isDir()) {
                $zip->addEmptyDir($zipPathEntry);
            } else {
                $zip->addFile($path, $zipPathEntry);
            }
        }

        $zip->close();
        $this->removeTree($tempRoot);

        return $zipPath;
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

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
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

    /**
     * @param array<int, string> $command
     */
    private function runOrFail(array $command, string $cwd): string
    {
        $result = $this->runCommand($command, $cwd);
        if ($result['code'] !== 0) {
            throw new \RuntimeException('Command failed: ' . implode(' ', $command) . ' | ' . $result['output']);
        }

        return trim($result['output']);
    }

    /**
     * @param array<int, string> $command
     * @return array{code: int, output: string}
     */
    private function runCommand(array $command, string $cwd): array
    {
        $escaped = implode(' ', array_map(static fn (string $part): string => escapeshellarg($part), $command));
        $full = 'cd ' . escapeshellarg($cwd) . ' && ' . $escaped . ' 2>&1';

        $output = [];
        $code = 0;
        exec($full, $output, $code);

        return [
            'code' => $code,
            'output' => implode("\n", $output),
        ];
    }
}
