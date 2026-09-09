<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Identity;

use Biblio\Core\Exception\ValidationException;

final readonly class PersonalMigrationTargetReadiness
{
    /** @param array<string, int> $counts */
    public function __construct(private array $counts)
    {
        foreach ($this->counts as $domain => $count) {
            if ($domain === "" || $count < 0) {
                throw new ValidationException(
                    "Migration target readiness counts are invalid."
                );
            }
        }
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return $this->counts;
    }

    public function isClean(): bool
    {
        return array_sum($this->counts) === 0;
    }

    public function status(): string
    {
        return $this->isClean()
            ? "empty"
            : "non_empty_requires_operator_review";
    }
}
