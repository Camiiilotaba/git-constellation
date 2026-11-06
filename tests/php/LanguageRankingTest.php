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

    /** @param list<string> $names @return array<string, mixed> */
    private function repository(array $names): array
    {
        return ['visibility' => 'PUBLIC', 'isFork' => false, 'isArchived' => false,
            'languages' => array_map(static fn (string $name): array => ['name' => $name, 'color' => '#abc123'], $names)];
    }
}
