<?php

declare(strict_types=1);

namespace GitConstellation\Data;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/** An inclusive range of UTC calendar days. */
final readonly class Period
{
    private function __construct(
        public string $start,
        public string $end,
    ) {}

    public static function trailingYear(DateTimeImmutable $now): self
    {
        $utc = new DateTimeZone('UTC');
        $end = new DateTimeImmutable($now->setTimezone($utc)->format('Y-m-d'), $utc);
        // sub('P1Y') on Feb 29 overflows to Mar 1, so a period ending on Feb 29 has 365 days; a period that ends after Feb 29 and starts before it has 366.
        $start = $end->sub(new DateInterval('P1Y'))->add(new DateInterval('P1D'));

        return new self($start->format('Y-m-d'), $end->format('Y-m-d'));
    }

    public function fromDateTime(): string
    {
        return "{$this->start}T00:00:00Z";
    }

    public function toDateTime(): string
    {
        return "{$this->end}T23:59:59Z";
    }

    public function contains(string $date): bool
    {
        return $date >= $this->start && $date <= $this->end;
    }

    public function dayCount(): int
    {
        $utc = new DateTimeZone('UTC');

        return (new DateTimeImmutable($this->start, $utc))
            ->diff(new DateTimeImmutable($this->end, $utc))
            ->days + 1;
    }
}
