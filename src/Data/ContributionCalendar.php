<?php

declare(strict_types=1);

namespace GitConstellation\Data;

use DateTimeImmutable;
use DateTimeZone;
use GitConstellation\Support\IntegerRange;
use InvalidArgumentException;

/** A complete, sorted, inclusive calendar. Missing days are errors, never fabricated zeros. */
final readonly class ContributionCalendar
{
    /** @param list<array{date: string, count: int}> $days */
    private function __construct(
        public string $start,
        public string $end,
        public array $days,
    ) {}

    /** @param array<string, mixed> $period @param array<mixed> $days */
    public static function fromArray(array $period, array $days): self
    {
        $start = self::date($period['start'] ?? null);
        $end = self::date($period['end'] ?? null);
        if ($end < $start) {
            throw new InvalidArgumentException('period.end must be on or after period.start.');
        }
        if ($start->diff($end)->days > 365) {
            throw new InvalidArgumentException('The contribution period must contain at most 366 days.');
        }
        if (!array_is_list($days)) {
            throw new InvalidArgumentException('days must be a list of daily contributions.');
        }

        $normalized = [];
        foreach ($days as $day) {
            if (!is_array($day)) {
                throw new InvalidArgumentException('Each contribution day must be an object.');
            }
            $date = self::date($day['date'] ?? null);
            $key = $date->format('Y-m-d');
            if ($date < $start || $date > $end) {
                throw new InvalidArgumentException("Contribution day {$key} is outside the configured period.");
            }
            $count = IntegerRange::nonNegative($day['count'] ?? null, "Contribution count for {$key}");
            if (array_key_exists($key, $normalized)) {
                throw new InvalidArgumentException("Duplicate contribution day: {$key}");
            }
            $normalized[$key] = ['date' => $key, 'count' => $count];
        }

        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            if (!isset($normalized[$key])) {
                throw new InvalidArgumentException("Incomplete contribution calendar: missing {$key}.");
            }
        }
        ksort($normalized, SORT_STRING);

        return new self($start->format('Y-m-d'), $end->format('Y-m-d'), array_values($normalized));
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            throw new InvalidArgumentException('Calendar dates must use YYYY-MM-DD.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value || $value < '0001-01-01') {
            throw new InvalidArgumentException("Invalid calendar date: {$value}");
        }

        return $date;
    }
}
