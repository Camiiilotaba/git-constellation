<?php

declare(strict_types=1);

namespace GitConstellation\Cli;

use DateTimeImmutable;
use DateTimeZone;
use GitConstellation\Config\Configuration;
use GitConstellation\Config\Environment;
use GitConstellation\Data\DemoFixture;
use GitConstellation\Data\Period;
use GitConstellation\Data\RendererData;
use GitConstellation\GitHub\ContributionsQuery;
use GitConstellation\GitHub\GitHubApiException;
use GitConstellation\GitHub\GitHubErrorKind;
use GitConstellation\GitHub\GraphQlClient;
use GitConstellation\GitHub\LiveStatistics;
use GitConstellation\GitHub\RepositoriesQuery;
use GitConstellation\Render\NodeExporter;
use GitConstellation\Support\JsonFile;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Application
{
    private const string LIVE_DEFAULT_OUTPUT = 'dist/live.png';

    /** @param array<string, string|false>|null $environment */
    public function __construct(
        private readonly string $projectRoot,
        private readonly ?HttpClientInterface $http = null,
        private readonly ?DateTimeImmutable $now = null,
        private readonly ?array $environment = null,
    ) {}

    /** @param list<string> $arguments */
    public function run(array $arguments): int
    {
        try {
            $options = Arguments::parse($arguments);
            if ($options->help) {
                fwrite(STDOUT, <<<'HELP'
Git Constellation — contribution constellation image generator

Usage:
  php bin/constellation --demo [--config=config/demo.json]
      [--fixture=fixtures/demo.json] [--output=dist/constellation.png]
  php bin/constellation --live [--config=config/local.json] [--output=dist/live.png]
  php bin/constellation --help

--demo  Simulated statistics from a fixed fixture; no API calls, no credentials.
--live  Real GitHub statistics for the configured username over the trailing year.
        Copy config/live.example.json to config/local.json and edit it first.
        Needs GITHUB_TOKEN (environment or .env) with the read:user scope only.
        Any fetch failure exits 1 and writes nothing; there is no fallback to demo data.
        Default image: dist/live.png (dist/constellation.png stays the demo image).

Relative paths are resolved from the project directory. Absolute paths are supported.
PHP prepares build/data.json, then invokes the Node renderer.
Run composer install, npm install, npm run build, and npx playwright install chromium first.

HELP);
                return 0;
            }
            $config = Configuration::fromArray(
                $this->readConfig($options->config, $options->live),
                $options->output,
                $options->live ? self::LIVE_DEFAULT_OUTPUT : 'dist/constellation.png',
            );
            $input = $this->projectRoot . '/build/data.json';
            $output = $this->path($config->output);
            $now = $this->now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $secret = null;
            if ($options->live) {
                $this->assertNotDemoImage($output);
                $secret = $this->prepareLive($config, $now, $input);
            } else {
                $fixture = DemoFixture::fromArray(JsonFile::read($this->path($options->fixture)));
                JsonFile::write($input, RendererData::fromDemo($config, $fixture, $now));
                fwrite(STDOUT, "DEMO — simulated statistics only. Prepared {$input}\n");
            }
            (new NodeExporter())->export($this->projectRoot, $input, $output, $secret);
            fwrite(STDOUT, ($options->live ? 'Generated live image: ' : 'Generated demo image: ') . $output . "\n");

            return 0;
        } catch (InvalidArgumentException | RuntimeException $exception) {
            fwrite(STDERR, 'Error: ' . $exception->getMessage() . PHP_EOL);
            return 1;
        }
    }

    /** @return string the token, so the renderer's environment can be scrubbed of it */
    private function prepareLive(Configuration $config, DateTimeImmutable $now, string $input): string
    {
        $environment = Environment::load($this->projectRoot, $this->environment ?? getenv());
        $period = Period::trailingYear($now);
        $client = new GraphQlClient($this->http ?? HttpClient::create(), $environment->githubToken);
        $statistics = new LiveStatistics($client, new ContributionsQuery($client), new RepositoriesQuery($client));

        // Nothing is written until the whole fetch succeeded, so a failed run never leaves a half-valid build/data.json.
        try {
            $snapshot = $statistics->collect($config->username, $period, $now);
        } catch (GitHubApiException $exception) {
            if ($exception->kind !== GitHubErrorKind::Auth) {
                throw $exception;
            }
            throw new GitHubApiException($exception->kind, $exception->getMessage() . " (token source: {$environment->source})", $exception);
        }
        JsonFile::write($input, RendererData::fromLive($config, $snapshot));
        fwrite(STDOUT, Summary::format($snapshot, $period));

        return $environment->githubToken;
    }

    /** @return array<string, mixed> */
    private function readConfig(string $path, bool $live): array
    {
        try {
            return JsonFile::read($this->path($path));
        } catch (RuntimeException $exception) {
            if (!$live || is_file($this->path($path))) {
                throw $exception;
            }
            throw new RuntimeException($exception->getMessage() . ' Copy config/live.example.json to config/local.json and fill in your login.', 0, $exception);
        }
    }

    private function assertNotDemoImage(string $output): void
    {
        if ($this->normalize($output) === $this->normalize($this->projectRoot . '/dist/constellation.png')) {
            throw new RuntimeException('Live mode refuses to overwrite dist/constellation.png, the committed demo image. Use --output or set "output" in the config.');
        }
    }

    private function normalize(string $path): string
    {
        $segments = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    private function path(string $path): string
    {
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            return $path;
        }

        return $this->projectRoot . '/' . $path;
    }
}
