<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Query;

use Biblio\Core\Collections\CollectionId;
use Biblio\Core\Exception\ValidationException;

final readonly class CatalogCollectionFilter
{
    /** @param list<CollectionId> $collectionIds */
    private function __construct(private array $collectionIds, private bool $withoutCollection)
    {
        if ($withoutCollection && $collectionIds !== []) {
            throw new ValidationException('Without Collection is exclusive with Collection IDs.');
        }
        self::assertTypedUnique($collectionIds);
    }

    public static function any(): self { return new self([], false); }

    /** @param list<CollectionId> $collectionIds */
    public static function in(array $collectionIds): self
    {
        if ($collectionIds === []) {
            throw new ValidationException('A Collection filter requires at least one ID.');
        }
        return new self($collectionIds, false);
    }

    public static function withoutCollection(): self { return new self([], true); }

    /** @return list<CollectionId> */
    public function collectionIds(): array { return $this->collectionIds; }
    public function isWithoutCollection(): bool { return $this->withoutCollection; }
    public function isActive(): bool { return $this->withoutCollection || $this->collectionIds !== []; }

    /** @param array<mixed> $ids */
    private static function assertTypedUnique(array $ids): void
    {
        $seen = [];
        foreach ($ids as $id) {
            if (!$id instanceof CollectionId || isset($seen[$id->value()])) {
                throw new ValidationException('Collection filter IDs must be typed and unique.');
            }
            $seen[$id->value()] = true;
        }
    }
}
