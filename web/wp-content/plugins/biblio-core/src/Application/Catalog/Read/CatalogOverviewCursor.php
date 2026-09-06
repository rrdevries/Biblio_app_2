<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Exception\ValidationException;

final readonly class CatalogOverviewCursor
{
    public function __construct(
        private string $editionTitle,
        private ItemId $itemId
    ) {
        $length = preg_match_all('/./us', $editionTitle);

        if ($length === false || trim($editionTitle) === "") {
            throw new ValidationException("Catalog cursor title must not be empty.");
        }

        if ($length > Edition::MAX_TITLE_LENGTH) {
            throw new ValidationException("Catalog cursor title is too long.");
        }
    }

    public function editionTitle(): string { return $this->editionTitle; }
    public function itemId(): ItemId { return $this->itemId; }
}
