<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Exception\ValidationException;

final readonly class CatalogOverviewCursor
{
    public function __construct(
        private string $workTitle,
        private ItemId $itemId
    ) {
        $length = preg_match_all('/./us', $workTitle);

        if ($length === false || trim($workTitle) === "") {
            throw new ValidationException("Catalog cursor title must not be empty.");
        }

        if ($length > Work::MAX_TITLE_LENGTH) {
            throw new ValidationException("Catalog cursor title is too long.");
        }
    }

    public function workTitle(): string { return $this->workTitle; }
    public function itemId(): ItemId { return $this->itemId; }
}
