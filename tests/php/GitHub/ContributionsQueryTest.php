<?php

declare(strict_types=1);

namespace GitConstellation\Tests\GitHub;

use DateTimeImmutable;
use GitConstellation\Data\Period;
use GitConstellation\GitHub\ContributionsQuery;
use GitConstellation\GitHub\GitHubApiException;
use GitConstellation\GitHub\GitHubErrorKind;
use GitConstellation\GitHub\GraphQlClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixture generator: the 365 in-period days use count = (dayIndex * 7) % 5 with dayIndex 0 = 2025-11-07.
 * The calendar grid runs from Sunday 2025-10-26 to Saturday 2026-11-07 (54 weeks); every padded day
 * outside the period has count 3 (before) and totalContributions is the sum of all returned days.
 */
final class ContributionsQueryTest extends TestCase
{
    private ?string $capturedBody = null;

    private function period(): Period
    {
        return Period::trailingYear(new DateTimeImmutable('2026-11-06T00:00:00Z'));
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . "/../fixtures/github/{$name}.json");
    }

    private function query(string $body): ContributionsQuery
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($body): MockResponse {
            $this->capturedBody = $options['body'] ?? null;

            return new MockResponse($body, ['http_code' => 200]);
        });

        return new ContributionsQuery(new GraphQlClient($http, 'tok_123', 0, fn (int $seconds): int => 0));
    }

    private function failure(callable $call): GitHubApiException
    {
        try {
            $call();
        } catch (GitHubApiException $exception) {
            return $exception;
        }
        self::fail('Expected GitHubApiException.');
    }

    public function testParsesCalendarAndTotals(): void
    {
        $fixture = json_decode($this->fixture('contributions-ok'), true);
        $collection = $fixture['data']['user']['contributionsCollection'];

        $result = $this->query($this->fixture('contributions-ok'))->fetch('octo-sample', $this->period());

        self::assertSame('2025-11-07', $result->calendar->start);
        self::assertSame('2026-11-06', $result->calendar->end);
        self::assertCount(365, $result->calendar->days);
        self::assertCount(54, $fixture['data']['user']['contributionsCollection']['contributionCalendar']['weeks']);
        self::assertSame(['date' => '2025-11-07', 'count' => 0], $result->calendar->days[0]);
        self::assertSame(['date' => '2025-11-08', 'count' => 2], $result->calendar->days[1]);
        self::assertSame(
            [
                'commits' => $collection['totalCommitContributions'],
                'issues' => $collection['totalIssueContributions'],
                'pullRequests' => $collection['totalPullRequestContributions'],
                'reviews' => $collection['totalPullRequestReviewContributions'],
                'repositories' => $collection['totalRepositoryContributions'],
            ],
            $result->visibleTotals->toArray(),
        );
        self::assertSame(7, $result->restrictedContributions);
        self::assertTrue($result->hasAnyRestrictedContributions);
        self::assertSame(4990, $result->rateLimitRemaining);
        self::assertSame($collection['contributionCalendar']['totalContributions'], $result->calendarTotal);
    }

    public function testSendsLoginAndPeriodVariables(): void
    {
        $this->query($this->fixture('contributions-ok'))->fetch('octo-sample', $this->period());

        $payload = json_decode((string) $this->capturedBody, true);
        self::assertSame(
            ['login' => 'octo-sample', 'from' => '2025-11-07T00:00:00Z', 'to' => '2026-11-06T23:59:59Z'],
            $payload['variables'],
        );
    }

    public function testLoginCaseIsNormalizedFromGitHub(): void
    {
        $result = $this->query($this->fixture('contributions-ok'))->fetch('OCTO-SAMPLE', $this->period());

        self::assertSame('octo-sample', $result->login);
    }

    public function testUnknownUserIsNotFound(): void
    {
        $query = $this->query($this->fixture('contributions-user-null'));

        $exception = $this->failure(fn () => $query->fetch('ghost', $this->period()));

        self::assertSame(GitHubErrorKind::NotFound, $exception->kind);
        self::assertStringContainsString('ghost', $exception->getMessage());
    }

    public function testLoginMismatchFails(): void
    {
        $body = str_replace('"login": "octo-sample"', '"login": "someone-else"', $this->fixture('contributions-ok'));
        $query = $this->query($body);

        $exception = $this->failure(fn () => $query->fetch('octo-sample', $this->period()));

        self::assertSame(GitHubErrorKind::Response, $exception->kind);
    }

    public function testMissingDayFails(): void
    {
        $query = $this->query($this->fixture('contributions-missing-day'));

        $exception = $this->failure(fn () => $query->fetch('octo-sample', $this->period()));

        self::assertSame(GitHubErrorKind::Response, $exception->kind);
        self::assertStringContainsString('calendar', $exception->getMessage());
    }

    public function testCalendarTotalMismatchFails(): void
    {
        $data = json_decode($this->fixture('contributions-ok'), true);
        $data['data']['user']['contributionsCollection']['contributionCalendar']['totalContributions']++;
        $query = $this->query(json_encode($data));

        $exception = $this->failure(fn () => $query->fetch('octo-sample', $this->period()));

        self::assertSame(GitHubErrorKind::Response, $exception->kind);
        self::assertStringContainsString('total', $exception->getMessage());
    }

    public function testCalendarTotalMatchingOnlyInPeriodDaysIsAccepted(): void
    {
        $data = json_decode($this->fixture('contributions-ok'), true);
        $calendar = &$data['data']['user']['contributionsCollection']['contributionCalendar'];
        $inPeriod = 0;
        foreach ($calendar['weeks'] as $week) {
            foreach ($week['contributionDays'] as $day) {
                if ($this->period()->contains($day['date'])) {
                    $inPeriod += $day['contributionCount'];
                }
            }
        }
        self::assertNotSame($calendar['totalContributions'], $inPeriod);
        $calendar['totalContributions'] = $inPeriod;

        $result = $this->query(json_encode($data))->fetch('octo-sample', $this->period());

        self::assertSame($inPeriod, $result->calendarTotal);
    }

    public function testNoRestrictedCountsParse(): void
    {
        $result = $this->query($this->fixture('contributions-no-restricted'))->fetch('octo-sample', $this->period());

        self::assertSame(0, $result->restrictedContributions);
        self::assertFalse($result->hasAnyRestrictedContributions);
    }

    public function testParsesViewerLogin(): void
    {
        $result = $this->query($this->fixture('contributions-no-restricted'))->fetch('octo-sample', $this->period());

        self::assertSame('someone-else', $result->viewerLogin);
        self::assertStringContainsString('viewer { login }', ContributionsQuery::QUERY);
    }

    public function testMissingViewerLoginFails(): void
    {
        $data = json_decode($this->fixture('contributions-ok'), true);
        unset($data['data']['viewer']);
        $query = $this->query(json_encode($data));

        $exception = $this->failure(fn () => $query->fetch('octo-sample', $this->period()));

        self::assertSame(GitHubErrorKind::Response, $exception->kind);
        self::assertStringContainsString('viewer.login', $exception->getMessage());
    }
}
