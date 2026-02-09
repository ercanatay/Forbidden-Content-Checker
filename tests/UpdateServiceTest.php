<?php

declare(strict_types=1);

namespace ForbiddenChecker\Tests;

use ForbiddenChecker\Domain\Update\UpdateService;
use ForbiddenChecker\Infrastructure\Db\Database;
use ForbiddenChecker\Infrastructure\Db\Migrator;
use ForbiddenChecker\Infrastructure\Logging\Logger;
use ForbiddenChecker\Infrastructure\Update\ReleaseClientInterface;
use ForbiddenChecker\Infrastructure\Update\UpdateStateRepository;
use ForbiddenChecker\Infrastructure\Update\VersionComparator;

final class UpdateServiceTest extends TestCase
{
    public function run(): void
    {
        $this->testNoUpdateScenario();
        $this->testUpdateAvailableScenario();
        $this->testReleaseClientErrorScenario();
        $this->testInvalidLatestVersionScenario();
    }

    private function testNoUpdateScenario(): void
    {
        [$stateRepo, $service] = $this->buildService(new FixedReleaseClient([
            'tag' => 'v3.0.0',
            'version' => '3.0.0',
        ]));

        $stateRepo->saveState(['installedVersion' => '3.0.0']);
        $state = $service->checkForUpdates(true, 1);

        $this->assertSame('idle', $state['status']);
        $this->assertSame('3.0.0', $state['latestVersion']);
    }

    private function testUpdateAvailableScenario(): void
    {
        [$stateRepo, $service] = $this->buildService(new FixedReleaseClient([
            'tag' => 'v99.0.0',
            'version' => '99.0.0',
        ]));

        $stateRepo->saveState(['installedVersion' => '1.0.0']);
        $state = $service->checkForUpdates(true, 1);

        $this->assertSame('update_available', $state['status']);
        $this->assertSame('99.0.0', $state['latestVersion']);
        $this->assertSame('v99.0.0', $state['latestTag']);
    }

    private function testReleaseClientErrorScenario(): void
    {
        [, $service] = $this->buildService(new ThrowingReleaseClient('simulated API failure'));

        $state = $service->checkForUpdates(true, 1);
        $this->assertSame('failed', $state['status']);
        $this->assertTrue(str_contains((string) $state['lastError'], 'simulated API failure'));
    }

    private function testInvalidLatestVersionScenario(): void
    {
        [, $service] = $this->buildService(new FixedReleaseClient([
            'tag' => 'v3.1.0-rc1',
            'version' => '3.1.0-rc1',
        ]));

        $state = $service->checkForUpdates(true, 1);
        $this->assertSame('failed', $state['status']);
        $this->assertTrue(str_contains((string) $state['lastError'], 'Invalid version payload'));
    }

    /**
     * @return array{0: UpdateStateRepository, 1: UpdateService}
     */
    private function buildService(ReleaseClientInterface $client): array
    {
        $dbPath = dirname(__DIR__) . '/storage/test-update-service-' . bin2hex(random_bytes(4)) . '.sqlite';
        @unlink($dbPath);

        $db = new Database($dbPath);
        $migrator = new Migrator($db->pdo(), dirname(__DIR__) . '/database/schema.sql');
        $migrator->migrate();

        $stateRepo = new UpdateStateRepository($db->pdo());
        $logger = new Logger(dirname(__DIR__) . '/storage/logs/test-update-service.log', true);

        $service = new UpdateService(
            $stateRepo,
            $client,
            new VersionComparator(),
            $logger,
            $db->pdo(),
            dirname(__DIR__),
            true,
            0,
            true
        );

        return [$stateRepo, $service];
    }
}

final class FixedReleaseClient implements ReleaseClientInterface
{
    /**
     * @param array{tag: string, version: string}|null $payload
     */
    public function __construct(private readonly ?array $payload)
    {
    }

    public function latestStableTag(): ?array
    {
        return $this->payload;
    }
}

final class ThrowingReleaseClient implements ReleaseClientInterface
{
    public function __construct(private readonly string $message)
    {
    }

    public function latestStableTag(): ?array
    {
        throw new \RuntimeException($this->message);
    }
}
