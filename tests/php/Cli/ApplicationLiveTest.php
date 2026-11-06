<?php

declare(strict_types=1);

namespace GitConstellation\Tests\Cli;

use DateTimeImmutable;
use GitConstellation\Cli\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ApplicationLiveTest extends TestCase
{
    private string $root;
    private int $requests = 0;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/constellation-live-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/config', 0775, true);
        file_put_contents($this->root . '/config/local.json', (string) file_get_contents(__DIR__ . '/../../../config/live.example.json'));
    }

    protected function tearDown(): void
    {
        foreach ([$this->root . '/config/local.json', $this->root . '/build/data.json'] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . '/build');
        @rmdir($this->root . '/config');
        @rmdir($this->root);
    }

    /** @param array<string, string> $environment */
    private function runLive(array $environment, int $status): int
    {
        $this->requests = 0;
        $http = new MockHttpClient(function () use ($status): MockResponse {
            ++$this->requests;

            return new MockResponse('{"message":"Bad credentials"}', ['http_code' => $status]);
        });
        $application = new Application($this->root, $http, new DateTimeImmutable('2026-11-06T12:00:00Z'), $environment);

        ob_start();
        try {
            return $application->run(['--live', '--config=config/local.json']);
        } finally {
            ob_end_clean();
        }
    }

    public function testLiveFailureWritesNothingAndReturnsOne(): void
    {
        self::assertSame(1, $this->runLive(['GITHUB_TOKEN' => 'tok_test'], 401));
        self::assertFileDoesNotExist($this->root . '/build/data.json');
        self::assertSame(1, $this->requests);
    }

    public function testLiveWithoutTokenFailsBeforeAnyRequest(): void
    {
        self::assertSame(1, $this->runLive([], 200));
        self::assertFileDoesNotExist($this->root . '/build/data.json');
        self::assertSame(0, $this->requests);
    }
}
