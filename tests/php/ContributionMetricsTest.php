<?php

declare(strict_types=1);

namespace GitConstellation\Tests;

use DateTimeImmutable;
use DateTimeZone;
use GitConstellation\Data\ContributionCalendar;
use GitConstellation\Statistics\ContributionMetrics;
use GitConstellation\Support\IntegerRange;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContributionMetricsTest extends TestCase
{
    public function testTotalsActiveDaysAndLongestStreakIncludingFinalRun(): void
    {
        $metrics = ContributionMetrics::calculate($this->calendar([1, 2, 0, 5, 0, 1, 4, 2]));
        self::assertSame(15, $metrics->totalContributions);
        self::assertSame(6, $metrics->activeDays);
        self::assertSame(3, $metrics->longestStreak);
    }

    public function testEntirelyInactiveCalendar(): void
    {
        $metrics = ContributionMetrics::calculate($this->calendar([0, 0, 0]));
        self::assertSame(0, $metrics->totalContributions);
        self::assertSame(0, $metrics->activeDays);
        self::assertSame(0, $metrics->longestStreak);
    }

    public function testStreakIsBoundedByAvailableCalendar(): void
    {
        $metrics = ContributionMetrics::calculate($this->calendar([1, 1, 1]));
        self::assertSame(3, $metrics->longestStreak);
    }

    public function testExactJavaScriptIntegerBoundaryIsPreserved(): void
    {
        $metrics = ContributionMetrics::calculate($this->calendar([IntegerRange::MAX_SAFE - 1, 1]));
        self::assertSame(IntegerRange::MAX_SAFE, $metrics->totalContributions);
        self::assertSame(2, $metrics->activeDays);
    }

    public function testIndividuallySafeDaysCannotOverflowJavaScriptTotal(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('total exceeds the JavaScript-safe');
        ContributionMetrics::calculate($this->calendar([IntegerRange::MAX_SAFE, 1]));
    }

    /** @param list<int> $counts */
    private function calendar(array $counts): ContributionCalendar
    {
        $start = new DateTimeImmutable('2025-12-29', new DateTimeZone('UTC'));
        $days = [];
        foreach ($counts as $index => $count) {
            $days[] = ['date' => $start->modify("+{$index} days")->format('Y-m-d'), 'count' => $count];
        }

        return ContributionCalendar::fromArray(['start' => $days[0]['date'], 'end' => $days[array_key_last($days)]['date']], $days);
    }
}
