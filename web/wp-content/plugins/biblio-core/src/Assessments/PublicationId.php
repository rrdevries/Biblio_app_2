<?php
declare(strict_types=1);
namespace Biblio\Core\Assessments;
use Biblio\Core\Identity\IdentifierConstraints;
final readonly class PublicationId { public function __construct(private string $value) { IdentifierConstraints::assertValid($value, "Publication ID"); } public function value(): string { return $this->value; } public function equals(self $other): bool { return $this->value === $other->value; } }
