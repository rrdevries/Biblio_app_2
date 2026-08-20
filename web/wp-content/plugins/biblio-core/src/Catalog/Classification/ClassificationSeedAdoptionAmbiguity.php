<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;

final readonly class ClassificationSeedAdoptionAmbiguity
{
    /** @var list<string> */
    private array $candidateTermIds;

    /** @param list<string> $candidateTermIds */
    public function __construct(
        private LibraryId $libraryId,
        private ClassificationTaxonomyType $taxonomyType,
        private ClassificationSeedKey $seedKey,
        array $candidateTermIds
    ) {
        $candidateTermIds = array_values(array_unique($candidateTermIds));
        sort($candidateTermIds, SORT_STRING);

        if ($candidateTermIds === []) {
            throw new ValidationException(
                "Seed-adoption ambiguity requires candidate term IDs."
            );
        }

        $this->candidateTermIds = $candidateTermIds;
    }

    public function libraryId(): LibraryId
    {
        return $this->libraryId;
    }

    public function taxonomyType(): ClassificationTaxonomyType
    {
        return $this->taxonomyType;
    }

    public function seedKey(): ClassificationSeedKey
    {
        return $this->seedKey;
    }

    /** @return list<string> */
    public function candidateTermIds(): array
    {
        return $this->candidateTermIds;
    }
}
