<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use Biblio\Core\Identity\IdentifierConstraints;
final readonly class RatingId { public function __construct(private string $value) { IdentifierConstraints::assertValid($value, "Rating ID"); } public function value(): string { return $this->value; } public function equals(self $other): bool { return $this->value === $other->value; } }
