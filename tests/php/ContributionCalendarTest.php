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
