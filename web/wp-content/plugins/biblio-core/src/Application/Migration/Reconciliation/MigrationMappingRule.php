<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reconciliation;

use Biblio\Core\Exception\ValidationException;

final readonly class MigrationMappingRule
{
    public function __construct(
        private string $targetType,
        private MigrationMappingKind $kind,
        private bool $required,
        private ?int $maximum = 1
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $this->targetType) !== 1) {
            throw new ValidationException("Reconciliation target type is invalid.");
        }
        if ($this->maximum !== null && $this->maximum < 1) {
            throw new ValidationException(
                "Reconciliation target mapping maximum is invalid."
            );
        }
    }

    public function targetType(): string { return $this->targetType; }
    public function kind(): MigrationMappingKind { return $this->kind; }
    public function required(): bool { return $this->required; }
    public function maximum(): ?int { return $this->maximum; }
}
