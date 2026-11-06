<?php

declare(strict_types=1);

namespace GitConstellation\Data;

use GitConstellation\Config\Configuration;
use DateTimeImmutable;
use DateTimeZone;
use GitConstellation\Statistics\ContributionMetrics;

final class RendererData
{
    /** @return array<string, mixed> Exact schemaVersion 2 handoff to the TypeScript renderer. */
    public static function fromDemo(Configuration $config, DemoFixture $fixture, DateTimeImmutable $generatedAt): array
    {
        $metrics = ContributionMetrics::calculate($fixture->calendar);

        return [
            'schemaVersion' => 2,
            'mode' => 'demo',
            'generatedAt' => $generatedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'profile' => [
                'username' => $config->username,
                'name' => $config->name,
                'role' => $config->role,
                'stack' => $config->stack,
            ],
            'theme' => ['cyan' => $config->cyan, 'green' => $config->green],
            'period' => ['start' => $fixture->calendar->start, 'end' => $fixture->calendar->end],
            'days' => $fixture->calendar->days,
            'metrics' => [
                'totalContributions' => $metrics->totalContributions,
                'activeDays' => $metrics->activeDays,
                'longestStreak' => $metrics->longestStreak,
                'pullRequests' => $fixture->pullRequests,
                'codeReviews' => $fixture->codeReviews,
            ],
            'languages' => $fixture->languages,
            'privateContributions' => 'excluded',
            'source' => null,
        ];
    }
}
