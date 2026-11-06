<?php

declare(strict_types=1);

namespace GitConstellation\GitHub;

use DateTimeImmutable;
use GitConstellation\Data\LiveSnapshot;
use GitConstellation\Data\Period;
use GitConstellation\Statistics\ContributionMetrics;
use GitConstellation\Statistics\LanguageRanking;
use GitConstellation\Statistics\PrivateContributions;

final class LiveStatistics
{
    public function __construct(
        private GraphQlClient $client,
        private ContributionsQuery $contributions,
        private RepositoriesQuery $repositories,
    ) {}

    public function collect(string $login, Period $period, DateTimeImmutable $generatedAt): LiveSnapshot
    {
        $contributions = $this->contributions->fetch($login, $period);
        $scopes = $this->client->lastScopes();

        // Rejected before the repositories query: the scope is not needed and would expose private PR/review activity.
        if (in_array('repo', $scopes, true)) {
            throw new GitHubApiException(
                GitHubErrorKind::Auth,
                'The token has the repo scope, which is not needed and would expose private activity. Create a classic token with only read:user.',
            );
        }

        $repositories = $this->repositories->fetch($login);
        $ownAccount = strcasecmp($contributions->viewerLogin, $contributions->login) === 0;

        return new LiveSnapshot(
            $contributions->login,
            $contributions->calendar,
            $contributions->visibleTotals->pullRequests,
            $contributions->visibleTotals->reviews,
            $contributions->visibleTotals,
            $contributions->restrictedContributions,
            // The five visible totals cover only the period, so compare them with the in-period sum, not GitHub's padded total.
            PrivateContributions::determine(
                ContributionMetrics::calculate($contributions->calendar)->totalContributions,
                $contributions->visibleTotals,
                $contributions->restrictedContributions,
                $contributions->hasAnyRestrictedContributions,
                $ownAccount,
            ),
            $ownAccount,
            LanguageRanking::calculate($repositories->repositories),
            $repositories->scanned,
            $generatedAt,
            $contributions->rateLimitRemaining,
            $scopes,
        );
    }
}
