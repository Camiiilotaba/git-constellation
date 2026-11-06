<?php

declare(strict_types=1);

namespace GitConstellation\Tests;

use DateTimeImmutable;
use GitConstellation\Config\Configuration;
use GitConstellation\Data\DemoFixture;
use GitConstellation\Data\RendererData;
use GitConstellation\Support\IntegerRange;
use GitConstellation\Support\JsonFile;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RendererDataTest extends TestCase
{
    public function testCommittedFixtureProducesCompleteVersionTwoContract(): void
    {
        $root = dirname(__DIR__, 2);
        $config = Configuration::fromArray(JsonFile::read($root . '/config/demo.json'));
        $data = RendererData::fromDemo(
            $config,
            DemoFixture::fromArray(JsonFile::read($root . '/fixtures/demo.json')),
            new DateTimeImmutable('2026-11-06T12:00:00Z'),
        );

        self::assertSame(['schemaVersion', 'mode', 'generatedAt', 'profile', 'theme', 'period', 'days', 'metrics', 'languages', 'privateContributions', 'source'], array_keys($data));
        self::assertSame(2, $data['schemaVersion']);
        self::assertSame('demo', $data['mode']);
        self::assertSame('2026-11-06T12:00:00Z', $data['generatedAt']);
        self::assertSame('excluded', $data['privateContributions']);
        self::assertNull($data['source']);
        self::assertSame(['username' => 'demo-developer', 'name' => 'Alex Morgan', 'role' => 'Backend Developer', 'stack' => 'PHP / TypeScript'], $data['profile']);
        self::assertSame(['cyan' => '#30e8ef', 'green' => '#52f5a0'], $data['theme']);
        self::assertSame(['start' => '2025-11-01', 'end' => '2026-10-31'], $data['period']);
        self::assertCount(365, $data['days']);
        self::assertSame(['totalContributions' => 1351, 'activeDays' => 327, 'longestStreak' => 20, 'pullRequests' => 136, 'codeReviews' => 84], $data['metrics']);
        self::assertSame(['PHP', 'JavaScript', 'TypeScript', 'Python', 'SQL'], array_column($data['languages'], 'name'));
        self::assertSame([8, 6, 4, 3, 2], array_column($data['languages'], 'repositories'));
        self::assertArrayNotHasKey('repositories', $data);
        self::assertArrayNotHasKey('provenance', $data);
    }

    public function testGeneratedAtIsNormalizedToUtc(): void
    {
        $root = dirname(__DIR__, 2);
        $data = RendererData::fromDemo(
            Configuration::fromArray(JsonFile::read($root . '/config/demo.json')),
            DemoFixture::fromArray(JsonFile::read($root . '/fixtures/demo.json')),
            new DateTimeImmutable('2026-11-06T08:00:00-05:00'),
        );

        self::assertSame('2026-11-06T13:00:00Z', $data['generatedAt']);
    }

    public function testUnknownOptionalMetricsRemainNullAndSafeLimitIsAccepted(): void
    {
        $input = $this->minimalFixture();
        $fixture = DemoFixture::fromArray($input);
        self::assertNull($fixture->pullRequests);
        self::assertNull($fixture->codeReviews);
        $input['pullRequests'] = $input['codeReviews'] = IntegerRange::MAX_SAFE;
        $fixture = DemoFixture::fromArray($input);
        self::assertSame(IntegerRange::MAX_SAFE, $fixture->pullRequests);
        self::assertSame(IntegerRange::MAX_SAFE, $fixture->codeReviews);
    }

    #[DataProvider('invalidOptionalMetrics')]
    public function testUnsafeOptionalMetricsAreRejected(string $field, mixed $value): void
    {
        $input = $this->minimalFixture();
        $input[$field] = $value;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($field);
        DemoFixture::fromArray($input);
    }

    public static function invalidOptionalMetrics(): iterable
    {
        foreach (['pullRequests', 'codeReviews'] as $field) {
            yield "{$field} negative" => [$field, -1];
            yield "{$field} unsafe integer" => [$field, IntegerRange::MAX_SAFE + 1];
            yield "{$field} fraction" => [$field, 1.5];
        }
    }

    public function testDemoCannotClaimPrivateContributions(): void
    {
        $fixture = $this->minimalFixture();
        $fixture['privateContributionsIncluded'] = true;
        $this->expectException(InvalidArgumentException::class);
        DemoFixture::fromArray($fixture);
    }

    public function testFixtureMustDeclareSimulation(): void
    {
        $fixture = $this->minimalFixture();
        unset($fixture['mode']);
        $this->expectException(InvalidArgumentException::class);
        DemoFixture::fromArray($fixture);
    }

    /** @return array<string, mixed> */
    private function minimalFixture(): array
    {
        return ['mode' => 'demo', 'period' => ['start' => '2026-01-01', 'end' => '2026-01-01'],
            'days' => [['date' => '2026-01-01', 'count' => 0]], 'repositories' => []];
    }
}
