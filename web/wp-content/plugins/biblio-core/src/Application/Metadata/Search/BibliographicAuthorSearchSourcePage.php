<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicAuthorSearchSourcePage
{
    /** @var list<BibliographicAuthorSearchResult> */
    private array $items;

    /** @param array<mixed> $items */
    public function __construct(array $items, private ?int $nextOffset)
    {
        if (!array_is_list($items)) {
            throw new ValidationException("Bibliographic Author source results must be a list.");
        }
        $previous = null;
        $identities = [];
        foreach ($items as $item) {
            if (!$item instanceof BibliographicAuthorSearchResult) {
                throw new ValidationException("Bibliographic Author source page contains invalid data.");
            }
            foreach ($item->reference()->strongIdentityKeys() as $identity) {
                if (isset($identities[$identity])) {
                    throw new ValidationException("Duplicate strong Author identity in source page.");
                }
                $identities[$identity] = true;
            }
            $sourceKey = $item->sourceOrderKey();
            if ($previous !== null && $sourceKey <= $previous) {
                throw new ValidationException("Bibliographic Author source results are not ordered.");
            }
            $previous = $sourceKey;
        }
        if ($nextOffset !== null && ($nextOffset < 1 || $nextOffset > 1000000)) {
            throw new ValidationException("Invalid bibliographic Author source continuation.");
        }
        /** @var list<BibliographicAuthorSearchResult> $items */
        $this->items = $items;
    }

    /** @return list<BibliographicAuthorSearchResult> */ public function items(): array { return $this->items; }
    public function nextOffset(): ?int { return $this->nextOffset; }
}
