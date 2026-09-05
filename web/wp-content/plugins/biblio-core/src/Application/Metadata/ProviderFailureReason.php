<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

enum ProviderFailureReason: string
{
    case Timeout = "timeout";
    case Network = "network";
    case RateLimited = "rate_limited";
    case Configuration = "configuration";
    case HttpError = "http_error";
    case Http5xx = "http_5xx";
    case Malformed = "malformed";
    case IsbnMismatch = "isbn_mismatch";
}
