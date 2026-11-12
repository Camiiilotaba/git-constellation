<?php

declare(strict_types=1);

namespace GitConstellation\Tests;

use GitConstellation\Render\NodeExporter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class NodeExporterTest extends TestCase
{
    public function testMissingBuildFailsBeforeInvokingNode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('npm run build');
        (new NodeExporter())->export(sys_get_temp_dir() . '/constellation-no-renderer-' . bin2hex(random_bytes(8)), 'input.json', 'output.png');
    }

    public function testAnimatedPathReplacesOnlyTheTrailingPngExtension(): void
    {
        self::assertSame('/tmp/a.png.dir/live.svg', NodeExporter::animatedPath('/tmp/a.png.dir/live.png'));
        self::assertSame('dist/x.svg', NodeExporter::animatedPath('dist/x.PNG'));
    }

    public function testChildEnvironmentStripsVariablesHoldingTheSecretValue(): void
    {
        $environment = ['PATH' => '/usr/bin', 'GH_PAT' => 'tok_x'];

        self::assertSame(['PATH' => '/usr/bin'], NodeExporter::childEnvironment($environment, 'tok_x'));
    }

    public function testChildEnvironmentStripsSecrets(): void
    {
        $environment = ['PATH' => '/usr/bin', 'GITHUB_TOKEN' => 'x', 'MY_SECRET' => 'y', 'DB_PASSWORD' => 'z', 'HOME' => '/h'];

        self::assertSame(['PATH' => '/usr/bin', 'HOME' => '/h'], NodeExporter::childEnvironment($environment));
    }
}
