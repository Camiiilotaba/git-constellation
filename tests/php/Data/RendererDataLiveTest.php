<?php

declare(strict_types=1);

namespace GitConstellation\Tests\Data;

use DateTimeImmutable;
use GitConstellation\Config\Configuration;
use GitConstellation\Data\ContributionCalendar;
use GitConstellation\Data\DemoFixture;
use GitConstellation\Data\LiveSnapshot;
use GitConstellation\Data\RendererData;
use GitConstellation\Statistics\ContributionMetrics;
use GitConstellation\Statistics\PrivateContributions;
use GitConstellation\Statistics\VisibleTotals;
use GitConstellation\Support\JsonFile;
use PHPUnit\Framework\TestCase;

final class RendererDataLiveTest extends TestCase
{
    private const array KEYS = ['schemaVersion', 'mode', 'generatedAt', 'profile', 'theme', 'period', 'days', 'metrics', 'languages', 'languagesSource', 'privateContributions', 'source'];

    private function config(): Configuration
    {
        return Configuration::fromArray(JsonFile::read(dirname(__DIR__, 3) . '/config/demo.json'));
    }

    private function snapshot(PrivateContributions $state = PrivateContributions::Included): LiveSnapshot
    {
        $calendar = ContributionCalendar::fromArray(
            ['start' => '2026-11-01', 'end' => '2026-11-04'],
            [
                ['date' => '2026-11-01', 'count' => 2],
                ['date' => '2026-11-02', 'count' => 0],
                ['date' => '2026-11-03', 'count' => 3],
                ['date' => '2026-11-04', 'count' => 1],
            ],
        );

        return new LiveSnapshot(
            'Octo-Sample',
            $calendar,
            58,
            33,
            new VisibleTotals(612, 14, 58, 33, 4),
            7,
            $state,
            true,
            [['name' => 'PHP', 'repositories' => 5, 'color' => '#4F5D95']],
            8,
            new DateTimeImmutable('2026-11-06T14:00:00+02:00'),
            4990,
            ['read:user'],
        );
    }

    public function testFromLiveProducesVersionTwoContract(): void
    {
        $snapshot = $this->snapshot();
        $data = RendererData::fromLive($this->config(), $snapshot);
        $metrics = ContributionMetrics::calculate($snapshot->calendar);

        self::assertSame(self::KEYS, array_keys($data));
        self::assertSame(2, $data['schemaVersion']);
        self::assertSame('live', $data['mode']);
        self::assertSame('2026-11-06T12:00:00Z', $data['generatedAt']);
        self::assertSame('Octo-Sample', $data['profile']['username']);
        self::assertSame(['start' => '2026-11-01', 'end' => '2026-11-04'], $data['period']);
        self::assertSame($snapshot->calendar->days, $data['days']);
        self::assertSame([
            'totalContributions' => $metrics->totalContributions,
            'activeDays' => $metrics->activeDays,
            'longestStreak' => $metrics->longestStreak,
            'pullRequests' => 58,
            'codeReviews' => 33,
        ], $data['metrics']);
        self::assertSame($snapshot->languages, $data['languages']);
        self::assertSame('ranking', $data['languagesSource']);
        self::assertSame('included', $data['privateContributions']);
        self::assertSame([
            'provider' => 'github',
            'login' => 'Octo-Sample',
            'restrictedContributions' => 7,
            'ownAccount' => true,
            'visibleTotals' => ['commits' => 612, 'issues' => 14, 'pullRequests' => 58, 'reviews' => 33, 'repositories' => 4],
            'repositoriesScanned' => 8,
        ], $data['source']);
    }

    public function testFromLiveStateValuesSerializeAsStrings(): void
    {
        self::assertSame('excluded', RendererData::fromLive($this->config(), $this->snapshot(PrivateContributions::Excluded))['privateContributions']);
        self::assertSame('unknown', RendererData::fromLive($this->config(), $this->snapshot(PrivateContributions::Unknown))['privateContributions']);
    }

    public function testDemoAndLiveShareKeyOrder(): void
    {
        $root = dirname(__DIR__, 3);
        $demo = RendererData::fromDemo(
            $this->config(),
            DemoFixture::fromArray(JsonFile::read($root . '/fixtures/demo.json')),
            new DateTimeImmutable('2026-11-06T12:00:00Z'),
        );

        self::assertSame(array_keys($demo), array_keys(RendererData::fromLive($this->config(), $this->snapshot())));
    }
}
