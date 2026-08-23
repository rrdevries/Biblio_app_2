<?php

declare(strict_types=1);

namespace Biblio\Core\NextReading;

use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;

final readonly class NextReadingList
{
    /** @var list<NextReadingEntry> */
    private array $entries;

    /** @param list<NextReadingEntry> $entries */
    public function __construct(
        private UserId $userId,
        private NextReadingListVersion $version,
        array $entries
    ) {
        $ids = [];
        $targets = [];
        foreach ($entries as $offset => $entry) {
            if (!$entry->userId()->equals($userId)) {
                throw new ValidationException("Next Reading Entry owner must match list owner.");
            }
            if ($entry->position()->value() !== $offset + 1) {
                throw new ValidationException("Next Reading positions must be contiguous from one.");
            }
            if (isset($ids[$entry->id()->value()])) {
                throw new ValidationException("Next Reading Entry IDs must be unique.");
            }
            if (isset($targets[$entry->target()->uniquenessKey()])) {
                throw new NextReadingTargetDuplicate();
            }
            $ids[$entry->id()->value()] = true;
            $targets[$entry->target()->uniquenessKey()] = true;
        }
        $this->entries = $entries;
    }

    public static function empty(UserId $userId): self
    {
        return new self($userId, NextReadingListVersion::initial(), []);
    }

    public function userId(): UserId { return $this->userId; }
    public function version(): NextReadingListVersion { return $this->version; }
    /** @return list<NextReadingEntry> */
    public function entries(): array { return $this->entries; }

    public function containsTarget(NextReadingTarget $target): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->target()->uniquenessKey() === $target->uniquenessKey()) {
                return true;
            }
        }
        return false;
    }

    public function find(NextReadingEntryId $id): ?NextReadingEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->id()->equals($id)) {
                return $entry;
            }
        }
        return null;
    }

    public function append(NextReadingEntry $entry): self
    {
        if ($this->containsTarget($entry->target())) {
            throw new NextReadingTargetDuplicate();
        }
        $positioned = $entry->atPosition(new NextReadingPosition(count($this->entries) + 1));
        return new self($this->userId, $this->version->next(), [...$this->entries, $positioned]);
    }

    public function without(NextReadingEntryId $id): self
    {
        $remaining = [];
        foreach ($this->entries as $entry) {
            if (!$entry->id()->equals($id)) {
                $remaining[] = $entry->atPosition(new NextReadingPosition(count($remaining) + 1));
            }
        }
        if (count($remaining) === count($this->entries)) {
            throw new NextReadingEntryNotAvailable();
        }
        return new self($this->userId, $this->version->next(), $remaining);
    }

    /** @param list<NextReadingEntryId> $orderedIds */
    public function reordered(array $orderedIds): self
    {
        if (count($orderedIds) !== count($this->entries)) {
            throw new ValidationException("Reorder must contain the complete Next Reading list.");
        }
        $currentById = [];
        foreach ($this->entries as $entry) {
            $currentById[$entry->id()->value()] = $entry;
        }
        $ordered = [];
        $seen = [];
        foreach ($orderedIds as $id) {
            if (isset($seen[$id->value()]) || !isset($currentById[$id->value()])) {
                throw new ValidationException("Reorder contains duplicate or unavailable entries.");
            }
            $seen[$id->value()] = true;
            $ordered[] = $currentById[$id->value()]->atPosition(
                new NextReadingPosition(count($ordered) + 1)
            );
        }
        if ($this->sameOrder($ordered)) {
            return $this;
        }
        return new self($this->userId, $this->version->next(), $ordered);
    }

    /** @param list<NextReadingEntry> $entries */
    private function sameOrder(array $entries): bool
    {
        foreach ($entries as $offset => $entry) {
            if (!$entry->id()->equals($this->entries[$offset]->id())) {
                return false;
            }
        }
        return true;
    }
}
