<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Application\Assessments\Read\PublicAssessmentPage;
use Biblio\Core\Application\Assessments\Read\OwnAssessmentView;
use Biblio\Core\Application\Library\LibraryContextView;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemStatus;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Catalog\Classification\LibraryCatalogClassification;

final readonly class CatalogItemDetailView
{
    public function __construct(
        private LibraryContextView $library,
        private ItemId $itemId,
        private WorkId $workId,
        private EditionId $editionId,
        private string $title,
        private CatalogTextListValue $authors,
        private CatalogTextValue $coverReference,
        private CatalogTextValue $isbn,
        private CatalogTextValue $language,
        private CatalogTextValue $publisher,
        private CatalogTextValue $publicationDate,
        private CatalogTextValue $series,
        private CatalogTextValue $form,
        private CatalogTextValue $location,
        private CatalogTextValue $condition,
        private CatalogTextValue $acquisition,
        private CatalogTextValue $availability,
        private ?LibraryCatalogClassification $classification,
        /** @var list<CatalogItemCollectionView> */
        private array $collections,
        private PublicAssessmentPage $assessments,
        /** @var list<OwnAssessmentView> */
        private array $ownAssessments,
        private ItemStatus $itemStatus,
        private CatalogReadingSummary $reading,
        private ?CatalogActiveReadingRoundView $activeReadingRound,
        private CatalogItemCapabilities $capabilities
    ) {
    }

    public function library(): LibraryContextView { return $this->library; }
    public function itemId(): ItemId { return $this->itemId; }
    public function workId(): WorkId { return $this->workId; }
    public function editionId(): EditionId { return $this->editionId; }
    public function title(): string { return $this->title; }
    public function authors(): CatalogTextListValue { return $this->authors; }
    public function coverReference(): CatalogTextValue { return $this->coverReference; }
    public function isbn(): CatalogTextValue { return $this->isbn; }
    public function language(): CatalogTextValue { return $this->language; }
    public function publisher(): CatalogTextValue { return $this->publisher; }
    public function publicationDate(): CatalogTextValue { return $this->publicationDate; }
    public function series(): CatalogTextValue { return $this->series; }
    public function form(): CatalogTextValue { return $this->form; }
    public function location(): CatalogTextValue { return $this->location; }
    public function condition(): CatalogTextValue { return $this->condition; }
    public function acquisition(): CatalogTextValue { return $this->acquisition; }
    public function availability(): CatalogTextValue { return $this->availability; }
    public function classification(): ?LibraryCatalogClassification
    {
        return $this->classification;
    }
    /** @return list<CatalogItemCollectionView> */
    public function collections(): array { return $this->collections; }
    public function assessments(): PublicAssessmentPage { return $this->assessments; }
    /** @return list<OwnAssessmentView> */
    public function ownAssessments(): array { return $this->ownAssessments; }
    public function itemStatus(): ItemStatus { return $this->itemStatus; }
    public function reading(): CatalogReadingSummary { return $this->reading; }
    public function activeReadingRound(): ?CatalogActiveReadingRoundView
    {
        return $this->activeReadingRound;
    }
    public function capabilities(): CatalogItemCapabilities { return $this->capabilities; }
}
