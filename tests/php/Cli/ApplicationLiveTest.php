<?php

declare(strict_types=1);

namespace GitConstellation\Tests\Cli;

use DateTimeImmutable;
use GitConstellation\Cli\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CapturingFilter extends \php_user_filter
{
    public static string $captured = '';

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            self::$captured .= $bucket->data;
            $consumed += $bucket->datalen;
        }

        return PSFS_PASS_ON;
    }
}

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

    /**
     * @param array<string, string> $environment
     * @return array{int, string} Exit code and everything written to STDERR.
     */
    private function runLive(array $environment, int $status, string $config = 'config/local.json'): array
    {
        $this->requests = 0;
        $http = new MockHttpClient(function () use ($status): MockResponse {
            ++$this->requests;

            return new MockResponse('{"message":"Bad credentials"}', ['http_code' => $status]);
        });
        $application = new Application($this->root, $http, new DateTimeImmutable('2026-11-06T12:00:00Z'), $environment);

        // Application writes with fwrite(STDERR), which output buffering cannot capture; a stream filter can.
        CapturingFilter::$captured = '';
        stream_filter_register('constellation.capture', CapturingFilter::class);
        $filter = stream_filter_append(STDERR, 'constellation.capture', STREAM_FILTER_WRITE);
        try {
            $code = $application->run(['--live', "--config={$config}"]);
        } finally {
            stream_filter_remove($filter);
        }

        return [$code, CapturingFilter::$captured];
    }

    public function testLiveFailureWritesNothingAndReturnsOne(): void
    {
        [$code, $stderr] = $this->runLive(['GITHUB_TOKEN' => 'tok_test'], 401);
        self::assertSame(1, $code);
        self::assertStringContainsString('HTTP 401', $stderr);
        self::assertStringNotContainsString('tok_test', $stderr);
        self::assertStringContainsString('token source: environment', $stderr);
        self::assertFileDoesNotExist($this->root . '/build/data.json');
        self::assertSame(1, $this->requests);
    }

    public function testLiveWithoutTokenFailsBeforeAnyRequest(): void
    {
        [$code, $stderr] = $this->runLive([], 200);
        self::assertSame(1, $code);
        self::assertStringContainsString('read:user', $stderr);
        self::assertFileDoesNotExist($this->root . '/build/data.json');
        self::assertSame(0, $this->requests);
    }

    public function testLiveRefusesToOverwriteTheDemoImageBeforeAnyRequest(): void
    {
        $config = json_decode((string) file_get_contents($this->root . '/config/local.json'), true);
        $config['output'] = 'dist/../dist/constellation.png';
        file_put_contents($this->root . '/config/demo-output.json', json_encode($config));

        [$code, $stderr] = $this->runLive(['GITHUB_TOKEN' => 'tok_test'], 200, 'config/demo-output.json');
        @unlink($this->root . '/config/demo-output.json');

        self::assertSame(1, $code);
        self::assertStringContainsString('Live mode refuses to overwrite dist/constellation.png', $stderr);
        self::assertSame(0, $this->requests);
        self::assertFileDoesNotExist($this->root . '/dist/constellation.png');
    }

    public function testLiveWithoutOutputInConfigDoesNotTargetTheDemoImage(): void
    {
        $config = json_decode((string) file_get_contents($this->root . '/config/local.json'), true);
        unset($config['output']);
        file_put_contents($this->root . '/config/no-output.json', json_encode($config));

        [$code, $stderr] = $this->runLive(['GITHUB_TOKEN' => 'tok_test'], 401, 'config/no-output.json');
        @unlink($this->root . '/config/no-output.json');

        self::assertSame(1, $code);
        self::assertStringNotContainsString('refuses to overwrite', $stderr);
        self::assertSame(1, $this->requests);
        self::assertFileDoesNotExist($this->root . '/dist/constellation.png');
    }

    public function testLiveWithMissingConfigHintsAtTheExample(): void
    {
        [$code, $stderr] = $this->runLive(['GITHUB_TOKEN' => 'tok_test'], 200, 'config/missing.json');

        self::assertSame(1, $code);
        self::assertStringContainsString('live.example.json', $stderr);
        self::assertSame(0, $this->requests);
    }
}
