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

    public function testChildEnvironmentStripsSecrets(): void
    {
        $environment = ['PATH' => '/usr/bin', 'GITHUB_TOKEN' => 'x', 'MY_SECRET' => 'y', 'DB_PASSWORD' => 'z', 'HOME' => '/h'];

        self::assertSame(['PATH' => '/usr/bin', 'HOME' => '/h'], NodeExporter::childEnvironment($environment));
    }
}
