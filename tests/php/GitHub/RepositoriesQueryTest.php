<?php

declare(strict_types=1);

namespace GitConstellation\Tests\GitHub;

use GitConstellation\GitHub\GitHubApiException;
use GitConstellation\GitHub\GitHubErrorKind;
use GitConstellation\GitHub\GraphQlClient;
use GitConstellation\GitHub\RepositoriesQuery;
use GitConstellation\Statistics\LanguageRanking;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RepositoriesQueryTest extends TestCase
{
    /** @var list<string> */
    private array $capturedBodies = [];

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . "/../fixtures/github/{$name}.json");
    }

    /** @param list<string> $bodies */
    private function query(array $bodies): RepositoriesQuery
    {
        $index = 0;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($bodies, &$index): MockResponse {
            $this->capturedBodies[] = (string) ($options['body'] ?? '');

            return new MockResponse($bodies[min($index++, count($bodies) - 1)], ['http_code' => 200]);
        });

        return new RepositoriesQuery(new GraphQlClient($http, 'tok_123', 0, fn (int $seconds): int => 0));
    }

    /** @return list<string> */
    private function threePages(): array
    {
        return [
            $this->fixture('repositories-page-1'),
            $this->fixture('repositories-page-2'),
            $this->fixture('repositories-page-3'),
        ];
    }

    public function testPaginatesUntilLastPage(): void
    {
        $result = $this->query($this->threePages())->fetch('octo-sample');

        self::assertCount(3, $this->capturedBodies);
        self::assertSame(8, $result->scanned);
        self::assertCount(8, $result->repositories);
        $first = json_decode($this->capturedBodies[0], true);
        $second = json_decode($this->capturedBodies[1], true);
        self::assertSame(['login' => 'octo-sample', 'after' => null], $first['variables']);
        self::assertSame('cursor-page-1', $second['variables']['after']);
        self::assertSame('cursor-page-2', json_decode($this->capturedBodies[2], true)['variables']['after']);
    }

    public function testNullColorGetsFallback(): void
    {
        $result = $this->query($this->threePages())->fetch('octo-sample');

        self::assertContains(
            ['name' => 'Zork', 'color' => RepositoriesQuery::FALLBACK_COLOR],
            $result->repositories[4]['languages'],
        );
        $names = array_column(LanguageRanking::calculate($result->repositories), 'name');
        self::assertContains('Zork', $names);
    }

    public function testArchivedAndForksArePassedThroughForRankingToFilter(): void
    {
        $result = $this->query($this->threePages())->fetch('octo-sample');

        self::assertTrue($result->repositories[3]['isArchived']);
        self::assertTrue($result->repositories[7]['isFork']);
        self::assertSame('PUBLIC', $result->repositories[0]['visibility']);
        self::assertSame(
            [
                ['name' => 'JavaScript', 'repositories' => 3, 'color' => '#f1e05a'],
                ['name' => 'PHP', 'repositories' => 3, 'color' => '#4f5d95'],
                ['name' => 'Rust', 'repositories' => 2, 'color' => '#dea584'],
                ['name' => 'Zork', 'repositories' => 1, 'color' => RepositoriesQuery::FALLBACK_COLOR],
            ],
            LanguageRanking::calculate($result->repositories),
        );
    }

    public function testTooManyPagesFails(): void
    {
        $query = $this->query([$this->fixture('repositories-page-1')]);

        try {
            $query->fetch('octo-sample');
            self::fail('Expected GitHubApiException.');
        } catch (GitHubApiException $exception) {
            self::assertSame(GitHubErrorKind::Response, $exception->kind);
            self::assertSame('More than 2000 public repositories; refusing to rank languages on a partial list', $exception->getMessage());
        }
        self::assertCount(20, $this->capturedBodies);
    }

    public function testUnknownUserIsNotFound(): void
    {
        $query = $this->query(['{"data":{"user":null}}']);

        try {
            $query->fetch('ghost');
            self::fail('Expected GitHubApiException.');
        } catch (GitHubApiException $exception) {
            self::assertSame(GitHubErrorKind::NotFound, $exception->kind);
            self::assertSame('GitHub user not found: ghost', $exception->getMessage());
        }
    }

    public function testQueryIncludesOwnedCollaboratedAndOrganizationRepositories(): void
    {
        self::assertStringContainsString('ownerAffiliations: [OWNER, COLLABORATOR, ORGANIZATION_MEMBER]', RepositoriesQuery::QUERY);
    }
}
