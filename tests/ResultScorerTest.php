<?php

declare(strict_types=1);

namespace ForbiddenChecker\Tests;

use ForbiddenChecker\Domain\Scan\ResultScorer;

final class ResultScorerTest extends TestCase
{
    public function run(): void
    {
        $scorer = new ResultScorer();

        $high = $scorer->score('casino', 'Best Casino Tips', 'https://example.com/casino-guide', false);
        $low = $scorer->score('casino', 'Random Article', 'https://example.com/news', false);
        $regex = $scorer->score('cas.*', 'Casino bonus', 'https://example.com/casino', true);

        $this->assertTrue($high > $low, 'Expected higher severity for explicit keyword match.');
        $this->assertTrue($regex > $low, 'Expected regex mode to contribute to a higher score than unrelated content.');
        $this->assertTrue($high <= 100 && $low >= 0, 'Scores should stay in [0, 100].');
    }
}
