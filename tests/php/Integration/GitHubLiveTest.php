<?php

declare(strict_types=1);

namespace GitConstellation\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use GitConstellation\Data\Period;
use GitConstellation\GitHub\ContributionsQuery;
use GitConstellation\GitHub\GraphQlClient;
use GitConstellation\GitHub\LiveStatistics;
use GitConstellation\GitHub\RepositoriesQuery;
use GitConstellation\Statistics\PrivateContributions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;

/** Calls the real GitHub API. Skipped unless GITHUB_TOKEN and GIT_CONSTELLATION_LOGIN are set. */
#[Group('integration')]
final class GitHubLiveTest extends TestCase
{
    public function testCollectAgainstRealApi(): void
    {
        $token = getenv('GITHUB_TOKEN');
        $login = getenv('GIT_CONSTELLATION_LOGIN');
        if (!is_string($token) || $token === '' || !is_string($login) || $login === '') {
            self::markTestSkipped('Set GITHUB_TOKEN and GIT_CONSTELLATION_LOGIN to run the real-API integration test.');
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $period = Period::trailingYear($now);
        $client = new GraphQlClient(HttpClient::create(), $token);
        $statistics = new LiveStatistics($client, new ContributionsQuery($client), new RepositoriesQuery($client));

        $snapshot = $statistics->collect($login, $period, $now);

        self::assertContains(count($snapshot->calendar->days), [365, 366]);
        self::assertSame($period->start, $snapshot->calendar->start);
        self::assertSame($period->end, $snapshot->calendar->end);
        self::assertGreaterThan(0, $snapshot->rateLimitRemaining);
        self::assertContains($snapshot->privateContributions, PrivateContributions::cases());
    }
}
