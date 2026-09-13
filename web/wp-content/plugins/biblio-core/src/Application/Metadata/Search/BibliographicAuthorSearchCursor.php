<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicAuthorSearchCursor
{
    public const string ORDER_CONTRACT = "canonical_exact_broader_external_exact_broader_v1";

    public function __construct(
        private BibliographicTextSearchQuery $query,
        private BibliographicAuthorSearchLane $lane,
        private int $nextOffset
    ) {
        if ($nextOffset < 0 || $nextOffset > 1000000
            || ($lane === BibliographicAuthorSearchLane::Local && $nextOffset === 0)) {
            throw new ValidationException("Invalid bibliographic Author source offset.");
        }
    }

    public function query(): BibliographicTextSearchQuery { return $this->query; }
    public function group(): BibliographicSearchGroup { return BibliographicSearchGroup::Authors; }
    public function lane(): BibliographicAuthorSearchLane { return $this->lane; }
    public function nextOffset(): int { return $this->nextOffset; }
}
