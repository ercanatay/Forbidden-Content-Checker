<?php

declare(strict_types=1);

namespace ForbiddenChecker\Domain\Scan;

use PDO;

final class SuppressionService
{
    /** @var array<int, array<string, mixed>>|null In-memory cache of active suppression rules to avoid repeated DB queries during a single scan */
    private ?array $cachedRules = null;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeRules(): array
    {
        // Return cached rules if available — suppression rules don't change mid-scan,
        // so querying once per service lifetime eliminates O(N) duplicate queries
        // where N = number of matches checked during a scan.
        if ($this->cachedRules !== null) {
            return $this->cachedRules;
        }

        $stmt = $this->pdo->query(
            "SELECT id, name, pattern, scope_domain, created_by
             FROM suppression_rules
             WHERE is_active = 1"
        );

        $this->cachedRules = $stmt->fetchAll() ?: [];

        return $this->cachedRules;
    }

    /**
     * Clear the in-memory rules cache, forcing a fresh DB query on the next call.
     * Useful after suppression rules have been modified.
     */
    public function clearCache(): void
    {
        $this->cachedRules = null;
    }

    public function isSuppressed(string $title, string $url, string $domain): bool
    {
        $rules = $this->activeRules();
        foreach ($rules as $rule) {
            $scope = (string) ($rule['scope_domain'] ?? '');
            if ($scope !== '' && strcasecmp($scope, $domain) !== 0) {
                continue;
            }

            $pattern = (string) ($rule['pattern'] ?? '');
            if ($pattern === '') {
                continue;
            }

            $regex = '/' . str_replace('/', '\\/', $pattern) . '/iu';
            if (@preg_match($regex, $title) === 1 || @preg_match($regex, $url) === 1) {
                return true;
            }
        }

        return false;
    }
}
