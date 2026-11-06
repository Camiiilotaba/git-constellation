<?php

declare(strict_types=1);

namespace GitConstellation\Statistics;

use GitConstellation\Support\IntegerRange;

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
}
