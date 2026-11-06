<?php

declare(strict_types=1);

namespace GitConstellation\Tests\Data;

use DateTimeImmutable;
use GitConstellation\Data\Period;
use PHPUnit\Framework\TestCase;

final class PeriodTest extends TestCase
{
    public function testTrailingYearForOrdinaryDate(): void
    {
        $period = Period::trailingYear(new DateTimeImmutable('2026-11-06T10:00:00Z'));
        self::assertSame('2025-11-07', $period->start);
        self::assertSame('2026-11-06', $period->end);
        self::assertSame(365, $period->dayCount());
        self::assertSame('2025-11-07T00:00:00Z', $period->fromDateTime());
        self::assertSame('2026-11-06T23:59:59Z', $period->toDateTime());
    }

    public function testPeriodSpanningLeapDayHas366Days(): void
    {
        $period = Period::trailingYear(new DateTimeImmutable('2028-03-01T00:00:00Z'));
        self::assertSame('2027-03-02', $period->start);
        self::assertSame(366, $period->dayCount());
    }

    public function testPeriodEndingOnLeapDayHas365Days(): void
    {
        $period = Period::trailingYear(new DateTimeImmutable('2028-02-29T00:00:00Z'));
        self::assertSame('2027-03-02', $period->start);
        self::assertSame(365, $period->dayCount());
    }

    public function testNowIsConvertedToUtcDate(): void
    {
        $period = Period::trailingYear(new DateTimeImmutable('2026-11-06T23:30:00-05:00'));
        self::assertSame('2026-11-07', $period->end);
    }

    public function testContainsIsInclusive(): void
    {
        $period = Period::trailingYear(new DateTimeImmutable('2026-11-06T10:00:00Z'));
        self::assertTrue($period->contains('2025-11-07'));
        self::assertTrue($period->contains('2026-11-06'));
        self::assertFalse($period->contains('2025-11-06'));
        self::assertFalse($period->contains('2026-11-07'));
    }
}
