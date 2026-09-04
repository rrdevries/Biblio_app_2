<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;

final readonly class CollectionMembership
{
    public function __construct(
        private CollectionMembershipId $id,
        private LibraryId $libraryId,
        private CollectionId $collectionId,
        private ItemId $itemId,
        private CollectionMembershipStatus $status,
        private CollectionItemPosition $position,
        private DateTimeImmutable $addedAt,
        private ?DateTimeImmutable $endedAt = null,
        private ?CollectionMembershipEndReason $endReason = null
    ) {
        PersistedDateTimeConstraints::assertSupported($addedAt, "Collection membership creation timestamp");
        if (($endedAt === null) !== ($endReason === null)) {
            throw new ValidationException("Collection membership end time and reason must occur together.");
        }
        if (($status === CollectionMembershipStatus::Active) !== ($endedAt === null)) {
            throw new ValidationException("Collection membership lifecycle fields are inconsistent.");
        }
        if ($endedAt !== null) {
            PersistedDateTimeConstraints::assertSupported($endedAt, "Collection membership end timestamp");
            if ($endedAt < $addedAt) { throw new ValidationException("Collection membership cannot end before it was added."); }
        }
    }

    public static function active(CollectionMembershipId $id, LibraryId $libraryId, CollectionId $collectionId, ItemId $itemId, CollectionItemPosition $position, DateTimeImmutable $now): self
    {
        return new self($id, $libraryId, $collectionId, $itemId, CollectionMembershipStatus::Active, $position, $now);
    }

    public function deactivate(CollectionMembershipEndReason $reason, DateTimeImmutable $now): self
    {
        if ($this->status !== CollectionMembershipStatus::Active) { throw new CollectionMembershipConflict(); }
        return new self($this->id, $this->libraryId, $this->collectionId, $this->itemId, CollectionMembershipStatus::Inactive, $this->position, $this->addedAt, $now, $reason);
    }

    public function reposition(CollectionItemPosition $position): self
    {
        if ($this->status !== CollectionMembershipStatus::Active) { throw new CollectionMembershipConflict(); }
        if ($this->position->equals($position)) { return $this; }
        return new self($this->id, $this->libraryId, $this->collectionId, $this->itemId, $this->status, $position, $this->addedAt);
    }

    public function id(): CollectionMembershipId { return $this->id; }
    public function libraryId(): LibraryId { return $this->libraryId; }
    public function collectionId(): CollectionId { return $this->collectionId; }
    public function itemId(): ItemId { return $this->itemId; }
    public function status(): CollectionMembershipStatus { return $this->status; }
    public function position(): CollectionItemPosition { return $this->position; }
    public function addedAt(): DateTimeImmutable { return $this->addedAt; }
    public function endedAt(): ?DateTimeImmutable { return $this->endedAt; }
    public function endReason(): ?CollectionMembershipEndReason { return $this->endReason; }
}
