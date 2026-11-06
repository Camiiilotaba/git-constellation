<?php

declare(strict_types=1);

namespace GitConstellation\GitHub;

enum GitHubErrorKind: string
{
    case Auth = 'auth';
    case RateLimit = 'rateLimit';
    case NotFound = 'notFound';
    case Transport = 'transport';
    case Response = 'response';
}
