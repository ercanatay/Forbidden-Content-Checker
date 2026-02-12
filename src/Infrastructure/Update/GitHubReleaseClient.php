<?php

declare(strict_types=1);

namespace ForbiddenChecker\Infrastructure\Update;

use Closure;

final class GitHubReleaseClient implements ReleaseClientInterface
{
    /**
     * @param callable(string, ?string): array<int, string>|null $tagFetcher
     */
    public function __construct(
        private readonly string $repo,
        private readonly ?string $githubToken,
        private readonly VersionComparator $versionComparator,
        private readonly ?Closure $tagFetcher = null
    ) {
    }

    public function latestStableTag(): ?array
    {
        $tags = $this->fetchTags();
        return $this->versionComparator->latestStableFromTags($tags);
    }

    /**
     * @return array<int, string>
     */
    private function fetchTags(): array
    {
        if (is_callable($this->tagFetcher)) {
            $result = ($this->tagFetcher)($this->repo, $this->githubToken);
            if (!is_array($result)) {
                throw new \RuntimeException('Custom tag fetcher returned invalid data.');
            }

            return array_values(array_filter(array_map(static fn ($item): string => (string) $item, $result), static fn (string $tag): bool => $tag !== ''));
        }

        $repoPath = implode('/', array_map('rawurlencode', explode('/', $this->repo)));
        $url = sprintf('https://api.github.com/repos/%s/tags?per_page=100', $repoPath);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize cURL for GitHub tags request.');
        }

        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: CybokronForbiddenContentChecker-Updater/1.0',
        ];

        if ($this->githubToken !== null && $this->githubToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->githubToken;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('GitHub tags request failed: ' . $error);
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException('GitHub tags request returned HTTP ' . $statusCode . '.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid GitHub API response while parsing tags.');
        }

        $tags = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || !isset($row['name']) || !is_string($row['name'])) {
                continue;
            }
            $tags[] = $row['name'];
        }

        return $tags;
    }
}
