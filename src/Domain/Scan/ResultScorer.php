<?php

declare(strict_types=1);

namespace ForbiddenChecker\Domain\Scan;

final class ResultScorer
{
    public function score(string $keyword, string $title, string $url, bool $regexMode): int
    {
        $score = 40;

        if (str_contains(mb_strtolower($title, 'UTF-8'), mb_strtolower($keyword, 'UTF-8'))) {
            $score += 30;
        }

        if (str_contains(mb_strtolower($url, 'UTF-8'), mb_strtolower($keyword, 'UTF-8'))) {
            $score += 15;
        }

        if ($regexMode) {
            $score += 10;
        }

        if (preg_match('/casino|bet|gambl|slot|poker|adult|pharma|crypto/i', $title . ' ' . $url) === 1) {
            $score += 5;
        }

        return max(0, min(100, $score));
    }
}
