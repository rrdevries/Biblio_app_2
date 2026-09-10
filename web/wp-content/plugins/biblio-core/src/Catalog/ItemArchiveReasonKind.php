<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

enum ItemArchiveReasonKind: string
{
    case Native = "native";
    case PreservedHistorical = "preserved_historical";
}
