<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemStatus;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Reading\PersonalWorkReadingStatus;

final readonly class CatalogItemCardView
{
    public function __construct(
        private ItemId $itemId,
        private WorkId $workId,
        private EditionId $editionId,
        private string $title,
        private CatalogTextListValue $authors,
        private CatalogTextValue $coverReference,
        private CatalogTextValue $form,
        private CatalogTextValue $locationOrSource,
        private PersonalWorkReadingStatus $readingStatus,
        private ItemStatus $itemStatus,
        private CatalogItemCapabilities $capabilities
    ) {
    }

    public function itemId(): ItemId { return $this->itemId; }
    public function workId(): WorkId { return $this->workId; }
    public function editionId(): EditionId { return $this->editionId; }
    public function title(): string { return $this->title; }
    public function authors(): CatalogTextListValue { return $this->authors; }
    public function coverReference(): CatalogTextValue { return $this->coverReference; }
    public function form(): CatalogTextValue { return $this->form; }
    public function locationOrSource(): CatalogTextValue { return $this->locationOrSource; }
    public function readingStatus(): PersonalWorkReadingStatus { return $this->readingStatus; }
    public function itemStatus(): ItemStatus { return $this->itemStatus; }
    public function capabilities(): CatalogItemCapabilities { return $this->capabilities; }
}
