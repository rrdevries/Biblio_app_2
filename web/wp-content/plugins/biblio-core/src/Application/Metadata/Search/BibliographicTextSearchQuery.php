<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Application\Metadata\Discovery\BibliographicTextQuery;
use Biblio\Core\Catalog\IsbnCanonicalizer;
use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicTextSearchQuery
{
    private BibliographicTextQuery $text;

    public function __construct(string $input)
    {
        if ((new IsbnCanonicalizer())->parse($input)->isValid()) {
            throw new ValidationException(
                "ISBN input does not belong to the Author/Work text-search contract."
            );
        }
        $this->text = new BibliographicTextQuery($input);
    }

    public function value(): string { return $this->text->value(); }
}
