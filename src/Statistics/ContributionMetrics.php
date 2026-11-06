<?php

declare(strict_types=1);

namespace GitConstellation\Statistics;

use GitConstellation\Data\ContributionCalendar;
use GitConstellation\Support\IntegerRange;
use InvalidArgumentException;

final readonly class ContributionMetrics
{
    private function __construct(
        public int $totalContributions,
        public int $activeDays,
        public int $longestStreak,
    ) {}

    public static function calculate(ContributionCalendar $calendar): self
    {
        $total = $active = $longest = $current = 0;
        foreach ($calendar->days as $day) {
            if ($day['count'] > IntegerRange::MAX_SAFE - $total) {
                throw new InvalidArgumentException('The contribution total exceeds the JavaScript-safe integer range.');
            }
            $total += $day['count'];
            if ($day['count'] > 0) {
                ++$active;
                ++$current;
                $longest = max($longest, $current);
            } else {
                $current = 0;
            }
        }

        return new self($total, $active, $longest);
    }
}
