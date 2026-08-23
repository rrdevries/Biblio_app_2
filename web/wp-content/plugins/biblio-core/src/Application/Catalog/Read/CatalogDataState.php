<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

enum CatalogDataState: string
{
    case Known = "known";
    case Missing = "missing";
    case NotApplicable = "not_applicable";
    case Unknown = "unknown";
}
