<?php

declare(strict_types=1);

namespace GitConstellation\GitHub;

use RuntimeException;
use Throwable;

final class GitHubApiException extends RuntimeException
{
    public function __construct(
        public readonly GitHubErrorKind $kind,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
