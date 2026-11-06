<?php

declare(strict_types=1);

namespace GitConstellation\Tests;

use GitConstellation\Config\Configuration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigurationTest extends TestCase
{
    private const array BASE = ['username' => 'some-user', 'name' => 'Some Developer', 'role' => 'Backend Developer', 'stack' => 'PHP / TypeScript'];

    public function testOptionalDefaultsAndOutputOverride(): void
    {
        $config = Configuration::fromArray(self::BASE);
        self::assertSame('dist/constellation.png', $config->output);
        self::assertSame('#30e8ef', $config->cyan);
        self::assertSame('#52f5a0', $config->green);
        $custom = Configuration::fromArray(self::BASE + ['colors' => ['cyan' => '#ABCDEF'], 'output' => 'dist/original.png'], 'dist/new image.png');
        self::assertSame('#abcdef', $custom->cyan);
        self::assertSame('dist/new image.png', $custom->output);
    }

    #[DataProvider('invalidFields')]
    public function testInvalidConfigurationFailsWithActionableField(array $patch, string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($field);
        Configuration::fromArray(array_replace(self::BASE, $patch));
    }

    public static function invalidFields(): iterable
    {
        yield 'missing name' => [['name' => null], 'name'];
        yield 'blank role' => [['role' => '  '], 'role'];
        yield 'control character' => [['stack' => "PHP\nTypeScript"], 'stack'];
        yield 'invalid username' => [['username' => 'user/name'], 'username'];
        yield 'leading dash' => [['username' => '-user'], 'username'];
        yield 'consecutive dashes' => [['username' => 'user--name'], 'username'];
        yield 'invalid colors object' => [['colors' => 'cyan'], 'colors'];
        yield 'null colors object' => [['colors' => null], 'colors'];
        yield 'CSS injection' => [['colors' => ['cyan' => '#fff; background:red']], 'cyan'];
        yield 'short hex' => [['colors' => ['green' => '#fff']], 'green'];
        yield 'null color' => [['colors' => ['green' => null]], 'green'];
        yield 'unknown color' => [['colors' => ['accent' => '#ffffff']], 'colors'];
        yield 'wrong output type' => [['output' => 3], 'output'];
        yield 'null output' => [['output' => null], 'output'];
        yield 'non PNG output' => [['output' => 'dist/output.html'], 'output'];
        yield 'path with NUL' => [['output' => "dist/hi\0.png"], 'output'];
        yield 'unknown config field' => [['githubToken' => 'example-not-a-real-token'], 'Unknown configuration field'];
    }
}
