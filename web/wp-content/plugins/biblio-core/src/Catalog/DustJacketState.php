<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

enum DustJacketState: string
{
    case Present = "present";
    case Missing = "missing";
    case NotApplicable = "not_applicable";
}
