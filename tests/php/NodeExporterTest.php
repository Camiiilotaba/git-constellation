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

    public function testMissingAnimatedSvgFailsTheExport(): void
    {
        $root = sys_get_temp_dir() . '/constellation-exporter-' . bin2hex(random_bytes(8));
        mkdir($root . '/build/renderer', 0775, true);
        $output = $root . '/out/image.png';
        file_put_contents(
            $root . '/build/renderer/export.js',
            "const fs = require('node:fs');\nconst out = process.argv[process.argv.indexOf('--output') + 1];\nfs.writeFileSync(out, Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), Buffer.from('png')]));\n",
        );

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('did not produce the animated SVG');
            (new NodeExporter())->export($root, 'input.json', $output);
        } finally {
            @unlink($output);
            @rmdir($root . '/out');
            @unlink($root . '/build/renderer/export.js');
            @rmdir($root . '/build/renderer');
            @rmdir($root . '/build');
            @rmdir($root);
        }
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
