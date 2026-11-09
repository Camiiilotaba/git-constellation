<?php

declare(strict_types=1);

namespace GitConstellation\GitHub;

final class RepositoriesQuery
{
    public const string QUERY = <<<'GRAPHQL'
query($login: String!, $after: String) {
  user(login: $login) {
    repositories(
      first: 100, after: $after,
      ownerAffiliations: [OWNER, COLLABORATOR, ORGANIZATION_MEMBER], privacy: PUBLIC, isFork: false,
      orderBy: { field: NAME, direction: ASC }
    ) {
      pageInfo { hasNextPage endCursor }
      nodes {
        isArchived isFork visibility
        languages(first: 20, orderBy: { field: SIZE, direction: DESC }) {
          edges { size node { name color } }
        }
      }
    }
  }
}
GRAPHQL;

    public const int PAGE_SIZE = 100;
    public const int MAX_PAGES = 20;
    // GitHub leaves some languages uncoloured. The colour is presentation; the repository count is the statistic.
    public const string FALLBACK_COLOR = '#8b949e';

    public function __construct(private GraphQlClient $client) {}

    public function fetch(string $login): RepositoriesResult
    {
        $repositories = [];
        $scanned = 0;
        $after = null;

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $data = $this->client->query(self::QUERY, ['login' => $login, 'after' => $after]);

            if (!array_key_exists('user', $data) || $data['user'] === null) {
                throw new GitHubApiException(GitHubErrorKind::NotFound, "GitHub user not found: {$login}");
            }
            $user = $this->object($data, 'user', 'user');
            $connection = $this->object($user, 'repositories', 'user.repositories');
            $pageInfo = $this->object($connection, 'pageInfo', 'repositories.pageInfo');
            $nodes = $connection['nodes'] ?? null;
            if (!is_array($nodes)) {
                throw $this->invalid('repositories.nodes');
            }

            foreach ($nodes as $node) {
                // GraphQL may return null for a node it could not resolve; that is not a repository.
                if ($node === null) {
                    continue;
                }
                $repositories[] = $this->repository($node);
                $scanned++;
            }

            $hasNextPage = $pageInfo['hasNextPage'] ?? null;
            if (!is_bool($hasNextPage)) {
                throw $this->invalid('repositories.pageInfo.hasNextPage');
            }
            if (!$hasNextPage) {
                return new RepositoriesResult($repositories, $scanned);
            }
            $after = $pageInfo['endCursor'] ?? null;
            if (!is_string($after) || $after === '') {
                throw $this->invalid('repositories.pageInfo.endCursor');
            }
        }

        // Ranking languages from the first MAX_PAGES pages would silently misreport the rest, so refuse instead.
        throw new GitHubApiException(GitHubErrorKind::Response, 'More than 2000 public repositories; refusing to rank languages on a partial list');
    }

    /** @return array{visibility: string, isFork: bool, isArchived: bool, languages: list<array{name: string, color: string, size: int}>} */
    private function repository(mixed $node): array
    {
        if (!is_array($node)) {
            throw $this->invalid('repositories.nodes[]');
        }
        $visibility = $node['visibility'] ?? null;
        if (!is_string($visibility)) {
            throw $this->invalid('repositories.nodes[].visibility');
        }
        $isFork = $node['isFork'] ?? null;
        if (!is_bool($isFork)) {
            throw $this->invalid('repositories.nodes[].isFork');
        }
        $isArchived = $node['isArchived'] ?? null;
        if (!is_bool($isArchived)) {
            throw $this->invalid('repositories.nodes[].isArchived');
        }

        return [
            'visibility' => $visibility,
            'isFork' => $isFork,
            'isArchived' => $isArchived,
            'languages' => $this->languages($node),
        ];
    }

    /**
     * @param array<mixed> $node
     * @return list<array{name: string, color: string, size: int}>
     */
    private function languages(array $node): array
    {
        $edges = $this->object($this->object($node, 'languages', 'repositories.nodes[].languages'), 'edges', 'repositories.nodes[].languages.edges');

        $languages = [];
        foreach ($edges as $edge) {
            if ($edge === null) {
                continue;
            }
            $size = is_array($edge) ? ($edge['size'] ?? null) : null;
            if (!is_int($size) || $size < 0) {
                throw $this->invalid('languages.edges[].size');
            }
            $language = is_array($edge) ? ($edge['node'] ?? null) : null;
            $name = is_array($language) ? ($language['name'] ?? null) : null;
            if (!is_string($name)) {
                throw $this->invalid('languages.edges[].node.name');
            }
            $color = $language['color'] ?? null;
            if ($color !== null && !is_string($color)) {
                throw $this->invalid('languages.edges[].node.color');
            }
            $languages[] = ['name' => $name, 'color' => $color ?? self::FALLBACK_COLOR, 'size' => $size];
        }

        return $languages;
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

    private function invalid(string $path): GitHubApiException
    {
        return new GitHubApiException(GitHubErrorKind::Response, "GitHub response is missing or has an invalid field: {$path}");
    }
}
