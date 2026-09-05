<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

enum IsbnInputError: string
{
    case Empty = "empty";
    case InvalidCharacters = "invalid_characters";
    case InvalidLength = "invalid_length";
    case InvalidChecksum = "invalid_checksum";
    case UnsupportedPrefix = "unsupported_prefix";
}
