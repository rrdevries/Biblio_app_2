<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class WorkSeriesMemberships
{
    /** @var list<WorkSeriesMembership> */
    private array $values;

    /** @param list<WorkSeriesMembership> $values */
    public function __construct(array $values)
    {
        $series = [];
        $workId = null;
        foreach ($values as $value) {
            $workId ??= $value->workId()->value();
            if ($value->workId()->value() !== $workId) {
                throw new ValidationException("Work-Series memberships must belong to one Work.");
            }
            $seriesId = $value->seriesId()->value();
            if (isset($series[$seriesId])) {
                throw new ValidationException("Work-Series memberships must be unique by Series.");
            }
            $series[$seriesId] = true;
        }
        $this->values = $values;
    }

    /** @return list<WorkSeriesMembership> */
    public function values(): array { return $this->values; }
}
