<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

enum MetadataObservationSource: string
{
    case PhysicalCopyAddBook = "physical_copy_add_book";
}
