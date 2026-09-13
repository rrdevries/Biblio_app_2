<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

enum AuthorCreditProviderSourceType: string
{
    case Work = "work";
    case Edition = "edition";
}
