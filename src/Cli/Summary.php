<?php

declare(strict_types=1);

namespace GitConstellation\Cli;

use DateTimeZone;
use GitConstellation\Data\LiveSnapshot;
use GitConstellation\Data\Period;
use GitConstellation\Statistics\ContributionMetrics;

final class Summary
{
    public static function format(LiveSnapshot $snapshot, Period $period): string
    {
        $metrics = ContributionMetrics::calculate($snapshot->calendar);
        $manual = $snapshot->languagesSource === 'manual';
        $languages = implode(', ', array_map(
            fn (array $language): string => $manual ? $language['name'] : "{$language['name']} ({$language['repositories']})",
            $snapshot->languages,
        ));
        $ownAccount = $snapshot->ownAccount ? 'yes' : 'no';
        $scopes = implode(', ', $snapshot->tokenScopes);

        return implode("\n", [
            "LIVE — GitHub statistics for {$snapshot->login}",
            "Period (UTC): {$period->start} .. {$period->end}",
            'Generated at: ' . $snapshot->generatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            "Contributions (calendar): {$metrics->totalContributions}",
            "Private contributions: {$snapshot->privateContributions->value} (restricted count: {$snapshot->restrictedContributions}, own account: {$ownAccount})",
            "Pull Requests (visible to token): {$snapshot->pullRequests}",
            "Code Reviews (visible to token): {$snapshot->codeReviews}",
            "Active Days: {$metrics->activeDays}",
            "Longest Streak: {$metrics->longestStreak}",
            "Repositories scanned (public, non-fork): {$snapshot->repositoriesScanned}",
            ($manual ? 'Top Languages (selected): ' : 'Top Languages: ') . ($languages === '' ? 'none' : $languages),
            'Token scopes: ' . ($scopes === '' ? '(not reported)' : $scopes),
            "Rate limit remaining: {$snapshot->rateLimitRemaining}",
        ]) . "\n";
    }
}
