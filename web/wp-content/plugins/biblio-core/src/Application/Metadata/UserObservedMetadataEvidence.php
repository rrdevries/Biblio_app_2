<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;
use DateTimeZone;

final readonly class UserObservedMetadataEvidence
{
    private DateTimeImmutable $observedAt;

    public function __construct(
        private MetadataRecordId $recordId,
        private EditionId $editionId,
        private ItemId $itemId,
        private LibraryId $libraryId,
        private UserId $actorId,
        private UserObservedMetadataField $field,
        private MetadataFieldValue $value,
        DateTimeImmutable $observedAt,
        private MetadataObservationSource $source,
        private bool $correctionProposal
    ) {
        $this->observedAt = $observedAt->setTimezone(new DateTimeZone("UTC"));
    }

    public function id(): string
    {
        return hash("sha256", implode("\0", [
            $this->recordId->value(),
            $this->editionId->value(),
            $this->itemId->value(),
            $this->libraryId->value(),
            $this->actorId->value(),
            $this->field->value,
            $this->value->hash(),
            $this->observedAt->format("Y-m-d H:i:s.u"),
            $this->source->value,
        ]));
    }

    public function recordId(): MetadataRecordId { return $this->recordId; }
    public function editionId(): EditionId { return $this->editionId; }
    public function itemId(): ItemId { return $this->itemId; }
    public function libraryId(): LibraryId { return $this->libraryId; }
    public function actorId(): UserId { return $this->actorId; }
    public function field(): UserObservedMetadataField { return $this->field; }
    public function value(): MetadataFieldValue { return $this->value; }
    public function observedAt(): DateTimeImmutable { return $this->observedAt; }
    public function source(): MetadataObservationSource { return $this->source; }
    public function isCorrectionProposal(): bool { return $this->correctionProposal; }
}
