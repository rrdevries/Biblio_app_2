<?php

declare(strict_types=1);

namespace Biblio\Core\NextReading;

use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\ReadingSource;

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
            $ids[$entry->id()->value()] = true;
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

    public function withPreferredSource(
        NextReadingEntryId $id,
        ?PreferredReadingSource $source
    ): self {
        $updated = [];
        $found = false;
        foreach ($this->entries as $entry) {
            if ($entry->id()->equals($id)) {
                $current = $entry->preferredSource();
                if (($current === null && $source === null)
                    || ($current !== null && $source !== null && $current->equals($source))) {
                    return $this;
                }
                $updated[] = $entry->withPreferredSource($source);
                $found = true;
                continue;
            }
            $updated[] = $entry;
        }
        if (!$found) {
            throw new NextReadingEntryNotAvailable();
        }
        return new self($this->userId, $this->version->next(), $updated);
    }

    public function matchingEntryId(
        WorkId $workId,
        ReadingSource $actualSource
    ): ?NextReadingEntryId {
        foreach ($this->entries as $entry) {
            if ($entry->workId()->equals($workId)
                && $entry->preferredSource()?->matchesLiveSource($actualSource) === true) {
                return $entry->id();
            }
        }
        foreach ($this->entries as $entry) {
            if ($entry->workId()->equals($workId) && $entry->preferredSource() === null) {
                return $entry->id();
            }
        }
        return null;
    }

    public function restored(
        NextReadingEntry $entry,
        ?NextReadingEntryId $previous,
        ?NextReadingEntryId $next,
        int $originalPosition
    ): self {
        if (!$entry->userId()->equals($this->userId) || $this->find($entry->id()) !== null) {
            throw new NextReadingUndoUnavailable();
        }

        $insertAt = null;
        $previousIndex = $this->indexOf($previous);
        $nextIndex = $this->indexOf($next);
        if ($previous !== null && $next !== null
            && $previousIndex !== null && $nextIndex === $previousIndex + 1) {
            $insertAt = $nextIndex;
        } elseif ($previous === null && $nextIndex === 0) {
            $insertAt = 0;
        } elseif ($next === null && $previousIndex === count($this->entries) - 1) {
            $insertAt = count($this->entries);
        }
        $insertAt ??= max(0, min($originalPosition - 1, count($this->entries)));

        $restored = $this->entries;
        array_splice($restored, $insertAt, 0, [$entry]);
        $restored = array_map(
            static fn (NextReadingEntry $candidate, int $offset): NextReadingEntry =>
                $candidate->atPosition(new NextReadingPosition($offset + 1)),
            $restored,
            array_keys($restored)
        );
        return new self($this->userId, $this->version->next(), $restored);
    }

    private function indexOf(?NextReadingEntryId $id): ?int
    {
        if ($id === null) {
            return null;
        }
        foreach ($this->entries as $index => $entry) {
            if ($entry->id()->equals($id)) {
                return $index;
            }
        }
        return null;
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
