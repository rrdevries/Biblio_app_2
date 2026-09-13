<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

enum AuthorDisplayNameStatus: string
{
    case Observed = "observed";
    case LibrarianConfirmed = "librarian_confirmed";
}
