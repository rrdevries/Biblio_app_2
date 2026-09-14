<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use Biblio\Core\Catalog\ContributorRole;
use InvalidArgumentException;

final readonly class ManualAuthorAttemptPlan
{
    /** @var list<ManualAuthorAttempt> */
    private array $authors;

    /** @param array<array-key, mixed> $authors */
    public function __construct(array $authors)
    {
        if (!array_is_list($authors) || count($authors) > 32) {
            throw new InvalidArgumentException(
                "Manual Author attempt plan must contain at most 32 Authors."
            );
        }
        foreach ($authors as $offset => $author) {
            if (!$author instanceof ManualAuthorAttempt) {
                throw new InvalidArgumentException(
                    "Manual Author attempt plan contains an invalid entry."
                );
            }
            if (
                $author->role() !== ContributorRole::Author
                || $author->position()->value() !== $offset + 1
            ) {
                throw new InvalidArgumentException(
                    "Manual Author attempt plan must use author role and contiguous positions."
                );
            }
        }
        /** @var list<ManualAuthorAttempt> $authors */
        $this->authors = $authors;
    }

    /** @return list<ManualAuthorAttempt> */
    public function authors(): array
    {
        return $this->authors;
    }
}
