<?php

declare(strict_types=1);

namespace GitConstellation\Data;

use DateTimeImmutable;
use GitConstellation\Statistics\PrivateContributions;
use GitConstellation\Statistics\VisibleTotals;

final readonly class LiveSnapshot
{
    /**
     * @param list<array{name: string, repositories: int, color: string}> $languages
     * @param list<string> $tokenScopes
     */
    public function __construct(
        public string $login,
        public ContributionCalendar $calendar,
        public int $pullRequests,
        public int $codeReviews,
        public VisibleTotals $visibleTotals,
        public int $restrictedContributions,
        public PrivateContributions $privateContributions,
        public bool $ownAccount,
        public array $languages,
        public int $repositoriesScanned,
        public DateTimeImmutable $generatedAt,
        public int $rateLimitRemaining,
        public array $tokenScopes,
    ) {}
}
