<?php

declare(strict_types=1);

namespace GitConstellation\Tests;

use GitConstellation\Data\ContributionCalendar;
use GitConstellation\Support\IntegerRange;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContributionCalendarTest extends TestCase
{
    /** @param list<int> $counts */
    private static function calendar(string $start, array $counts): ContributionCalendar
    {
        $days = [];
        $date = new \DateTimeImmutable($start);
        foreach ($counts as $count) {
            $days[] = ['date' => $date->format('Y-m-d'), 'count' => $count];
            $date = $date->modify('+1 day');
        }

        return ContributionCalendar::fromArray(['start' => $start, 'end' => end($days)['date']], $days);
    }

    public function testConcatenateJoinsContiguousCalendarsBeyondOneYear(): void
    {
        $first = self::calendar('2024-12-30', [1, 2]);
        $long = ContributionCalendar::concatenate([
            $first,
            ContributionCalendar::fromArray(['start' => '2025-01-01', 'end' => '2025-12-31'], array_map(
                fn (int $offset): array => ['date' => (new \DateTimeImmutable('2025-01-01'))->modify("+{$offset} day")->format('Y-m-d'), 'count' => 0],
                range(0, 364),
            )),
            self::calendar('2026-01-01', [3]),
        ]);

        self::assertSame('2024-12-30', $long->start);
        self::assertSame('2026-01-01', $long->end);
        self::assertCount(368, $long->days);
        self::assertSame(['date' => '2026-01-01', 'count' => 3], $long->days[367]);
    }

    public function testConcatenateRejectsGaps(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ContributionCalendar::concatenate([self::calendar('2025-12-30', [1]), self::calendar('2026-01-01', [1])]);
    }

    public function testConcatenateRejectsOverlaps(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ContributionCalendar::concatenate([self::calendar('2025-12-30', [1, 1]), self::calendar('2025-12-31', [1])]);
    }

    public function testConcatenateRejectsAnEmptyList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ContributionCalendar::concatenate([]);
    }

    public function testFromFirstContributionDropsLeadingEmptyDays(): void
    {
        $calendar = self::calendar('2023-10-21', [0, 0, 4, 0, 1])->fromFirstContribution();

        self::assertSame('2023-10-23', $calendar->start);
        self::assertSame('2023-10-25', $calendar->end);
        self::assertSame([4, 0, 1], array_column($calendar->days, 'count'));
    }

    public function testFromFirstContributionWithoutActivityKeepsOnlyTheLastDay(): void
    {
        $calendar = self::calendar('2026-01-01', [0, 0, 0])->fromFirstContribution();

        self::assertSame('2026-01-03', $calendar->start);
        self::assertSame([['date' => '2026-01-03', 'count' => 0]], $calendar->days);
    }

    public function testUnsortedInputIsNormalizedByDateAcrossYearBoundary(): void
    {
        $calendar = ContributionCalendar::fromArray(
            ['start' => '2025-12-31', 'end' => '2026-01-02'],
            [['date' => '2026-01-02', 'count' => 2], ['date' => '2025-12-31', 'count' => 0], ['date' => '2026-01-01', 'count' => 3]],
        );

        self::assertSame(['2025-12-31', '2026-01-01', '2026-01-02'], array_column($calendar->days, 'date'));
        self::assertSame([0, 3, 2], array_column($calendar->days, 'count'));
    }

    public function testMissingDayIsRejectedRatherThanFilledWithZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing 2026-01-02');
        ContributionCalendar::fromArray(
            ['start' => '2026-01-01', 'end' => '2026-01-03'],
            [['date' => '2026-01-01', 'count' => 1], ['date' => '2026-01-03', 'count' => 2]],
        );
    }

    public function testDuplicateDayIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate');
        ContributionCalendar::fromArray(
            ['start' => '2026-01-01', 'end' => '2026-01-01'],
            [['date' => '2026-01-01', 'count' => 1], ['date' => '2026-01-01', 'count' => 2]],
        );
    }

    #[DataProvider('invalidDates')]
    public function testInvalidDatesAreRejected(mixed $date): void
    {
        $this->expectException(InvalidArgumentException::class);
        ContributionCalendar::fromArray(['start' => $date, 'end' => $date], []);
    }

    public static function invalidDates(): iterable
    {
        yield 'non-leap February' => ['2026-02-29'];
        yield 'April 31' => ['2026-04-31'];
        yield 'month 13' => ['2026-13-01'];
        yield 'missing padding' => ['2026-1-01'];
        yield 'timestamp instead of date' => ['2026-01-01T00:00:00Z'];
        yield 'null' => [null];
        yield 'year zero' => ['0000-01-01'];
    }

    public function testLeapDayIsAccepted(): void
    {
        $calendar = ContributionCalendar::fromArray(
            ['start' => '2024-02-28', 'end' => '2024-03-01'],
            [['date' => '2024-02-28', 'count' => 0], ['date' => '2024-02-29', 'count' => 1], ['date' => '2024-03-01', 'count' => 0]],
        );
        self::assertCount(3, $calendar->days);
    }

    public function testOutOfPeriodDayIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside');
        ContributionCalendar::fromArray(
            ['start' => '2026-01-01', 'end' => '2026-01-01'],
            [['date' => '2026-01-02', 'count' => 1]],
        );
    }

    public function testInvertedPeriodIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ContributionCalendar::fromArray(['start' => '2026-01-02', 'end' => '2026-01-01'], []);
    }

    public function testPeriodOf367DaysIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 366');
        ContributionCalendar::fromArray(['start' => '2025-01-01', 'end' => '2026-01-02'], []);
    }

    #[DataProvider('invalidCounts')]
    public function testInvalidOrUnsafeCountIsRejected(mixed $count): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JavaScript-safe');
        ContributionCalendar::fromArray(
            ['start' => '2026-01-01', 'end' => '2026-01-01'],
            [['date' => '2026-01-01', 'count' => $count]],
        );
    }

    public static function invalidCounts(): iterable
    {
        yield 'negative' => [-1];
        yield 'fraction' => [1.5];
        yield 'string' => ['1'];
        yield 'boolean' => [true];
        yield 'unavailable' => [null];
        yield 'above JavaScript maximum' => [IntegerRange::MAX_SAFE + 1];
    }
}
