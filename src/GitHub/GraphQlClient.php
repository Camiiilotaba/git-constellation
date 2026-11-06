<?php

declare(strict_types=1);

namespace GitConstellation\GitHub;

use Closure;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class GraphQlClient
{
    public const string ENDPOINT = 'https://api.github.com/graphql';
    public const string USER_AGENT = 'git-constellation';

    private const int TIMEOUT_SECONDS = 30;
    private const int MAX_DURATION_SECONDS = 60;
    private const int MAX_BODY_BYTES = 5 * 1024 * 1024;

    private readonly Closure $sleep;

    /** @var list<string> */
    private array $lastScopes = [];

    public function __construct(
        private readonly HttpClientInterface $http,
        #[\SensitiveParameter] private readonly string $token,
        private readonly int $maxRetries = 2,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? sleep(...);
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, mixed> the GraphQL `data` object
     */
    public function query(string $query, array $variables = []): array
    {
        $response = $this->sendWithRetries($query, $variables);
        $this->lastScopes = $this->parseScopes($response->getHeaders(false)['x-oauth-scopes'][0] ?? '');

        $body = $response->getContent(false);
        $declaredLength = (int) ($response->getHeaders(false)['content-length'][0] ?? 0);
        if ($declaredLength > self::MAX_BODY_BYTES || strlen($body) > self::MAX_BODY_BYTES) {
            throw new GitHubApiException(GitHubErrorKind::Response, 'GitHub response is larger than 5 MB.');
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new GitHubApiException(GitHubErrorKind::Response, 'GitHub returned a non-JSON response.');
        }
        if (!is_array($decoded)) {
            throw new GitHubApiException(GitHubErrorKind::Response, 'GitHub returned an unexpected response.');
        }

        // Any error fails the run even when `data` is present: partial statistics would be silently wrong.
        if (isset($decoded['errors']) && is_array($decoded['errors']) && $decoded['errors'] !== []) {
            $messages = array_map(
                static fn (mixed $error): string => is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : '(no message)',
                $decoded['errors'],
            );
            throw new GitHubApiException(GitHubErrorKind::Response, 'GitHub GraphQL errors: ' . implode('; ', $messages));
        }

        if (!isset($decoded['data']) || !is_array($decoded['data'])) {
            throw new GitHubApiException(GitHubErrorKind::Response, 'GitHub response has no data.');
        }

        return $decoded['data'];
    }

    /** @return list<string> */
    public function lastScopes(): array
    {
        return $this->lastScopes;
    }

    /** @param array<string, mixed> $variables */
    private function sendWithRetries(string $query, array $variables): ResponseInterface
    {
        $attempt = 0;
        while (true) {
            try {
                $response = $this->http->request('POST', self::ENDPOINT, [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->token,
                        'User-Agent' => self::USER_AGENT,
                        'Accept' => 'application/json',
                    ],
                    'json' => ['query' => $query, 'variables' => (object) $variables],
                    'timeout' => self::TIMEOUT_SECONDS,
                    'max_duration' => self::MAX_DURATION_SECONDS,
                ]);
                $status = $response->getStatusCode();
                $failure = null;
            } catch (TransportExceptionInterface $exception) {
                $response = null;
                $status = 0;
                $failure = $exception;
            }

            if ($response !== null && $status < 500) {
                $this->assertNotClientError($response, $status);

                return $response;
            }

            // Only transient failures are retried; a 4xx would fail identically every time.
            if ($attempt >= $this->maxRetries) {
                throw new GitHubApiException(
                    GitHubErrorKind::Transport,
                    $failure !== null
                        ? 'Could not reach GitHub: ' . $failure->getMessage()
                        : "GitHub is unavailable (HTTP {$status}) after " . ($attempt + 1) . ' attempts.',
                    $failure,
                );
            }

            $attempt++;
            ($this->sleep)($attempt);
        }
    }

    private function assertNotClientError(ResponseInterface $response, int $status): void
    {
        if ($status < 400) {
            return;
        }

        if ($status === 401) {
            throw new GitHubApiException(GitHubErrorKind::Auth, 'GitHub rejected the token (HTTP 401). Check GITHUB_TOKEN and the read:user scope.');
        }

        $headers = $response->getHeaders(false);
        $exhausted = ($headers['x-ratelimit-remaining'][0] ?? null) === '0';
        if ($status === 429 || ($status === 403 && $exhausted)) {
            throw new GitHubApiException(GitHubErrorKind::RateLimit, $this->rateLimitMessage($headers));
        }

        throw new GitHubApiException(GitHubErrorKind::Response, "GitHub returned HTTP {$status}.");
    }

    /** @param array<string, list<string>> $headers */
    private function rateLimitMessage(array $headers): string
    {
        $reset = $headers['x-ratelimit-reset'][0] ?? null;
        if ($reset !== null && ctype_digit($reset)) {
            return 'GitHub rate limit reached. Resets at ' . gmdate('Y-m-d\TH:i:s\Z', (int) $reset) . '.';
        }

        $retryAfter = $headers['retry-after'][0] ?? null;
        if ($retryAfter !== null && ctype_digit($retryAfter)) {
            return "GitHub rate limit reached. Retry after {$retryAfter} seconds.";
        }

        return 'GitHub rate limit reached.';
    }

    /** @return list<string> */
    private function parseScopes(string $header): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $header)),
            static fn (string $scope): bool => $scope !== '',
        ));
    }
}
