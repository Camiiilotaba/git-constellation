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

    public function testLanguageOptionsAreNormalized(): void
    {
        $defaults = Configuration::fromArray(self::BASE);
        self::assertNull($defaults->excludeLanguages);
        self::assertNull($defaults->languages);
        $config = Configuration::fromArray(self::BASE + [
            'excludeLanguages' => [],
            'languages' => ['PHP', ['name' => ' TypeScript ', 'color' => '#ABCDEF'], ['name' => 'Go']],
        ]);
        self::assertSame([], $config->excludeLanguages);
        self::assertSame([
            ['name' => 'PHP', 'color' => null],
            ['name' => 'TypeScript', 'color' => '#abcdef'],
            ['name' => 'Go', 'color' => null],
        ], $config->languages);
    }

    public function testDefaultOutputFollowsTheModeDefault(): void
    {
        self::assertSame('dist/live.png', Configuration::fromArray(self::BASE, null, 'dist/live.png')->output);
        self::assertSame('dist/own.png', Configuration::fromArray(self::BASE + ['output' => 'dist/own.png'], null, 'dist/live.png')->output);
        self::assertSame('dist/cli.png', Configuration::fromArray(self::BASE, 'dist/cli.png', 'dist/live.png')->output);
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
        yield 'excludeLanguages not a list' => [['excludeLanguages' => 'CSS'], 'excludeLanguages'];
        yield 'excludeLanguages empty name' => [['excludeLanguages' => ['CSS', ' ']], 'excludeLanguages'];
        yield 'excludeLanguages non-string' => [['excludeLanguages' => [1]], 'excludeLanguages'];
        yield 'excludeLanguages too long' => [['excludeLanguages' => [str_repeat('a', 81)]], 'excludeLanguages'];
        yield 'excludeLanguages control char' => [['excludeLanguages' => ["C\nSS"]], 'excludeLanguages'];
        yield 'languages empty' => [['languages' => []], 'languages'];
        yield 'languages not a list' => [['languages' => ['name' => 'PHP']], 'languages'];
        yield 'languages too many' => [['languages' => ['A', 'B', 'C', 'D', 'E', 'F']], 'languages'];
        yield 'languages duplicate' => [['languages' => ['PHP', 'php']], 'duplicate'];
        yield 'languages bad entry type' => [['languages' => [3]], 'languages'];
        yield 'languages missing name' => [['languages' => [['color' => '#ffffff']]], 'languages'];
        yield 'languages bad color' => [['languages' => [['name' => 'PHP', 'color' => 'red']]], 'color'];
        yield 'languages unknown key' => [['languages' => [['name' => 'PHP', 'size' => 1]]], 'languages'];
        yield 'languages long name' => [['languages' => [str_repeat('a', 81)]], 'languages'];
        yield 'unknown config field' => [['githubToken' => 'example-not-a-real-token'], 'Unknown configuration field'];
    }
}
