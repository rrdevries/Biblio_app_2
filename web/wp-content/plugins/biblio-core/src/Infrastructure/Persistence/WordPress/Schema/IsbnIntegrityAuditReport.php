<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress\Schema;

use JsonSerializable;

/** @phpstan-type CollisionMap array<string, list<string>> */
final readonly class IsbnIntegrityAuditReport implements JsonSerializable
{
    /**
     * @param list<string> $invalidEditionIds
     * @param CollisionMap $exactCollisions
     * @param CollisionMap $equivalenceCollisions
     * @param list<string> $internalConflictEditionIds
     * @param list<string> $formatDeviationEditionIds
     * @param array<string, string> $canonicalClaims canonical ISBN-13 => Edition ID
     */
    public function __construct(
        public int $totalEditions,
        public int $withIsbn13,
        public int $isbn10Only,
        public int $withoutIsbn,
        public array $invalidEditionIds,
        public array $exactCollisions,
        public array $equivalenceCollisions,
        public array $internalConflictEditionIds,
        public array $formatDeviationEditionIds,
        public array $canonicalClaims
    ) {
    }

    public function hasBlockers(): bool
    {
        return $this->invalidEditionIds !== []
            || $this->exactCollisions !== []
            || $this->equivalenceCollisions !== []
            || $this->internalConflictEditionIds !== []
            || $this->formatDeviationEditionIds !== [];
    }

    public function blockerSummary(): string
    {
        return "invalid=" . count($this->invalidEditionIds)
            . ", exact_collisions=" . count($this->exactCollisions)
            . ", equivalence_collisions=" . count($this->equivalenceCollisions)
            . ", internal_conflicts=" . count($this->internalConflictEditionIds)
            . ", format_deviations=" . count($this->formatDeviationEditionIds);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            "total_editions" => $this->totalEditions,
            "isbn_13" => $this->withIsbn13,
            "isbn_10_only" => $this->isbn10Only,
            "no_isbn" => $this->withoutIsbn,
            "invalid_edition_ids" => $this->invalidEditionIds,
            "exact_collisions" => $this->exactCollisions,
            "equivalence_collisions" => $this->equivalenceCollisions,
            "internal_conflict_edition_ids" => $this->internalConflictEditionIds,
            "format_deviation_edition_ids" => $this->formatDeviationEditionIds,
            "canonical_claim_count" => count($this->canonicalClaims),
            "status" => $this->hasBlockers() ? "BLOCKED" : "OK",
        ];
    }
}
