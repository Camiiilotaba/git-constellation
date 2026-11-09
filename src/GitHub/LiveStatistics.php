<?php

declare(strict_types=1);

namespace GitConstellation\GitHub;

use DateTimeImmutable;
use GitConstellation\Data\ContributionCalendar;
use GitConstellation\Data\LiveSnapshot;
use GitConstellation\Data\Period;
use GitConstellation\Statistics\ContributionMetrics;
use GitConstellation\Statistics\LanguageRanking;
use GitConstellation\Statistics\PrivateContributions;
use GitConstellation\Statistics\VisibleTotals;

final class LiveStatistics
{
    public function __construct(
        private GraphQlClient $client,
        private ContributionsQuery $contributions,
        private RepositoriesQuery $repositories,
    ) {}

    /**
     * @param list<string> $excludedLanguages
     * @param list<array{name: string, color: string|null}>|null $manualLanguages
     */
    public function collect(
        string $login,
        Period $period,
        DateTimeImmutable $generatedAt,
        array $excludedLanguages = LanguageRanking::DEFAULT_EXCLUDED,
        ?array $manualLanguages = null,
    ): LiveSnapshot {
        $contributions = $this->contributions->fetch($login, $period);

        return $this->snapshot([$contributions], $contributions->calendar, 'last-year', $generatedAt, $excludedLanguages, $manualLanguages);
    }

    /**
     * Everything since the first contribution day, up to today (UTC), from one query per calendar year.
     *
     * @param list<string> $excludedLanguages
     * @param list<array{name: string, color: string|null}>|null $manualLanguages
     */
    public function collectAllTime(
        string $login,
        DateTimeImmutable $generatedAt,
        array $excludedLanguages = LanguageRanking::DEFAULT_EXCLUDED,
        ?array $manualLanguages = null,
    ): LiveSnapshot {
        $years = $this->contributions->fetchAllYears($login, $generatedAt);
        $calendar = ContributionCalendar::concatenate(array_map(fn (ContributionsResult $year): ContributionCalendar => $year->calendar, $years))
            ->fromFirstContribution();

        return $this->snapshot($years, $calendar, 'all-time', $generatedAt, $excludedLanguages, $manualLanguages);
    }

    /**
     * @param non-empty-list<ContributionsResult> $parts
     * @param list<string> $excludedLanguages
     * @param list<array{name: string, color: string|null}>|null $manualLanguages
     */
    private function snapshot(
        array $parts,
        ContributionCalendar $calendar,
        string $periodKind,
        DateTimeImmutable $generatedAt,
        array $excludedLanguages,
        ?array $manualLanguages,
    ): LiveSnapshot {
        $scopes = $this->client->lastScopes();

        // Rejected before the repositories query: the scope is not needed and would expose private PR/review activity.
        if (in_array('repo', $scopes, true)) {
            throw new GitHubApiException(
                GitHubErrorKind::Auth,
                'The token has the repo scope, which is not needed and would expose private activity. Create a classic token with only read:user.',
            );
        }

        $login = $parts[0]->login;
        $repositories = $this->repositories->fetch($login);
        $last = $parts[count($parts) - 1];
        $ownAccount = strcasecmp($last->viewerLogin, $last->login) === 0;
        $visibleTotals = array_reduce(
            array_slice($parts, 1),
            fn (VisibleTotals $sum, ContributionsResult $part): VisibleTotals => $sum->plus($part->visibleTotals),
            $parts[0]->visibleTotals,
        );

        return new LiveSnapshot(
            $login,
            $calendar,
            $visibleTotals->pullRequests,
            $visibleTotals->reviews,
            $visibleTotals,
            array_sum(array_map(fn (ContributionsResult $part): int => $part->restrictedContributions, $parts)),
            // Decided per query: each query's five visible totals cover only its own period, so they are compared with that period's sum.
            PrivateContributions::combine(array_map(
                fn (ContributionsResult $part): PrivateContributions => PrivateContributions::determine(
                    ContributionMetrics::calculate($part->calendar)->totalContributions,
                    $part->visibleTotals,
                    $part->restrictedContributions,
                    $part->hasAnyRestrictedContributions,
                    $ownAccount,
                ),
                $parts,
            )),
            $ownAccount,
            $manualLanguages === null
                ? LanguageRanking::calculate($repositories->repositories, $excludedLanguages)
                : LanguageRanking::manual($manualLanguages, $repositories->repositories),
            $repositories->scanned,
            $generatedAt,
            $last->rateLimitRemaining,
            $scopes,
            $manualLanguages === null ? 'ranking' : 'manual',
            $periodKind,
        );
    }
}
