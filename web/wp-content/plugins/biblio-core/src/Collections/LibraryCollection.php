<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;

final readonly class LibraryCollection
{
    public function __construct(
        private CollectionId $id,
        private LibraryId $libraryId,
        private CollectionName $name,
        private NormalizedCollectionName $normalizedName,
        private ?CollectionDescription $description,
        private CollectionStatus $status,
        private CollectionPosition $position,
        private CollectionVersion $version,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt
    ) {
        PersistedDateTimeConstraints::assertSupported($createdAt, "Collection creation timestamp");
        PersistedDateTimeConstraints::assertSupported($updatedAt, "Collection update timestamp");
        if ($updatedAt < $createdAt) {
            throw new ValidationException("Collection update timestamp cannot precede creation.");
        }
    }

    public static function create(
        CollectionId $id,
        LibraryId $libraryId,
        CollectionName $name,
        NormalizedCollectionName $normalizedName,
        ?CollectionDescription $description,
        CollectionPosition $position,
        DateTimeImmutable $now
    ): self {
        return new self($id, $libraryId, $name, $normalizedName, $description, CollectionStatus::Active, $position, CollectionVersion::initial(), $now, $now);
    }

    public function updateDetails(CollectionName $name, NormalizedCollectionName $normalizedName, ?CollectionDescription $description, DateTimeImmutable $now): self
    {
        $this->assertActive();
        if ($this->name->equals($name) && $this->normalizedName->equals($normalizedName) && $this->sameDescription($description)) { return $this; }
        return $this->replacement($name, $normalizedName, $description, $this->status, $this->position, $now);
    }

    public function archive(DateTimeImmutable $now): self
    {
        $this->assertActive();
        return $this->replacement($this->name, $this->normalizedName, $this->description, CollectionStatus::Archived, $this->position, $now);
    }

    public function restore(DateTimeImmutable $now): self
    {
        if ($this->status !== CollectionStatus::Archived) { throw new CollectionTransitionUnavailable(); }
        return $this->replacement($this->name, $this->normalizedName, $this->description, CollectionStatus::Active, $this->position, $now);
    }

    public function reposition(CollectionPosition $position, DateTimeImmutable $now): self
    {
        $this->assertActive();
        if ($this->position->equals($position)) { return $this; }
        return $this->replacement($this->name, $this->normalizedName, $this->description, $this->status, $position, $now);
    }

    public function contentChanged(DateTimeImmutable $now): self
    {
        $this->assertActive();
        return $this->replacement($this->name, $this->normalizedName, $this->description, $this->status, $this->position, $now);
    }

    public function id(): CollectionId { return $this->id; }
    public function libraryId(): LibraryId { return $this->libraryId; }
    public function name(): CollectionName { return $this->name; }
    public function normalizedName(): NormalizedCollectionName { return $this->normalizedName; }
    public function description(): ?CollectionDescription { return $this->description; }
    public function status(): CollectionStatus { return $this->status; }
    public function position(): CollectionPosition { return $this->position; }
    public function version(): CollectionVersion { return $this->version; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    private function assertActive(): void
    {
        if ($this->status !== CollectionStatus::Active) { throw new CollectionTransitionUnavailable(); }
    }

    private function sameDescription(?CollectionDescription $description): bool
    {
        return $this->description === null ? $description === null : $description !== null && $this->description->equals($description);
    }

    private function replacement(CollectionName $name, NormalizedCollectionName $normalizedName, ?CollectionDescription $description, CollectionStatus $status, CollectionPosition $position, DateTimeImmutable $now): self
    {
        return new self($this->id, $this->libraryId, $name, $normalizedName, $description, $status, $position, $this->version->next(), $this->createdAt, $now);
    }
}
