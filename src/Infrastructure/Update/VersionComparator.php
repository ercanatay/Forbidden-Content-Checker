<?php

declare(strict_types=1);

namespace ForbiddenChecker\Infrastructure\Update;

final class VersionComparator
{
    public function normalize(string $value): ?string
    {
        $candidate = trim($value);
        if ($candidate === '') {
            return null;
        }

        if (str_starts_with($candidate, 'v') || str_starts_with($candidate, 'V')) {
            $candidate = substr($candidate, 1);
        }

        if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $candidate, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1] . '.' . (int) $matches[2] . '.' . (int) $matches[3];
    }

    public function isStableTag(string $tag): bool
    {
        return preg_match('/^v\d+\.\d+\.\d+$/', trim($tag)) === 1;
    }

    public function versionFromTag(string $tag): ?string
    {
        if (!$this->isStableTag($tag)) {
            return null;
        }

        return $this->normalize(substr(trim($tag), 1));
    }

    public function compare(string $left, string $right): int
    {
        $a = $this->normalize($left);
        $b = $this->normalize($right);

        if ($a === null || $b === null) {
            throw new \InvalidArgumentException('Invalid semantic version input.');
        }

        [$amajor, $aminor, $apatch] = array_map('intval', explode('.', $a));
        [$bmajor, $bminor, $bpatch] = array_map('intval', explode('.', $b));

        if ($amajor !== $bmajor) {
            return $amajor <=> $bmajor;
        }

        if ($aminor !== $bminor) {
            return $aminor <=> $bminor;
        }

        return $apatch <=> $bpatch;
    }

    /**
     * @param array<int, string> $tags
     * @return array{tag: string, version: string}|null
     */
    public function latestStableFromTags(array $tags): ?array
    {
        $latestTag = null;
        $latestVersion = null;

        foreach ($tags as $tag) {
            $version = $this->versionFromTag($tag);
            if ($version === null) {
                continue;
            }

            if ($latestVersion === null || $this->compare($version, $latestVersion) > 0) {
                $latestVersion = $version;
                $latestTag = $tag;
            }
        }

        if ($latestTag === null || $latestVersion === null) {
            return null;
        }

        return [
            'tag' => $latestTag,
            'version' => $latestVersion,
        ];
    }
}
