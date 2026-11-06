<?php

declare(strict_types=1);

namespace GitConstellation\Tests\Statistics;

use GitConstellation\Statistics\PrivateContributions;
use GitConstellation\Statistics\VisibleTotals;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrivateContributionsTest extends TestCase
{
    /** @param array{int, int, int, int, int} $visible */
    #[DataProvider('cases')]
    public function testDetermine(int $calendarTotal, array $visible, int $restricted, bool $hasAny, PrivateContributions $expected): void
    {
        self::assertSame($expected, PrivateContributions::determine($calendarTotal, new VisibleTotals(...$visible), $restricted, $hasAny));
    }

    public static function cases(): iterable
    {
        yield 'restricted count' => [120, [50, 10, 20, 15, 5], 20, true, PrivateContributions::Included];
        yield 'flag only' => [100, [50, 10, 20, 15, 5], 0, true, PrivateContributions::Included];
        yield 'arithmetic gap only' => [110, [50, 10, 20, 15, 5], 0, false, PrivateContributions::Unknown];
        yield 'exact match' => [100, [50, 10, 20, 15, 5], 0, false, PrivateContributions::Excluded];
        yield 'all zero' => [0, [0, 0, 0, 0, 0], 0, false, PrivateContributions::Excluded];
        yield 'calendar below visible' => [90, [50, 10, 20, 15, 5], 0, false, PrivateContributions::Unknown];
    }

    public function testNegativeInputRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PrivateContributions::determine(-1, new VisibleTotals(0, 0, 0, 0, 0), 0, false);
    }

    public function testVisibleTotalsSumAndArray(): void
    {
        $totals = new VisibleTotals(50, 10, 20, 15, 5);
        self::assertSame(100, $totals->sum());
        self::assertSame(['commits' => 50, 'issues' => 10, 'pullRequests' => 20, 'reviews' => 15, 'repositories' => 5], $totals->toArray());
    }
}
