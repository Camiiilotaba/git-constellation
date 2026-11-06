<?php

declare(strict_types=1);

namespace GitConstellation\Statistics;

use GitConstellation\Support\IntegerRange;

final readonly class VisibleTotals
{
    public function __construct(
        public int $commits,
        public int $issues,
        public int $pullRequests,
        public int $reviews,
        public int $repositories,
    ) {
        foreach ($this->toArray() as $field => $value) {
            IntegerRange::nonNegative($value, $field);
        }
    }

    public function sum(): int
    {
        return $this->commits + $this->issues + $this->pullRequests + $this->reviews + $this->repositories;
    }

    /** @return array{commits: int, issues: int, pullRequests: int, reviews: int, repositories: int} */
    public function toArray(): array
    {
        return [
            'commits' => $this->commits,
            'issues' => $this->issues,
            'pullRequests' => $this->pullRequests,
            'reviews' => $this->reviews,
            'repositories' => $this->repositories,
        ];
    }
}
