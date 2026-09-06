<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

enum WorkTitleStatus: string
{
    case Provisional = "provisional";
    case LibrarianConfirmed = "librarian_confirmed";
}
