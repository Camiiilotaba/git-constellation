<?php

declare(strict_types=1);

namespace GitConstellation\Tests\Config;

use GitConstellation\Config\Environment;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EnvironmentTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/constellation-env-' . bin2hex(random_bytes(8));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        if (is_file($this->root . '/.env')) {
            unlink($this->root . '/.env');
        }
        rmdir($this->root);
    }

    public function testProcessEnvironmentWins(): void
    {
        file_put_contents($this->root . '/.env', "GITHUB_TOKEN=file\n");

        self::assertSame('proc', Environment::load($this->root, ['GITHUB_TOKEN' => 'proc'])->githubToken);
    }

    public function testDotEnvIsParsedWithQuotesAndExport(): void
    {
        file_put_contents($this->root . '/.env', "export GITHUB_TOKEN=\"ghp_abc\"\n");

        self::assertSame('ghp_abc', Environment::load($this->root, [])->githubToken);
    }

    public function testMissingEverywhereFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/read:user.*' . preg_quote($this->root, '/') . '/');

        Environment::load($this->root, ['GITHUB_TOKEN' => false]);
    }

    public function testMalformedTokenFails(): void
    {
        try {
            Environment::load($this->root, ['GITHUB_TOKEN' => 'abc def']);
            self::fail('Expected RuntimeException.');
        } catch (RuntimeException $exception) {
            self::assertStringNotContainsString('abc def', $exception->getMessage());
            self::assertStringContainsString('whitespace or control characters', $exception->getMessage());
        }
    }

    public function testDotEnvIsNeverExportedToProcess(): void
    {
        file_put_contents($this->root . '/.env', "GITHUB_TOKEN=ghp_secret\n");
        $before = [getenv('GITHUB_TOKEN'), $_ENV['GITHUB_TOKEN'] ?? null, $_SERVER['GITHUB_TOKEN'] ?? null];

        Environment::load($this->root, []);

        self::assertSame($before, [getenv('GITHUB_TOKEN'), $_ENV['GITHUB_TOKEN'] ?? null, $_SERVER['GITHUB_TOKEN'] ?? null]);
    }
}
