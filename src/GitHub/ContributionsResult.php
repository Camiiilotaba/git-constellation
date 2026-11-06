<?php

declare(strict_types=1);

namespace GitConstellation\GitHub;

use GitConstellation\Data\ContributionCalendar;
use GitConstellation\Statistics\VisibleTotals;

final readonly class ContributionsResult
{
    public function __construct(
        public string $login,
        public string $viewerLogin,
        public ContributionCalendar $calendar,
        public int $calendarTotal,
        public VisibleTotals $visibleTotals,
        public int $restrictedContributions,
        public bool $hasAnyRestrictedContributions,
        public int $rateLimitRemaining,
    ) {}
}
