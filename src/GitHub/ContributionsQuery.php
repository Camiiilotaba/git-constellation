<?php

declare(strict_types=1);

namespace GitConstellation\GitHub;

use DateTimeImmutable;
use DateTimeZone;
use GitConstellation\Data\ContributionCalendar;
use GitConstellation\Data\Period;
use GitConstellation\Statistics\VisibleTotals;
use GitConstellation\Support\IntegerRange;
use InvalidArgumentException;

final class ContributionsQuery
{
    public const string QUERY = <<<'GRAPHQL'
query($login: String!, $from: DateTime!, $to: DateTime!) {
  rateLimit { remaining resetAt }
  viewer { login }
  user(login: $login) {
    login
    contributionsCollection(from: $from, to: $to) {
      contributionCalendar {
        totalContributions
        weeks { contributionDays { date contributionCount } }
      }
      totalCommitContributions
      totalIssueContributions
      totalPullRequestContributions
      totalPullRequestReviewContributions
      totalRepositoryContributions
      restrictedContributionsCount
      hasAnyRestrictedContributions
    }
  }
}
GRAPHQL;

    public const string YEARS_QUERY = <<<'GRAPHQL'
query($login: String!) {
  user(login: $login) {
    login
    contributionsCollection { contributionYears }
  }
}
GRAPHQL;

    private const int FIRST_GITHUB_YEAR = 2008;

    public function __construct(private GraphQlClient $client) {}

    /**
     * One query per calendar year, oldest first, from the first contribution year to today (UTC). Years
     * without contributions inside that range are queried too, so the joined calendar has no invented gaps.
     *
     * @return non-empty-list<ContributionsResult>
     */
    public function fetchAllYears(string $login, DateTimeImmutable $now): array
    {
        $today = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        $currentYear = (int) substr($today, 0, 4);
        $years = $this->contributionYears($login, $currentYear);
        $results = [];
        for ($year = $years === [] ? $currentYear : min($years); $year <= $currentYear; ++$year) {
            $results[] = $this->fetch($login, Period::between("{$year}-01-01", $year === $currentYear ? $today : "{$year}-12-31"));
        }

        return $results;
    }

    /** @return list<int> */
    private function contributionYears(string $login, int $currentYear): array
    {
        $data = $this->client->query(self::YEARS_QUERY, ['login' => $login]);
        if (!array_key_exists('user', $data) || $data['user'] === null) {
            throw new GitHubApiException(GitHubErrorKind::NotFound, "GitHub user not found: {$login}");
        }
        $collection = $this->object($this->object($data, 'user', 'user'), 'contributionsCollection', 'contributionsCollection');
        $years = $collection['contributionYears'] ?? null;
        if (!is_array($years) || !array_is_list($years)) {
            throw $this->invalid('contributionsCollection.contributionYears');
        }
        foreach ($years as $year) {
            if (!is_int($year) || $year < self::FIRST_GITHUB_YEAR) {
                throw $this->invalid('contributionsCollection.contributionYears');
            }
        }

        // A year after today's UTC year (a time-zone edge) holds nothing up to today.
        return array_values(array_filter($years, fn (int $year): bool => $year <= $currentYear));
    }

    public function fetch(string $login, Period $period): ContributionsResult
    {
        $data = $this->client->query(self::QUERY, [
            'login' => $login,
            'from' => $period->fromDateTime(),
            'to' => $period->toDateTime(),
        ]);

        $rateLimitRemaining = $this->integer($this->object($data, 'rateLimit', 'rateLimit'), 'remaining', 'rateLimit.remaining');

        if (!array_key_exists('user', $data) || $data['user'] === null) {
            throw new GitHubApiException(GitHubErrorKind::NotFound, "GitHub user not found: {$login}");
        }
        $user = $this->object($data, 'user', 'user');
        $returnedLogin = $user['login'] ?? null;
        if (!is_string($returnedLogin)) {
            throw $this->invalid('user.login');
        }
        if (strcasecmp($returnedLogin, $login) !== 0) {
            throw new GitHubApiException(GitHubErrorKind::Response, "GitHub returned a different user than requested: {$returnedLogin}");
        }

        $viewer = $data['viewer'] ?? null;
        $viewerLogin = is_array($viewer) ? ($viewer['login'] ?? null) : null;
        if (!is_string($viewerLogin)) {
            throw $this->invalid('viewer.login');
        }

        $collection = $this->object($user, 'contributionsCollection', 'contributionsCollection');
        $calendarData = $this->object($collection, 'contributionCalendar', 'contributionsCollection.contributionCalendar');
        $calendarTotal = $this->integer($calendarData, 'totalContributions', 'contributionCalendar.totalContributions');
        $returnedDays = $this->days($calendarData);

        // GitHub pads the calendar to whole weeks, so it returns days outside the requested period.
        $periodDays = array_values(array_filter($returnedDays, fn (array $day): bool => $period->contains($day['date'])));
        $this->assertCalendarTotal($calendarTotal, $returnedDays, $periodDays);
        try {
            $calendar = ContributionCalendar::fromArray(['start' => $period->start, 'end' => $period->end], $periodDays);
        } catch (InvalidArgumentException $exception) {
            throw new GitHubApiException(GitHubErrorKind::Response, $exception->getMessage(), $exception);
        }

        $field = fn (string $name): int => $this->integer($collection, $name, "contributionsCollection.{$name}");
        $hasAnyRestricted = $collection['hasAnyRestrictedContributions'] ?? null;
        if (!is_bool($hasAnyRestricted)) {
            throw $this->invalid('contributionsCollection.hasAnyRestrictedContributions');
        }

        return new ContributionsResult(
            $returnedLogin,
            $viewerLogin,
            $calendar,
            $calendarTotal,
            new VisibleTotals(
                $field('totalCommitContributions'),
                $field('totalIssueContributions'),
                $field('totalPullRequestContributions'),
                $field('totalPullRequestReviewContributions'),
                $field('totalRepositoryContributions'),
            ),
            $field('restrictedContributionsCount'),
            $hasAnyRestricted,
            $rateLimitRemaining,
        );
    }

    /**
     * Never render numbers that GitHub's own total does not confirm. Whether the total counts the padded
     * days is unverified against the real API, so either sum is accepted.
     *
     * @param list<array{date: string, count: int}> $returnedDays
     * @param list<array{date: string, count: int}> $periodDays
     */
    private function assertCalendarTotal(int $calendarTotal, array $returnedDays, array $periodDays): void
    {
        if ($calendarTotal !== array_sum(array_column($returnedDays, 'count'))
            && $calendarTotal !== array_sum(array_column($periodDays, 'count'))) {
            throw new GitHubApiException(GitHubErrorKind::Response, 'GitHub calendar total does not match its daily counts');
        }
    }

    /**
     * @param array<mixed> $calendarData
     * @return list<array{date: string, count: int}>
     */
    private function days(array $calendarData): array
    {
        $weeks = $calendarData['weeks'] ?? null;
        if (!is_array($weeks)) {
            throw $this->invalid('contributionCalendar.weeks');
        }

        $days = [];
        foreach ($weeks as $week) {
            $weekDays = is_array($week) ? ($week['contributionDays'] ?? null) : null;
            if (!is_array($weekDays)) {
                throw $this->invalid('contributionCalendar.weeks[].contributionDays');
            }
            foreach ($weekDays as $day) {
                $date = is_array($day) ? ($day['date'] ?? null) : null;
                if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
                    throw $this->invalid('contributionDays[].date');
                }
                $days[] = ['date' => $date, 'count' => $this->integer($day, 'contributionCount', "contributionDays[{$date}].contributionCount")];
            }
        }

        return $days;
    }

    /**
     * @param array<mixed> $parent
     * @return array<mixed>
     */
    private function object(array $parent, string $key, string $path): array
    {
        $value = $parent[$key] ?? null;
        if (!is_array($value)) {
            throw $this->invalid($path);
        }

        return $value;
    }

    /** @param array<mixed> $parent */
    private function integer(array $parent, string $key, string $path): int
    {
        try {
            return IntegerRange::nonNegative($parent[$key] ?? null, $path);
        } catch (InvalidArgumentException $exception) {
            throw new GitHubApiException(GitHubErrorKind::Response, "GitHub response field is invalid: {$exception->getMessage()}", $exception);
        }
    }

    private function invalid(string $path): GitHubApiException
    {
        return new GitHubApiException(GitHubErrorKind::Response, "GitHub response is missing or has an invalid field: {$path}");
    }
}
