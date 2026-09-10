<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

interface ItemArchiveReasonValue
{
    public function kind(): ItemArchiveReasonKind;

    public function equals(self $other): bool;
}
