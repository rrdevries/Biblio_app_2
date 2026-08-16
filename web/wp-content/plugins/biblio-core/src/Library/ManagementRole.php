<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

enum ManagementRole: string
{
    case Owner = "owner";
    case Manager = "manager";
    case Member = "member";
}
