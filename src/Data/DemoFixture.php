<?php

declare(strict_types=1);

namespace GitConstellation\Data;

use GitConstellation\Statistics\LanguageRanking;
use GitConstellation\Support\IntegerRange;
use InvalidArgumentException;

final readonly class DemoFixture
{
    /** @param list<array{name: string, repositories: int, color: string}> $languages */
    private function __construct(
        public ContributionCalendar $calendar,
        public ?int $pullRequests,
        public ?int $codeReviews,
        public array $languages,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (!is_array($data['period'] ?? null) || !is_array($data['days'] ?? null)
            || !is_array($data['repositories'] ?? null)) {
            throw new InvalidArgumentException('Demo fixture requires period, days, and repositories.');
        }
        if (($data['mode'] ?? null) !== 'demo') {
            throw new InvalidArgumentException('Phase 1 fixtures must explicitly declare mode: demo.');
        }
        if (($data['privateContributionsIncluded'] ?? false) !== false) {
            throw new InvalidArgumentException('Demo fixtures cannot claim private contributions are included.');
        }

        return new self(
            ContributionCalendar::fromArray($data['period'], $data['days']),
            self::optionalMetric($data['pullRequests'] ?? null, 'pullRequests'),
            self::optionalMetric($data['codeReviews'] ?? null, 'codeReviews'),
            LanguageRanking::calculate($data['repositories']),
        );
    }

    private static function optionalMetric(mixed $value, string $field): ?int
    {
        return $value === null ? null : IntegerRange::nonNegative($value, $field);
    }
}
