<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class WorkAlternateTitles
{
    /** @var list<AlternateWorkTitle> */
    private array $values;

    /** @param list<AlternateWorkTitle> $values */
    public function __construct(array $values)
    {
        $workId = null;
        $normalizedTitles = [];

        foreach ($values as $value) {
            $workId ??= $value->workId()->value();

            if ($value->workId()->value() !== $workId) {
                throw new ValidationException(
                    "Alternate titles must belong to one Work."
                );
            }

            if (isset($normalizedTitles[$value->normalizedKey()])) {
                throw new ValidationException(
                    "Alternate Work titles must be unique after normalization."
                );
            }

            $normalizedTitles[$value->normalizedKey()] = true;
        }

        usort(
            $values,
            static fn (
                AlternateWorkTitle $left,
                AlternateWorkTitle $right
            ): int => [
                $left->normalizedKey(),
                $left->value(),
            ] <=> [
                $right->normalizedKey(),
                $right->value(),
            ]
        );

        $this->values = $values;
    }

    /** @return list<AlternateWorkTitle> */
    public function values(): array { return $this->values; }
}
