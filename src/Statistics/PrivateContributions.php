<?php

declare(strict_types=1);

namespace GitConstellation\Statistics;

use GitConstellation\Support\IntegerRange;
use InvalidArgumentException;

enum PrivateContributions: string
{
    case Included = 'included';
    case Excluded = 'excluded';
    case Unknown = 'unknown';

    /** Private activity is never inferred from arithmetic alone; only an explicit restricted signal proves inclusion. */
    public static function determine(
        int $calendarTotal,
        VisibleTotals $visible,
        int $restrictedContributions,
        bool $hasAnyRestricted,
        bool $ownAccount,
    ): self {
        IntegerRange::nonNegative($calendarTotal, 'calendarTotal');
        IntegerRange::nonNegative($restrictedContributions, 'restrictedContributions');

        // For the token owner's own account GitHub folds private activity into the totals and keeps the restricted count at 0.
        if ($restrictedContributions > 0 || $hasAnyRestricted || $ownAccount) {
            return self::Included;
        }

        return $calendarTotal === $visible->sum() ? self::Excluded : self::Unknown;
    }

    /**
     * Included when any part gives an explicit signal; excluded only when every part is exact.
     *
     * @param list<self> $parts
     */
    public static function combine(array $parts): self
    {
        if ($parts === []) {
            throw new InvalidArgumentException('At least one private contributions state is required.');
        }
        if (in_array(self::Included, $parts, true)) {
            return self::Included;
        }

        return in_array(self::Unknown, $parts, true) ? self::Unknown : self::Excluded;
    }
}
