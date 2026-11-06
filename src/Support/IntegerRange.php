<?php

declare(strict_types=1);

namespace GitConstellation\Support;

use InvalidArgumentException;

/** The JSON contract crosses into JavaScript, so PHP's larger integer range is not safe. */
final class IntegerRange
{
    public const int MAX_SAFE = 9_007_199_254_740_991;

    public static function nonNegative(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 0 || $value > self::MAX_SAFE) {
            throw new InvalidArgumentException("{$field} must be a non-negative JavaScript-safe integer (maximum " . self::MAX_SAFE . ').');
        }

        return $value;
    }
}
