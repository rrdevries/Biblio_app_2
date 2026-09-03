<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

interface Isbn
{
    public function value(): string;
    public function type(): IsbnType;
}
