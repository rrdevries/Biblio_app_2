<?php

declare(strict_types=1);

namespace Biblio\Core\Wishlist;

use Biblio\Core\Catalog\{EditionId,WorkId};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;

final readonly class WishlistEntry
{
    public function __construct(
        private WishlistEntryId $id,
        private UserId $ownerUserId,
        private WorkId $workId,
        private ?EditionId $editionId,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt
    ) {
        PersistedDateTimeConstraints::assertSupported(
            $createdAt,
            "Wishlist creation time"
        );
        PersistedDateTimeConstraints::assertSupported(
            $updatedAt,
            "Wishlist update time"
        );
        if ($updatedAt < $createdAt) {
            throw new ValidationException(
                "Wishlist update time must not precede creation time."
            );
        }
    }

    public static function workOnly(
        WishlistEntryId $id,
        UserId $ownerUserId,
        WorkId $workId,
        DateTimeImmutable $createdAt
    ): self {
        return new self($id, $ownerUserId, $workId, null, $createdAt, $createdAt);
    }

    public static function editionSpecific(
        WishlistEntryId $id,
        UserId $ownerUserId,
        WorkId $workId,
        EditionId $editionId,
        DateTimeImmutable $createdAt
    ): self {
        return new self(
            $id,
            $ownerUserId,
            $workId,
            $editionId,
            $createdAt,
            $createdAt
        );
    }

    public function id(): WishlistEntryId { return $this->id; }
    public function ownerUserId(): UserId { return $this->ownerUserId; }
    public function workId(): WorkId { return $this->workId; }
    public function editionId(): ?EditionId { return $this->editionId; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    public function targetType(): WishlistTargetType
    {
        return $this->editionId === null
            ? WishlistTargetType::WorkOnly
            : WishlistTargetType::EditionSpecific;
    }

    public function refineToEdition(
        EditionId $editionId,
        DateTimeImmutable $updatedAt
    ): self {
        if ($this->editionId !== null) {
            throw new ValidationException(
                "Only a Work-only Wishlist Entry can be refined."
            );
        }

        return new self(
            $this->id,
            $this->ownerUserId,
            $this->workId,
            $editionId,
            $this->createdAt,
            $updatedAt
        );
    }
}
