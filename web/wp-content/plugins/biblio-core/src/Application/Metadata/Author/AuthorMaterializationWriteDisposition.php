<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

enum AuthorMaterializationWriteDisposition: string
{
    case Created = "created";
    case Reused = "reused";
    case NotWritten = "not_written";
}
