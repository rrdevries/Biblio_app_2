<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Wishlist;

use Biblio\Core\Catalog\{Author,EditionId,WorkId};
use Biblio\Core\Wishlist\{WishlistEntryId,WishlistTargetType};
use DateTimeImmutable;

final readonly class WishlistEntryView
{
    /** @param list<Author> $authors */
    public function __construct(
        private WishlistEntryId $entryId,
        private WorkId $workId,
        private ?EditionId $editionId,
        private string $displayTitle,
        private array $authors,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt
    ) {
    }

    public function entryId(): WishlistEntryId { return $this->entryId; }
    public function workId(): WorkId { return $this->workId; }
    public function editionId(): ?EditionId { return $this->editionId; }
    public function targetType(): WishlistTargetType
    {
        return $this->editionId === null
            ? WishlistTargetType::WorkOnly
            : WishlistTargetType::EditionSpecific;
    }
    public function displayTitle(): string { return $this->displayTitle; }
    /** @return list<Author> */ public function authors(): array { return $this->authors; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
}
