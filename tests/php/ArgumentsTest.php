<?php

declare(strict_types=1);

namespace GitConstellation\Tests;

use GitConstellation\Cli\Arguments;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArgumentsTest extends TestCase
{
    public function testDefaultsAreDemoOnly(): void
    {
        $arguments = Arguments::parse(['--demo']);
        self::assertFalse($arguments->help);
        self::assertSame('config/demo.json', $arguments->config);
        self::assertSame('fixtures/demo.json', $arguments->fixture);
        self::assertNull($arguments->output);
    }

    public function testPathsAcceptEqualsAndSpaceFormsWithoutShellInterpretation(): void
    {
        $arguments = Arguments::parse(['--demo', '--config=my config.json', '--fixture', 'my fixture.json', '--output=dist/a;$name.png']);
        self::assertSame('my config.json', $arguments->config);
        self::assertSame('my fixture.json', $arguments->fixture);
        self::assertSame('dist/a;$name.png', $arguments->output);
    }

    public function testLiveFlagParses(): void
    {
        $arguments = Arguments::parse(['--live']);
        self::assertTrue($arguments->live);
        self::assertSame('config/local.json', $arguments->config);
        self::assertFalse(Arguments::parse(['--demo'])->live);
    }

    public function testDemoAndLiveAreExclusive(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Choose either --demo or --live, not both.'));
        Arguments::parse(['--demo', '--live']);
    }

    public function testNeitherModeFails(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Pass --demo (simulated data) or --live (GitHub data). Use --help for usage.'));
        Arguments::parse([]);
    }

    public function testFixtureRejectedInLiveMode(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('--fixture only applies to --demo.'));
        Arguments::parse(['--live', '--fixture=x.json']);
    }

    public function testLiveDoesNotTakeValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Arguments::parse(['--live=true']);
    }

    public function testHelpDoesNotNeedDemoFlag(): void
    {
        self::assertTrue(Arguments::parse(['--help'])->help);
        self::assertTrue(Arguments::parse(['-h'])->help);
    }

    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentsFail(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        Arguments::parse($arguments);
    }

    public static function invalidArguments(): iterable
    {
        yield 'missing demo' => [[]];
        yield 'demo with value' => [['--demo=true']];
        yield 'missing output' => [['--demo', '--output']];
        yield 'empty path' => [['--demo', '--config=']];
        yield 'option used as path' => [['--demo', '--fixture', '--config=x']];
        yield 'duplicate option' => [['--demo', '--demo']];
        yield 'both modes' => [['--demo', '--live']];
        yield 'neither mode' => [[]];
        yield 'fixture with live' => [['--live', '--fixture=x.json']];
        yield 'live with value' => [['--live=true']];
        yield 'unknown argument' => [['--demo', '--username=x']];
    }
}
