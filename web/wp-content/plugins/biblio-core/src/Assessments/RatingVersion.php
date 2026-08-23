<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use Biblio\Core\Exception\ValidationException;
final readonly class RatingVersion { public function __construct(private int $value) { if ($value < 1) throw new ValidationException("Rating version must be positive."); } public static function initial(): self { return new self(1); } public function next(): self { return new self($this->value + 1); } public function value(): int { return $this->value; } public function equals(self $other): bool { return $this->value === $other->value; } }
