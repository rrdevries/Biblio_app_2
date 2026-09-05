<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Query;

use Biblio\Core\Exception\ValidationException;

final readonly class CatalogQueryCursor
{
    public function __construct(private string $opaqueValue)
    {
        if ($opaqueValue === '' || strlen($opaqueValue) > 2048) {
            throw new ValidationException('Catalog query cursor is invalid.');
        }
    }

    public function opaqueValue(): string { return $this->opaqueValue; }
}
