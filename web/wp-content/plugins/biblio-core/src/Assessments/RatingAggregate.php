<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
final readonly class RatingAggregate
{
    public function __construct(private int $count, private int $sumHalfUnits, private int $uniqueUsers) {}
    public static function empty(): self { return new self(0, 0, 0); }
    public function count(): int { return $this->count; } public function sumHalfUnits(): int { return $this->sumHalfUnits; } public function uniqueUsers(): int { return $this->uniqueUsers; }
    public function average(): ?float { return $this->count === 0 ? null : round($this->sumHalfUnits / (2 * $this->count), 1, PHP_ROUND_HALF_UP); }
}
