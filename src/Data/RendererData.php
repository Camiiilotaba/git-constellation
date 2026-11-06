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
            'demo',
            $generatedAt,
            $config->username,
            $config,
            $fixture->calendar,
            $fixture->pullRequests,
            $fixture->codeReviews,
            $fixture->languages,
            'excluded',
            null,
        );
    }

    /** @return array<string, mixed> Exact schemaVersion 2 handoff to the TypeScript renderer. */
    public static function fromLive(Configuration $config, LiveSnapshot $snapshot): array
    {
        return self::assemble(
            'live',
            $snapshot->generatedAt,
            $snapshot->login,
            $config,
            $snapshot->calendar,
            $snapshot->pullRequests,
            $snapshot->codeReviews,
            $snapshot->languages,
            $snapshot->privateContributions->value,
            [
                'provider' => 'github',
                'login' => $snapshot->login,
                'restrictedContributions' => $snapshot->restrictedContributions,
                'visibleTotals' => $snapshot->visibleTotals->toArray(),
                'repositoriesScanned' => $snapshot->repositoriesScanned,
            ],
        );
    }

    /**
     * @param list<array{name: string, repositories: int, color: string}> $languages
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
            'privateContributions' => $privateContributions,
            'source' => $source,
        ];
    }
}
