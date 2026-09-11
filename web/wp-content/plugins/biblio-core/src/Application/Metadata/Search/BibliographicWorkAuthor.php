<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Catalog\{Author,AuthorId};

final readonly class BibliographicWorkAuthor
{
    public function __construct(
        private string $displayName,
        private ?AuthorId $authorId = null
    ) {
        BibliographicAuthorSearchResult::assertText(
            $displayName,
            Author::MAX_NAME_LENGTH,
            "Work Author name"
        );
    }

    public function displayName(): string { return $this->displayName; }
    public function authorId(): ?AuthorId { return $this->authorId; }
}
