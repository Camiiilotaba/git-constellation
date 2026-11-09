<?php

declare(strict_types=1);

namespace GitConstellation\Tests;

use GitConstellation\Statistics\LanguageRanking;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LanguageRankingTest extends TestCase
{
    public function testSecondaryLanguagesCountAndForksArchivedAndPrivateRepositoriesAreExcluded(): void
    {
        $ranking = LanguageRanking::calculate([
            $this->repository(['PHP', 'TypeScript', 'TypeScript']),
            $this->repository(['PHP', 'JavaScript']),
            $this->repository(['TypeScript']),
            array_replace($this->repository(['JavaScript']), ['isFork' => true]),
            array_replace($this->repository(['JavaScript']), ['isArchived' => true]),
            array_replace($this->repository(['JavaScript']), ['visibility' => 'PRIVATE']),
        ]);
        self::assertSame(['PHP', 'TypeScript', 'JavaScript'], array_column($ranking, 'name'));
        self::assertSame([2, 2, 1], array_column($ranking, 'repositories'));
    }

    public function testTiesAreAlphabeticalAndOnlyFiveLanguagesAreReturned(): void
    {
        $ranking = LanguageRanking::calculate([$this->repository(['Zig', 'Swift', 'Rust', 'PHP', 'Go', 'C'])]);
        self::assertSame(['C', 'Go', 'PHP', 'Rust', 'Swift'], array_column($ranking, 'name'));
    }

    public function testNoRepositoriesOrNoDetectedLanguagesYieldEmptyRanking(): void
    {
        self::assertSame([], LanguageRanking::calculate([]));
        self::assertSame([], LanguageRanking::calculate([$this->repository([])]));
    }

    public function testIncompleteRepositoryMetadataFailsInsteadOfGuessingVisibility(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LanguageRanking::calculate([['languages' => []]]);
    }

    public function testMissingSecondaryLanguageColorIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LanguageRanking::calculate([array_replace($this->repository([]), ['languages' => [['name' => 'PHP']]])]);
    }

    public function testNonProgrammingLanguagesAreExcludedCaseInsensitively(): void
    {
        $ranking = LanguageRanking::calculate([$this->repository(['PHP', 'CSS', 'html', 'Markdown', 'Dockerfile'])]);
        self::assertSame(['PHP'], array_column($ranking, 'name'));
    }

    public function testEmptyExclusionListKeepsEverything(): void
    {
        $ranking = LanguageRanking::calculate([$this->repository(['PHP', 'CSS'])], []);
        self::assertSame(['CSS', 'PHP'], array_column($ranking, 'name'));
    }

    public function testCustomExclusionListReplacesTheDefault(): void
    {
        $ranking = LanguageRanking::calculate([$this->repository(['PHP', 'CSS'])], ['php']);
        self::assertSame(['CSS'], array_column($ranking, 'name'));
    }

    public function testEqualRepositoryCountsAreOrderedByTotalCodeSize(): void
    {
        $ranking = LanguageRanking::calculate([
            $this->sized(['JavaScript' => 40_000, 'PHP' => 100_000, 'TypeScript' => 200_000, 'Go' => 500]),
            $this->sized(['JavaScript' => 1_000, 'PHP' => 12_000, 'TypeScript' => 55_000]),
            $this->sized(['Rust' => 900_000]),
        ]);
        self::assertSame(['TypeScript', 'PHP', 'JavaScript', 'Rust', 'Go'], array_column($ranking, 'name'));
        self::assertSame([2, 2, 2, 1, 1], array_column($ranking, 'repositories'));
        self::assertSame(['name', 'repositories', 'color'], array_keys($ranking[0]));
    }

    public function testRepositoryCountStillOutranksSize(): void
    {
        $ranking = LanguageRanking::calculate([
            $this->sized(['Small' => 1, 'Huge' => 9_000_000]),
            $this->sized(['Small' => 1]),
        ]);
        self::assertSame(['Small', 'Huge'], array_column($ranking, 'name'));
    }

    public function testNegativeSizeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LanguageRanking::calculate([$this->sized(['PHP' => -1])]);
    }

    public function testManualLanguagesResolveColorsFromReportedThenGivenThenFallback(): void
    {
        $repositories = [$this->repository(['PHP', 'CSS'])];
        $manual = LanguageRanking::manual([
            ['name' => 'php', 'color' => '#111111'],
            ['name' => 'Ada', 'color' => '#222222'],
            ['name' => 'css', 'color' => null],
            ['name' => 'Zig', 'color' => null],
        ], $repositories);
        self::assertSame([
            ['name' => 'php', 'repositories' => null, 'color' => '#abc123'],
            ['name' => 'Ada', 'repositories' => null, 'color' => '#222222'],
            ['name' => 'css', 'repositories' => null, 'color' => '#abc123'],
            ['name' => 'Zig', 'repositories' => null, 'color' => '#8b949e'],
        ], $manual);
    }

    /** @param array<string, int> $sizes @return array<string, mixed> */
    private function sized(array $sizes): array
    {
        $languages = [];
        foreach ($sizes as $name => $size) {
            $languages[] = ['name' => (string) $name, 'color' => '#abc123', 'size' => $size];
        }

        return ['visibility' => 'PUBLIC', 'isFork' => false, 'isArchived' => false, 'languages' => $languages];
    }

    /** @param list<string> $names @return array<string, mixed> */
    private function repository(array $names): array
    {
        return ['visibility' => 'PUBLIC', 'isFork' => false, 'isArchived' => false,
            'languages' => array_map(static fn (string $name): array => ['name' => $name, 'color' => '#abc123'], $names)];
    }
}
