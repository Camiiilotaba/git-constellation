<?php

declare(strict_types=1);

namespace GitConstellation\GitHub;

final readonly class RepositoriesResult
{
    /**
     * @param list<array{visibility: string, isFork: bool, isArchived: bool, languages: list<array{name: string, color: string}>}> $repositories
     * @param int $scanned Every repository node GitHub returned, before LanguageRanking filters any out.
     */
    public function __construct(public array $repositories, public int $scanned) {}
}
