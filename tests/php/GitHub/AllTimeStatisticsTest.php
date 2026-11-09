<?php

declare(strict_types=1);

namespace GitConstellation\Tests\GitHub;

use DateTimeImmutable;
use GitConstellation\GitHub\ContributionsQuery;
use GitConstellation\GitHub\GitHubApiException;
use GitConstellation\GitHub\GitHubErrorKind;
use GitConstellation\GitHub\GraphQlClient;
use GitConstellation\GitHub\LiveStatistics;
use GitConstellation\GitHub\RepositoriesQuery;
use GitConstellation\Statistics\ContributionMetrics;
use GitConstellation\Statistics\PrivateContributions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AllTimeStatisticsTest extends TestCase
{
    private const string NOW = '2026-11-09T12:00:00Z';

    /** @var list<array<string, mixed>> */
    private array $requests = [];

    /** @var array<int, array<string, mixed>> per-year overrides for the contributionsCollection */
    private array $overrides = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @param list<int> $years */
    private function statistics(array $years, string $viewer = 'someone-else', ?int $failingYear = null): LiveStatistics
    {
        $this->requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($years, $viewer, $failingYear): MockResponse {
            $payload = json_decode((string) $options['body'], true);
            $this->requests[] = $payload;
            $headers = ['response_headers' => ['x-oauth-scopes: read:user']];
            if ($payload['query'] === ContributionsQuery::YEARS_QUERY) {
                return new MockResponse(json_encode(['data' => ['user' => ['login' => 'octo-sample', 'contributionsCollection' => ['contributionYears' => $years]]]]), $headers);
            }
            if ($payload['query'] === RepositoriesQuery::QUERY) {
                return new MockResponse(json_encode(['data' => ['user' => ['repositories' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'nodes' => []]]]]), $headers);
            }
            $from = substr($payload['variables']['from'], 0, 10);
            $to = substr($payload['variables']['to'], 0, 10);
            if ((int) substr($from, 0, 4) === $failingYear) {
                return new MockResponse('{"message":"Server Error"}', ['http_code' => 502]);
            }

            return new MockResponse($this->yearBody($from, $to, $viewer), $headers);
        });
        $client = new GraphQlClient($http, 'tok_123', 0, fn (int $seconds): int => 0);

        return new LiveStatistics($client, new ContributionsQuery($client), new RepositoriesQuery($client));
    }

    private function yearBody(string $from, string $to, string $viewer): string
    {
        $days = [];
        $total = 0;
        for ($date = new DateTimeImmutable($from); $date->format('Y-m-d') <= $to; $date = $date->modify('+1 day')) {
            $count = $this->counts[$date->format('Y-m-d')] ?? 0;
            $days[] = ['date' => $date->format('Y-m-d'), 'contributionCount' => $count];
            $total += $count;
        }
        $collection = array_replace([
            'contributionCalendar' => ['totalContributions' => $total, 'weeks' => [['contributionDays' => $days]]],
            // Commits make the five visible totals equal the calendar total, so the year is exact by default.
            'totalCommitContributions' => $total,
            'totalIssueContributions' => 0,
            'totalPullRequestContributions' => 0,
            'totalPullRequestReviewContributions' => 0,
            'totalRepositoryContributions' => 0,
            'restrictedContributionsCount' => 0,
            'hasAnyRestrictedContributions' => false,
        ], $this->overrides[(int) substr($from, 0, 4)] ?? []);

        return json_encode(['data' => [
            'rateLimit' => ['remaining' => 5000 - count($this->requests), 'resetAt' => '2026-11-09T13:00:00Z'],
            'viewer' => ['login' => $viewer],
            'user' => ['login' => 'octo-sample', 'contributionsCollection' => $collection],
        ]], JSON_THROW_ON_ERROR);
    }

    /** @return list<array{string, string}> */
    private function queriedRanges(): array
    {
        return array_values(array_map(
            fn (array $payload): array => [$payload['variables']['from'], $payload['variables']['to']],
            array_filter($this->requests, fn (array $payload): bool => $payload['query'] === ContributionsQuery::QUERY),
        ));
    }

    public function testQueriesEveryYearFromTheFirstContributionYearToToday(): void
    {
        $this->counts = ['2024-03-05' => 1];

        $this->statistics([2026, 2024])->collectAllTime('octo-sample', new DateTimeImmutable(self::NOW));

        self::assertSame(ContributionsQuery::YEARS_QUERY, $this->requests[0]['query']);
        self::assertSame(['login' => 'octo-sample'], $this->requests[0]['variables']);
        // 2025 has no contributions but is still queried, so the joined calendar is real data without gaps.
        self::assertSame([
            ['2024-01-01T00:00:00Z', '2024-12-31T23:59:59Z'],
            ['2025-01-01T00:00:00Z', '2025-12-31T23:59:59Z'],
            ['2026-01-01T00:00:00Z', '2026-11-09T23:59:59Z'],
        ], $this->queriedRanges());
    }

    public function testAggregatesYearsFromTheFirstContributionDay(): void
    {
        // A streak of 4 crosses the year boundary; the leading empty days of 2023 are not part of the period.
        $this->counts = ['2023-10-23' => 2, '2023-12-30' => 1, '2023-12-31' => 3, '2024-01-01' => 4, '2024-01-02' => 1, '2026-11-09' => 5];
        $this->overrides = [
            2023 => ['totalCommitContributions' => 1, 'totalPullRequestContributions' => 3, 'totalPullRequestReviewContributions' => 2],
            2024 => ['totalCommitContributions' => 0, 'totalPullRequestContributions' => 4, 'totalPullRequestReviewContributions' => 1],
            2026 => ['totalCommitContributions' => 5, 'restrictedContributionsCount' => 2],
        ];

        $snapshot = $this->statistics([2026, 2025, 2024, 2023])->collectAllTime('octo-sample', new DateTimeImmutable(self::NOW));
        $metrics = ContributionMetrics::calculate($snapshot->calendar);

        self::assertSame('all-time', $snapshot->period);
        self::assertSame('2023-10-23', $snapshot->calendar->start);
        self::assertSame('2026-11-09', $snapshot->calendar->end);
        self::assertCount(1114, $snapshot->calendar->days);
        self::assertSame(16, $metrics->totalContributions);
        self::assertSame(6, $metrics->activeDays);
        self::assertSame(4, $metrics->longestStreak);
        self::assertSame(7, $snapshot->pullRequests);
        self::assertSame(3, $snapshot->codeReviews);
        self::assertSame(6, $snapshot->visibleTotals->commits);
        self::assertSame(2, $snapshot->restrictedContributions);
        self::assertSame(PrivateContributions::Included, $snapshot->privateContributions);
        self::assertSame('octo-sample', $snapshot->login);
        self::assertSame(['read:user'], $snapshot->tokenScopes);
        self::assertSame(5000 - 5, $snapshot->rateLimitRemaining);
    }

    public function testEveryExactYearWithoutSignalIsExcluded(): void
    {
        $this->counts = ['2025-06-01' => 3, '2026-02-01' => 2];

        $snapshot = $this->statistics([2025, 2026])->collectAllTime('octo-sample', new DateTimeImmutable(self::NOW));

        self::assertFalse($snapshot->ownAccount);
        self::assertSame(PrivateContributions::Excluded, $snapshot->privateContributions);
    }

    public function testOneInexactYearMakesTheStateUnknownEvenWhenTheSumsBalance(): void
    {
        // 2025 is one above its visible totals and 2026 one below: the aggregate balances, but neither year is exact.
        $this->counts = ['2025-06-01' => 3, '2026-02-01' => 2];
        $this->overrides = [2025 => ['totalCommitContributions' => 2], 2026 => ['totalCommitContributions' => 3]];

        $snapshot = $this->statistics([2025, 2026])->collectAllTime('octo-sample', new DateTimeImmutable(self::NOW));

        self::assertSame(PrivateContributions::Unknown, $snapshot->privateContributions);
    }

    public function testAnExplicitSignalInOneYearIsIncluded(): void
    {
        $this->counts = ['2025-06-01' => 3, '2026-02-01' => 2];
        $this->overrides = [2025 => ['hasAnyRestrictedContributions' => true]];

        $snapshot = $this->statistics([2025, 2026])->collectAllTime('octo-sample', new DateTimeImmutable(self::NOW));

        self::assertSame(PrivateContributions::Included, $snapshot->privateContributions);
    }

    public function testOwnAccountIsIncluded(): void
    {
        $this->counts = ['2025-06-01' => 3];

        $snapshot = $this->statistics([2025], 'Octo-Sample')->collectAllTime('octo-sample', new DateTimeImmutable(self::NOW));

        self::assertTrue($snapshot->ownAccount);
        self::assertSame(PrivateContributions::Included, $snapshot->privateContributions);
    }

    public function testNoContributionYearsGivesAZeroActivitySnapshotOfToday(): void
    {
        $snapshot = $this->statistics([])->collectAllTime('octo-sample', new DateTimeImmutable(self::NOW));

        self::assertSame([['2026-01-01T00:00:00Z', '2026-11-09T23:59:59Z']], $this->queriedRanges());
        self::assertSame([['date' => '2026-11-09', 'count' => 0]], $snapshot->calendar->days);
        self::assertSame(0, $snapshot->pullRequests);
        self::assertSame(PrivateContributions::Excluded, $snapshot->privateContributions);
    }

    public function testAFailedYearFailsTheWholeRunBeforeTheRepositoriesQuery(): void
    {
        $this->counts = ['2024-03-05' => 1];
        try {
            $this->statistics([2024, 2025, 2026], 'someone-else', 2025)->collectAllTime('octo-sample', new DateTimeImmutable(self::NOW));
            self::fail('Expected GitHubApiException.');
        } catch (GitHubApiException $exception) {
            self::assertSame(GitHubErrorKind::Transport, $exception->kind);
        }

        self::assertSame(3, count($this->requests));
        self::assertSame([], array_filter($this->requests, fn (array $payload): bool => $payload['query'] === RepositoriesQuery::QUERY));
    }

    public function testUnknownUserIsNotFoundFromTheYearsQuery(): void
    {
        $http = new MockHttpClient(new MockResponse('{"data":{"user":null}}'));
        $client = new GraphQlClient($http, 'tok_123', 0, fn (int $seconds): int => 0);
        $statistics = new LiveStatistics($client, new ContributionsQuery($client), new RepositoriesQuery($client));

        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('ghost');
        $statistics->collectAllTime('ghost', new DateTimeImmutable(self::NOW));
    }

    public function testInvalidContributionYearsAreRejected(): void
    {
        $this->expectException(GitHubApiException::class);
        $this->expectExceptionMessage('contributionYears');
        $this->statistics(['2024'])->collectAllTime('octo-sample', new DateTimeImmutable(self::NOW));
    }
}
