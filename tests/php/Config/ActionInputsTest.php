<?php

declare(strict_types=1);

namespace GitConstellation\Tests\Config;

use GitConstellation\Config\ActionInputs;
use GitConstellation\Config\Configuration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ActionInputsTest extends TestCase
{
    private const array PROFILE = ['name' => 'Some Developer', 'role' => 'Backend Developer', 'stack' => 'PHP / TypeScript'];

    public function testInputsBuildAValidConfigurationWithTheOwnerAsDefaultUsername(): void
    {
        $config = ActionInputs::toConfig(self::PROFILE + [
            'username' => '',
            'period' => 'last-year',
            'languages' => '["PHP", {"name": "TypeScript", "color": "#3178c6"}]',
            'exclude-languages' => '[]',
            'colors' => '{"cyan": "#112233"}',
        ], [], 'octo-owner');

        self::assertSame([
            'name' => 'Some Developer',
            'role' => 'Backend Developer',
            'stack' => 'PHP / TypeScript',
            'period' => 'last-year',
            'languages' => ['PHP', ['name' => 'TypeScript', 'color' => '#3178c6']],
            'excludeLanguages' => [],
            'colors' => ['cyan' => '#112233'],
            'username' => 'octo-owner',
        ], $config);
        self::assertSame('octo-owner', Configuration::fromArray($config)->username);
    }

    public function testNonEmptyInputsOverrideTheConfigFileAndOutputIsDropped(): void
    {
        $base = ['username' => 'file-user', 'name' => 'File Name', 'role' => 'File Role', 'stack' => 'File Stack', 'output' => 'x.png', 'period' => 'all-time'];

        $config = ActionInputs::toConfig(['name' => '  Input Name ', 'role' => '', 'period' => ''], $base, 'octo-owner');

        self::assertSame('file-user', $config['username']);
        self::assertSame('Input Name', $config['name']);
        self::assertSame('File Role', $config['role']);
        self::assertSame('all-time', $config['period']);
        self::assertArrayNotHasKey('output', $config);
    }

    public function testMissingProfileTextFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The role input is required');

        ActionInputs::toConfig(['name' => 'Some Developer', 'stack' => 'PHP'], [], 'octo-owner');
    }

    public function testInvalidJsonInputFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The colors input must be valid JSON.');

        ActionInputs::toConfig(self::PROFILE + ['colors' => '{cyan: 1}'], [], 'octo-owner');
    }
}
