<?php

declare(strict_types=1);

namespace ForbiddenChecker\Domain\Scan;

use PDO;

final class SuppressionService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function activeRules(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, name, pattern, scope_domain, created_by
             FROM suppression_rules
             WHERE is_active = 1"
        );

        return $stmt->fetchAll() ?: [];
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
