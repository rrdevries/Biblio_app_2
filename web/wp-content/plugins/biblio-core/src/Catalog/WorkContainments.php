<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class WorkContainments
{
    /** @var list<WorkContainment> */
    private array $values;

    /** @param list<WorkContainment> $values */
    public function __construct(array $values)
    {
        $relations = [];
        $positions = [];
        $adjacency = [];

        foreach ($values as $value) {
            $parent = $value->parentWorkId()->value();
            $contained = $value->containedWorkId()->value();
            $relationKey = $parent . "\0" . $contained;
            $positionKey = $parent . "\0" . $value->position()->value();

            if (isset($relations[$relationKey])) {
                throw new ValidationException(
                    "Work containment relations must be unique."
                );
            }

            if (isset($positions[$positionKey])) {
                throw new ValidationException(
                    "Contained Work positions must be unique per parent."
                );
            }

            $relations[$relationKey] = true;
            $positions[$positionKey] = true;
            $adjacency[$parent][] = $contained;
        }

        $this->assertAcyclic($adjacency);

        usort(
            $values,
            static fn (
                WorkContainment $left,
                WorkContainment $right
            ): int => [
                $left->parentWorkId()->value(),
                $left->position()->value(),
                $left->containedWorkId()->value(),
            ] <=> [
                $right->parentWorkId()->value(),
                $right->position()->value(),
                $right->containedWorkId()->value(),
            ]
        );

        $this->values = $values;
    }

    /** @return list<WorkContainment> */
    public function values(): array { return $this->values; }

    /** @param array<string, list<string>> $adjacency */
    private function assertAcyclic(array $adjacency): void
    {
        $state = [];
        $visit = function (string $workId) use (
            &$visit,
            &$state,
            $adjacency
        ): void {
            if (($state[$workId] ?? 0) === 1) {
                throw new ValidationException(
                    "Work containment must not contain a cycle."
                );
            }

            if (($state[$workId] ?? 0) === 2) {
                return;
            }

            $state[$workId] = 1;
            foreach ($adjacency[$workId] ?? [] as $containedWorkId) {
                $visit($containedWorkId);
            }
            $state[$workId] = 2;
        };

        foreach (array_keys($adjacency) as $workId) {
            $visit($workId);
        }
    }
}
