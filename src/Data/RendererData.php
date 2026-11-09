<?php

declare(strict_types=1);

namespace GitConstellation\Data;

use DateTimeImmutable;
use DateTimeZone;
use GitConstellation\Config\Configuration;
use GitConstellation\Statistics\ContributionMetrics;

final class RendererData
{
    /** @return array<string, mixed> Exact schemaVersion 2 handoff to the TypeScript renderer. */
    public static function fromDemo(Configuration $config, DemoFixture $fixture, DateTimeImmutable $generatedAt): array
    {
        return self::assemble(
            mode: 'demo',
            generatedAt: $generatedAt,
            username: $config->username,
            config: $config,
            calendar: $fixture->calendar,
            pullRequests: $fixture->pullRequests,
            codeReviews: $fixture->codeReviews,
            languages: $fixture->languages,
            languagesSource: $fixture->languagesSource,
            privateContributions: 'excluded',
            source: null,
        );
    }

    /** @return array<string, mixed> Exact schemaVersion 2 handoff to the TypeScript renderer. */
    public static function fromLive(Configuration $config, LiveSnapshot $snapshot): array
    {
        return self::assemble(
            mode: 'live',
            generatedAt: $snapshot->generatedAt,
            username: $snapshot->login,
            config: $config,
            calendar: $snapshot->calendar,
            pullRequests: $snapshot->pullRequests,
            codeReviews: $snapshot->codeReviews,
            languages: $snapshot->languages,
            languagesSource: $snapshot->languagesSource,
            privateContributions: $snapshot->privateContributions->value,
            source: [
                'provider' => 'github',
                'login' => $snapshot->login,
                'restrictedContributions' => $snapshot->restrictedContributions,
                'ownAccount' => $snapshot->ownAccount,
                'visibleTotals' => $snapshot->visibleTotals->toArray(),
                'repositoriesScanned' => $snapshot->repositoriesScanned,
            ],
        );
    }

    /**
     * @param list<array{name: string, repositories: int|null, color: string}> $languages
     * @param array<string, mixed>|null $source
     * @return array<string, mixed>
     */
    private static function assemble(
        string $mode,
        DateTimeImmutable $generatedAt,
        string $username,
        Configuration $config,
        ContributionCalendar $calendar,
        int $pullRequests,
        int $codeReviews,
        array $languages,
        string $languagesSource,
        string $privateContributions,
        ?array $source,
    ): array {
        $metrics = ContributionMetrics::calculate($calendar);

        return [
            'schemaVersion' => 2,
            'mode' => $mode,
            'generatedAt' => $generatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'profile' => [
                'username' => $username,
                'name' => $config->name,
                'role' => $config->role,
                'stack' => $config->stack,
            ],
            'theme' => ['cyan' => $config->cyan, 'green' => $config->green],
            'period' => ['start' => $calendar->start, 'end' => $calendar->end],
            'days' => $calendar->days,
            'metrics' => [
                'totalContributions' => $metrics->totalContributions,
                'activeDays' => $metrics->activeDays,
                'longestStreak' => $metrics->longestStreak,
                'pullRequests' => $pullRequests,
                'codeReviews' => $codeReviews,
            ],
            'languages' => $languages,
            'languagesSource' => $languagesSource,
            'privateContributions' => $privateContributions,
            'source' => $source,
        ];
    }
}
