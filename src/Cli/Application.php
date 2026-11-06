<?php

declare(strict_types=1);

namespace GitConstellation\Cli;

use DateTimeImmutable;
use DateTimeZone;
use GitConstellation\Config\Configuration;
use GitConstellation\Data\DemoFixture;
use GitConstellation\Data\RendererData;
use GitConstellation\Render\NodeExporter;
use GitConstellation\Support\JsonFile;
use InvalidArgumentException;
use RuntimeException;

final class Application
{
    public function __construct(private readonly string $projectRoot) {}

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            $options = Arguments::parse($arguments);
            if ($options->help) {
                fwrite(STDOUT, <<<'HELP'
Git Constellation — Phase 1 demo generator (simulated data only)

Usage:
  php bin/constellation --demo [--config=config/demo.json]
      [--fixture=fixtures/demo.json] [--output=dist/constellation.png]
  php bin/constellation --help

Relative paths are resolved from the project directory. Absolute paths are supported.
The demo fixture uses a fixed inclusive UTC period; it makes no API calls.
PHP validates the calendar and prepares build/data.json, then invokes the Node renderer.
Run composer install, npm install, npm run build, and npx playwright install chromium first.
No credentials or .env loader are needed. Live mode is reserved for a later phase.

HELP);
                return 0;
            }
            $config = Configuration::fromArray(JsonFile::read($this->path($options->config)), $options->output);
            $fixture = DemoFixture::fromArray(JsonFile::read($this->path($options->fixture)));
            $input = $this->projectRoot . '/build/data.json';
            $output = $this->path($config->output);
            JsonFile::write($input, RendererData::fromDemo($config, $fixture, new DateTimeImmutable('now', new DateTimeZone('UTC'))));
            fwrite(STDOUT, "DEMO — simulated statistics only. Prepared {$input}\n");
            (new NodeExporter())->export($this->projectRoot, $input, $output);
            fwrite(STDOUT, "Generated demo image: {$output}\n");

            return 0;
        } catch (InvalidArgumentException | RuntimeException $exception) {
            fwrite(STDERR, 'Error: ' . $exception->getMessage() . PHP_EOL);
            return 1;
        }
    }

    private function path(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            return $path;
        }

        return $this->projectRoot . '/' . $path;
    }
}
