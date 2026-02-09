<?php

declare(strict_types=1);

namespace ForbiddenChecker\Infrastructure\Update;

interface ReleaseClientInterface
{
    /**
     * @return array{tag: string, version: string}|null
     */
    public function latestStableTag(): ?array;
}
