<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

enum AcquisitionMethod: string
{
    case Purchased = "zelf_aangeschaft";
    case Received = "gekregen";
    case Other = "anders";
}
