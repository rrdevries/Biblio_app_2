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
        private ?EditionId $approvedExistingEditionId = null,
        private ?string $aliasOfSourceId = null
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
            $this->aliasOfSourceId !== null
            && (
                trim($this->aliasOfSourceId) === ""
                || mb_strlen($this->aliasOfSourceId) > 191
            )
        ) {
            throw new ValidationException("Edition alias source reference is invalid.");
        }
        if ($this->approvedExistingEditionId !== null && $this->aliasOfSourceId !== null) {
            throw new ValidationException(
                "Edition plan cannot combine an approved target with a source alias."
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
    public function aliasOfSourceId(): ?string { return $this->aliasOfSourceId; }

    public function canonicalPayload(): array
    {
        $identity = CanonicalIsbnIdentity::fromMetadata($this->isbnMetadata);
        $isbnState = $identity !== null
            ? "canonical"
            : ($this->isbnMetadata->isExplicitlyWithoutIsbn()
                ? "without_isbn"
                : "unknown");
        return [
            "target_kind" => "catalog_edition",
            "work_source_id" => $this->workSourceId,
            "title" => $this->title,
            "isbn_state" => $isbnState,
            "isbn_10" => $identity?->isbn10()?->value(),
            "isbn_13" => $identity?->isbn13()->value(),
            "approved_existing_edition_id" =>
                $this->approvedExistingEditionId?->value(),
            ...($this->aliasOfSourceId === null ? [] : [
                "alias_of_source_id" => $this->aliasOfSourceId,
            ]),
        ];
    }
}
