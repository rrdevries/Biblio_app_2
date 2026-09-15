<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

use Biblio\Core\Exception\ValidationException;

final readonly class MigrationSourceCategoryStrategy
{
    /** @param list<string> $sourceTypes */
    public function __construct(
        private string $category,
        private array $sourceTypes,
        private int $nonObservationCount = 0,
        private ?string $nonObservationReason = null
    ) {
        if (trim($this->category) === "") {
            throw new ValidationException("Source category strategy is invalid.");
        }
        $types = [];
        foreach ($this->sourceTypes as $sourceType) {
            if (
                preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $sourceType) !== 1
                || isset($types[$sourceType])
            ) {
                throw new ValidationException("Source category strategy type is invalid.");
            }
            $types[$sourceType] = true;
        }
        if ($this->nonObservationCount < 0) {
            throw new ValidationException("Non-observation count is invalid.");
        }
        if (
            ($this->nonObservationCount > 0)
            !== ($this->nonObservationReason !== null)
            || (
                $this->nonObservationReason !== null
                && preg_match(
                    '/^[a-z0-9][a-z0-9._-]{0,63}$/',
                    $this->nonObservationReason
                ) !== 1
            )
        ) {
            throw new ValidationException(
                "Non-observation accounting requires a stable reason."
            );
        }
    }

    public function category(): string { return $this->category; }
    /** @return list<string> */
    public function sourceTypes(): array
    {
        $types = $this->sourceTypes;
        sort($types, SORT_STRING);
        return $types;
    }
    public function nonObservationCount(): int
    {
        return $this->nonObservationCount;
    }
    public function nonObservationReason(): ?string
    {
        return $this->nonObservationReason;
    }

    /** @return array{source_types:list<string>,non_observation_count:int,non_observation_reason:?string} */
    public function toArray(): array
    {
        return [
            "source_types" => $this->sourceTypes(),
            "non_observation_count" => $this->nonObservationCount,
            "non_observation_reason" => $this->nonObservationReason,
        ];
    }
}
