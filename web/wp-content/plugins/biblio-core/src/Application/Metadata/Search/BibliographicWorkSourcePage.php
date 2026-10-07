<?php

declare(strict_types=1);
namespace Biblio\Core\Application\Metadata\Search;
use InvalidArgumentException;

/** Source progress counts all received documents, before identity deduplication. */
final readonly class BibliographicWorkSourcePage
{
    /** @param list<BibliographicWorkSearchResult> $items */
    public function __construct(
        private BibliographicTextSearchQuery $query, private int $requestedOffset,
        private int $requestedCapacity, private int $consumedCount,
        private array $items, private int $rejectedCount, private ?int $nextOffset
    ) {
        if ($requestedOffset < 0 || $requestedOffset > 1000000 || $requestedCapacity < 1 || $requestedCapacity > 10
            || $consumedCount < 0 || $consumedCount > $requestedCapacity || !array_is_list($items)
            || $rejectedCount < 0 || $rejectedCount !== $consumedCount - count($items)
            || ($nextOffset !== null && ($consumedCount === 0 || $nextOffset !== $requestedOffset + $consumedCount || $nextOffset > 1000000))) {
            throw new InvalidArgumentException('Invalid Work source page.');
        }
        $previous = $requestedOffset - 1;
        foreach ($items as $item) {
            if (!$item instanceof BibliographicWorkSearchResult
                || $item->reference()->kind() !== BibliographicSearchResultKind::ExternalCandidate
                || $item->presentationOrder() <= $previous
                || $item->presentationOrder() >= $requestedOffset + $consumedCount) {
                throw new InvalidArgumentException('Invalid Work source position.');
            }
            $previous = $item->presentationOrder();
        }
    }
    public function query(): BibliographicTextSearchQuery { return $this->query; }
    public function requestedOffset(): int { return $this->requestedOffset; }
    public function requestedCapacity(): int { return $this->requestedCapacity; }
    public function consumedCount(): int { return $this->consumedCount; }
    public function items(): array { return $this->items; }
    public function rejectedCount(): int { return $this->rejectedCount; }
    public function nextOffset(): ?int { return $this->nextOffset; }
}
