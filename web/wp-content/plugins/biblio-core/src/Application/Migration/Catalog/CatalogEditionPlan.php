<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Catalog;

use Biblio\Core\Application\Migration\Runner\TypedMigrationPlan;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\EditionIsbnMetadata;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;

final readonly class CatalogEditionPlan implements TypedMigrationPlan
{
    public function __construct(
        private string $workSourceId,
        private string $title,
        private EditionIsbnMetadata $isbnMetadata,
        private ?EditionId $approvedExistingEditionId = null
    ) {
        if (trim($this->workSourceId) === "" || mb_strlen($this->workSourceId) > 191) {
            throw new ValidationException("Work source reference is invalid.");
        }
        new Edition(
            new EditionId("edition-plan-validation"),
            new WorkId("work-plan-validation"),
            $this->title,
            $this->isbnMetadata
        );
        if (
            CanonicalIsbnIdentity::fromMetadata($this->isbnMetadata) === null
            && !$this->isbnMetadata->isExplicitlyWithoutIsbn()
        ) {
            throw new ValidationException(
                "Catalog migration Edition requires canonical ISBN or explicit no-ISBN."
            );
        }
    }

    public function workSourceId(): string { return $this->workSourceId; }
    public function title(): string { return $this->title; }
    public function isbnMetadata(): EditionIsbnMetadata { return $this->isbnMetadata; }
    public function approvedExistingEditionId(): ?EditionId
    {
        return $this->approvedExistingEditionId;
    }

    public function canonicalPayload(): array
    {
        $identity = CanonicalIsbnIdentity::fromMetadata($this->isbnMetadata);
        return [
            "target_kind" => "catalog_edition",
            "work_source_id" => $this->workSourceId,
            "title" => $this->title,
            "isbn_state" => $identity === null ? "without_isbn" : "canonical",
            "isbn_10" => $identity?->isbn10()?->value(),
            "isbn_13" => $identity?->isbn13()->value(),
            "approved_existing_edition_id" =>
                $this->approvedExistingEditionId?->value(),
        ];
    }
}
