<?php

declare(strict_types=1);

namespace GitConstellation\Tests\Cli;

use DateTimeImmutable;
use GitConstellation\Cli\Summary;
use GitConstellation\Data\ContributionCalendar;
use GitConstellation\Data\LiveSnapshot;
use GitConstellation\Data\Period;
use GitConstellation\Statistics\PrivateContributions;
use GitConstellation\Statistics\VisibleTotals;
use PHPUnit\Framework\TestCase;

final class SummaryTest extends TestCase
{
    /** @param list<array{name: string, repositories: int, color: string}> $languages @param list<string> $scopes */
    private function snapshot(array $languages, array $scopes): LiveSnapshot
    {
        $calendar = ContributionCalendar::fromArray(
            ['start' => '2026-01-01', 'end' => '2026-01-05'],
            [
                ['date' => '2026-01-01', 'count' => 3],
                ['date' => '2026-01-02', 'count' => 2],
                ['date' => '2026-01-03', 'count' => 0],
                ['date' => '2026-01-04', 'count' => 1],
                ['date' => '2026-01-05', 'count' => 0],
            ],
        );

        return new LiveSnapshot(
            'octo-sample',
            $calendar,
            7,
            5,
            new VisibleTotals(4, 1, 7, 5, 2),
            2,
            PrivateContributions::Included,
            true,
            $languages,
            12,
            new DateTimeImmutable('2026-11-06T12:00:00Z'),
            4987,
            $scopes,
        );
    }

    private function period(): Period
    {
        return Period::trailingYear(new DateTimeImmutable('2026-11-06T00:00:00Z'));
    }

    public function testFormatsAllLines(): void
    {
        $languages = [
            ['name' => 'PHP', 'repositories' => 8, 'color' => '#4F5D95'],
            ['name' => 'TypeScript', 'repositories' => 4, 'color' => '#3178c6'],
        ];

        $expected = "LIVE — GitHub statistics for octo-sample\n"
            . "Period (UTC): 2025-11-07 .. 2026-11-06\n"
            . "Generated at: 2026-11-06T12:00:00Z\n"
            . "Contributions (calendar): 6\n"
            . "Private contributions: included (restricted count: 2, own account: yes)\n"
            . "Pull Requests (visible to token): 7\n"
            . "Code Reviews (visible to token): 5\n"
            . "Active Days: 3\n"
            . "Longest Streak: 2\n"
            . "Repositories scanned (public, non-fork): 12\n"
            . "Top Languages: PHP (8), TypeScript (4)\n"
            . "Token scopes: read:user\n"
            . "Rate limit remaining: 4987\n";

        self::assertSame($expected, Summary::format($this->snapshot($languages, ['read:user']), $this->period()));
    }

    public function testNoLanguagesPrintsNone(): void
    {
        self::assertStringContainsString("Top Languages: none\n", Summary::format($this->snapshot([], ['read:user']), $this->period()));
    }

    public function testMissingScopesPrintsNotReported(): void
    {
        self::assertStringContainsString("Token scopes: (not reported)\n", Summary::format($this->snapshot([], []), $this->period()));
    }
}
