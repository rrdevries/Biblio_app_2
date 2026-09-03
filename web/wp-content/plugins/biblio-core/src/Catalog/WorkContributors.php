<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class WorkContributors
{
    /** @var list<WorkContributor> */
    private array $values;

    /** @param list<WorkContributor> $values */
    public function __construct(array $values)
    {
        $authors = [];
        $positions = [];
        $workId = null;

        foreach ($values as $value) {
            $workId ??= $value->workId()->value();
            if ($value->workId()->value() !== $workId) {
                throw new ValidationException("Work contributors must belong to one Work.");
            }
            $author = $value->authorId()->value();
            $position = $value->position()->value();
            if (isset($authors[$author]) || isset($positions[$position])) {
                throw new ValidationException("Work contributors must be unique by Author and position.");
            }
            $authors[$author] = true;
            $positions[$position] = true;
        }

        usort(
            $values,
            static fn (WorkContributor $left, WorkContributor $right): int =>
                $left->position()->value() <=> $right->position()->value()
        );
        $this->values = $values;
    }

    /** @return list<WorkContributor> */
    public function values(): array { return $this->values; }
}
