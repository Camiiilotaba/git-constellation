<?php

declare(strict_types=1);

namespace GitConstellation\Tests\GitHub;

use Closure;
use GitConstellation\GitHub\GitHubApiException;
use GitConstellation\GitHub\GitHubErrorKind;
use GitConstellation\GitHub\GraphQlClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GraphQlClientTest extends TestCase
{
    private const string TOKEN = 'tok_123';

    /** @var list<int> */
    private array $sleeps = [];

    private int $requests = 0;

    /** @var list<string> */
    private array $authorizationHeaders = [];

    protected function setUp(): void
    {
        $this->sleeps = [];
        $this->requests = 0;
        $this->authorizationHeaders = [];
    }

    /** @param list<MockResponse> $responses */
    private function client(array $responses): GraphQlClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests++;
            $this->authorizationHeaders[] = $options['normalized_headers']['authorization'][0] ?? '';

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        $sleep = function (int $seconds): int {
            $this->sleeps[] = $seconds;

            return 0;
        };

        return new GraphQlClient($http, self::TOKEN, 2, Closure::fromCallable($sleep));
    }

    private function json(string $body, int $status = 200, array $headers = []): MockResponse
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return new MockResponse($body, ['http_code' => $status, 'response_headers' => $lines]);
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

    public function testSendsBearerTokenAndUserAgent(): void
    {
        $client = $this->client([$this->json('{"data":{}}')]);

        $client->query('{ viewer { login } }');

        self::assertSame(['Authorization: Bearer tok_123'], $this->authorizationHeaders);
    }

    public function testReturnsDataObject(): void
    {
        $client = $this->client([$this->json('{"data":{"viewer":{"login":"x"}}}')]);

        self::assertSame(['viewer' => ['login' => 'x']], $client->query('q'));
    }

    public function testCapturesScopesHeader(): void
    {
        $client = $this->client([$this->json('{"data":{}}', 200, ['x-oauth-scopes' => 'read:user, repo'])]);
        self::assertSame([], $client->lastScopes());

        $client->query('q');

        self::assertSame(['read:user', 'repo'], $client->lastScopes());
    }

    public function testUnauthorizedMapsToAuth(): void
    {
        $client = $this->client([$this->json('{"message":"Bad credentials"}', 401)]);

        $exception = $this->failure(fn () => $client->query('q'));

        self::assertSame(GitHubErrorKind::Auth, $exception->kind);
        self::assertStringContainsString('read:user', $exception->getMessage());
    }

    public function testRateLimitMapsWithReset(): void
    {
        $client = $this->client([$this->json('{}', 403, ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '1794000000'])]);

        $exception = $this->failure(fn () => $client->query('q'));

        self::assertSame(GitHubErrorKind::RateLimit, $exception->kind);
        self::assertStringContainsString(gmdate('Y-m-d\TH:i:s\Z', 1794000000), $exception->getMessage());
        self::assertStringContainsString('2026-11-06T', $exception->getMessage());
    }

    public function testTooManyRequestsMapsToRateLimit(): void
    {
        $client = $this->client([$this->json('{}', 429, ['retry-after' => '30'])]);

        $exception = $this->failure(fn () => $client->query('q'));

        self::assertSame(GitHubErrorKind::RateLimit, $exception->kind);
        self::assertStringContainsString('30', $exception->getMessage());
    }

    public function testServerErrorsRetryThenFail(): void
    {
        $client = $this->client([$this->json('', 503), $this->json('', 503), $this->json('', 503)]);

        $exception = $this->failure(fn () => $client->query('q'));

        self::assertSame(GitHubErrorKind::Transport, $exception->kind);
        self::assertSame([1, 2], $this->sleeps);
        self::assertSame(3, $this->requests);
    }

    public function testClientErrorsDoNotRetry(): void
    {
        $client = $this->client([$this->json('{"message":"nope"}', 422)]);

        $exception = $this->failure(fn () => $client->query('q'));

        self::assertSame(GitHubErrorKind::Response, $exception->kind);
        self::assertSame(1, $this->requests);
        self::assertSame([], $this->sleeps);
    }

    public function testGraphQlErrorsFailEvenWithData(): void
    {
        $client = $this->client([$this->json('{"data":{"viewer":{}},"errors":[{"message":"boom"}]}')]);

        $exception = $this->failure(fn () => $client->query('q'));

        self::assertSame(GitHubErrorKind::Response, $exception->kind);
        self::assertStringContainsString('boom', $exception->getMessage());
    }

    public function testNonJsonBodyFails(): void
    {
        $client = $this->client([$this->json('<html>')]);

        self::assertSame(GitHubErrorKind::Response, $this->failure(fn () => $client->query('q'))->kind);
    }

    public function testTokenNeverAppearsInMessages(): void
    {
        $scenarios = [
            [$this->json('{"message":"tok_123"}', 401)],
            [$this->json('{}', 403, ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => '1794000000'])],
            [$this->json('{}', 429, ['retry-after' => '30'])],
            [$this->json('', 503), $this->json('', 503), $this->json('', 503)],
            [new MockResponse('', ['error' => 'Connection refused']), new MockResponse('', ['error' => 'Connection refused']), new MockResponse('', ['error' => 'Connection refused'])],
            [$this->json('{"message":"tok_123"}', 422)],
            [$this->json('{"errors":[{"message":"boom"}]}')],
            [$this->json('<html>')],
            [$this->json('{"foo":1}')],
        ];

        foreach ($scenarios as $responses) {
            $client = $this->client($responses);
            $exception = $this->failure(fn () => $client->query('q'));

            self::assertStringNotContainsString(self::TOKEN, $exception->getMessage());
            self::assertStringNotContainsString(self::TOKEN, (string) $exception->getPrevious()?->getMessage());
        }
    }
}
