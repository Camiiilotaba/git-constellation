<?php

declare(strict_types=1);

namespace GitConstellation\Tests\GitHub;

use DateTimeImmutable;
use GitConstellation\Data\Period;
use GitConstellation\GitHub\ContributionsQuery;
use GitConstellation\GitHub\GitHubApiException;
use GitConstellation\GitHub\GitHubErrorKind;
use GitConstellation\GitHub\GraphQlClient;
use GitConstellation\GitHub\LiveStatistics;
use GitConstellation\GitHub\RepositoriesQuery;
use GitConstellation\Statistics\PrivateContributions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LiveStatisticsTest extends TestCase
{
    private int $requests = 0;

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . "/../fixtures/github/{$name}.json");
    }

    /** @param list<MockResponse> $responses */
    private function statistics(array $responses): LiveStatistics
    {
        $this->requests = 0;
        $http = new MockHttpClient(function () use ($responses): MockResponse {
            return $responses[min($this->requests++, count($responses) - 1)];
        });
        $client = new GraphQlClient($http, 'tok_123', 0, fn (int $seconds): int => 0);

        return new LiveStatistics($client, new ContributionsQuery($client), new RepositoriesQuery($client));
    }

    private function response(string $body, string $scopes = 'read:user', int $status = 200): MockResponse
    {
        $headers = $scopes === '' ? [] : ['x-oauth-scopes: ' . $scopes];

        return new MockResponse($body, ['http_code' => $status, 'response_headers' => $headers]);
    }

    /** @return list<MockResponse> */
    private function repositoryPages(): array
    {
        return array_map(
            fn (string $name): MockResponse => $this->response($this->fixture($name)),
            ['repositories-page-1', 'repositories-page-2', 'repositories-page-3'],
        );
    }

    private function period(): Period
    {
        return Period::trailingYear(new DateTimeImmutable('2026-11-06T00:00:00Z'));
    }

    private function failure(LiveStatistics $statistics): GitHubApiException
    {
        try {
            $statistics->collect('octo-sample', $this->period(), new DateTimeImmutable('2026-11-06T12:00:00Z'));
        } catch (GitHubApiException $exception) {
            return $exception;
        }
        self::fail('Expected GitHubApiException.');
    }

    public function testCollectsSnapshot(): void
    {
        $generatedAt = new DateTimeImmutable('2026-11-06T12:00:00Z');
        $statistics = $this->statistics([$this->response($this->fixture('contributions-ok')), ...$this->repositoryPages()]);

        $snapshot = $statistics->collect('octo-sample', $this->period(), $generatedAt);

        self::assertSame('octo-sample', $snapshot->login);
        self::assertCount(365, $snapshot->calendar->days);
        self::assertSame(58, $snapshot->pullRequests);
        self::assertSame(33, $snapshot->codeReviews);
        self::assertSame(612, $snapshot->visibleTotals->commits);
        self::assertSame(7, $snapshot->restrictedContributions);
        self::assertSame(PrivateContributions::Included, $snapshot->privateContributions);
        self::assertTrue($snapshot->ownAccount);
        self::assertSame(8, $snapshot->repositoriesScanned);
        self::assertLessThanOrEqual(5, count($snapshot->languages));
        self::assertNotSame([], $snapshot->languages);
        $counts = array_column($snapshot->languages, 'repositories');
        $sorted = $counts;
        rsort($sorted);
        self::assertSame($sorted, $counts);
        self::assertSame(4990, $snapshot->rateLimitRemaining);
        self::assertSame(['read:user'], $snapshot->tokenScopes);
        self::assertSame($generatedAt, $snapshot->generatedAt);
        self::assertSame(4, $this->requests);
    }

    public function testZeroActivityAndNoRepositories(): void
    {
        $days = [];
        for ($date = new DateTimeImmutable('2025-11-07'); $date <= new DateTimeImmutable('2026-11-06'); $date = $date->modify('+1 day')) {
            $days[] = ['date' => $date->format('Y-m-d'), 'contributionCount' => 0];
        }
        $contributions = json_encode(['data' => [
            'rateLimit' => ['remaining' => 4000, 'resetAt' => '2026-11-06T13:00:00Z'],
            'viewer' => ['login' => 'someone-else'],
            'user' => ['login' => 'octo-sample', 'contributionsCollection' => [
                'contributionCalendar' => ['totalContributions' => 0, 'weeks' => [['contributionDays' => $days]]],
                'totalCommitContributions' => 0,
                'totalIssueContributions' => 0,
                'totalPullRequestContributions' => 0,
                'totalPullRequestReviewContributions' => 0,
                'totalRepositoryContributions' => 0,
                'restrictedContributionsCount' => 0,
                'hasAnyRestrictedContributions' => false,
            ]],
        ]], JSON_THROW_ON_ERROR);
        $repositories = json_encode(['data' => ['user' => ['repositories' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [],
        ]]]], JSON_THROW_ON_ERROR);

        $snapshot = $this->statistics([$this->response($contributions), $this->response($repositories)])
            ->collect('octo-sample', $this->period(), new DateTimeImmutable('2026-11-06T12:00:00Z'));

        self::assertSame(PrivateContributions::Excluded, $snapshot->privateContributions);
        self::assertSame([], $snapshot->languages);
        self::assertSame(0, $snapshot->repositoriesScanned);
    }

    public function testPrivateStateUsesTheInPeriodTotalNotTheTotalWithPaddedDays(): void
    {
        // The padded day 2025-11-06 (5 contributions) lies outside the period, so GitHub's total of 10
        // covers more than the five visible totals, which sum to the in-period 5.
        $days = [['date' => '2025-11-06', 'contributionCount' => 5]];
        for ($date = new DateTimeImmutable('2025-11-07'); $date <= new DateTimeImmutable('2026-11-06'); $date = $date->modify('+1 day')) {
            $days[] = ['date' => $date->format('Y-m-d'), 'contributionCount' => $date->format('Y-m-d') === '2026-11-06' ? 5 : 0];
        }
        $contributions = json_encode(['data' => [
            'rateLimit' => ['remaining' => 4000, 'resetAt' => '2026-11-06T13:00:00Z'],
            'viewer' => ['login' => 'someone-else'],
            'user' => ['login' => 'octo-sample', 'contributionsCollection' => [
                'contributionCalendar' => ['totalContributions' => 10, 'weeks' => [['contributionDays' => $days]]],
                'totalCommitContributions' => 5,
                'totalIssueContributions' => 0,
                'totalPullRequestContributions' => 0,
                'totalPullRequestReviewContributions' => 0,
                'totalRepositoryContributions' => 0,
                'restrictedContributionsCount' => 0,
                'hasAnyRestrictedContributions' => false,
            ]],
        ]], JSON_THROW_ON_ERROR);
        $repositories = json_encode(['data' => ['user' => ['repositories' => [
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            'nodes' => [],
        ]]]], JSON_THROW_ON_ERROR);

        $snapshot = $this->statistics([$this->response($contributions), $this->response($repositories)])
            ->collect('octo-sample', $this->period(), new DateTimeImmutable('2026-11-06T12:00:00Z'));

        self::assertSame(PrivateContributions::Excluded, $snapshot->privateContributions);
    }

    public function testOwnAccountIsIncluded(): void
    {
        $body = $this->withViewer($this->fixture('contributions-no-restricted'), 'octo-sample');

        $snapshot = $this->statistics([$this->response($body), ...$this->repositoryPages()])
            ->collect('octo-sample', $this->period(), new DateTimeImmutable('2026-11-06T12:00:00Z'));

        self::assertSame(0, $snapshot->restrictedContributions);
        self::assertTrue($snapshot->ownAccount);
        self::assertSame(PrivateContributions::Included, $snapshot->privateContributions);
    }

    public function testOtherAccountWithoutRestrictedIsExcluded(): void
    {
        $snapshot = $this->statistics([$this->response($this->fixture('contributions-no-restricted')), ...$this->repositoryPages()])
            ->collect('octo-sample', $this->period(), new DateTimeImmutable('2026-11-06T12:00:00Z'));

        self::assertFalse($snapshot->ownAccount);
        self::assertSame(PrivateContributions::Excluded, $snapshot->privateContributions);
    }

    private function withViewer(string $body, string $viewer): string
    {
        return str_replace('"login": "someone-else"', '"login": "' . $viewer . '"', $body);
    }

    public function testContributionsFailureAbortsBeforeRepositories(): void
    {
        $exception = $this->failure($this->statistics([$this->response('{}', 'read:user', 401)]));

        self::assertSame(GitHubErrorKind::Auth, $exception->kind);
        self::assertSame(1, $this->requests);
    }

    public function testRepoScopeIsRejected(): void
    {
        $exception = $this->failure($this->statistics([
            $this->response($this->fixture('contributions-ok'), 'read:user, repo'),
            ...$this->repositoryPages(),
        ]));

        self::assertSame(GitHubErrorKind::Auth, $exception->kind);
        self::assertStringContainsString('read:user', $exception->getMessage());
        self::assertSame(1, $this->requests);
    }

    public function testPublicRepoScopeIsNotRejected(): void
    {
        $snapshot = $this->statistics([
            $this->response($this->fixture('contributions-ok'), 'public_repo, read:user'),
            ...$this->repositoryPages(),
        ])->collect('octo-sample', $this->period(), new DateTimeImmutable('2026-11-06T12:00:00Z'));

        self::assertSame(['public_repo', 'read:user'], $snapshot->tokenScopes);
    }

    public function testMissingScopesHeaderIsAllowed(): void
    {
        $snapshot = $this->statistics([
            $this->response($this->fixture('contributions-ok'), ''),
            ...$this->repositoryPages(),
        ])->collect('octo-sample', $this->period(), new DateTimeImmutable('2026-11-06T12:00:00Z'));

        self::assertSame([], $snapshot->tokenScopes);
    }
}
