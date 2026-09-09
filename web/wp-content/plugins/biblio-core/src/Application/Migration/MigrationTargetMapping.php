<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

use Biblio\Core\Exception\ValidationException;

final readonly class MigrationTargetMapping
{
    public function __construct(
        private string $targetType,
        private string $targetId,
        private MappingDisposition $disposition,
        private ?string $reasonCode = null
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{0,63}$/', $this->targetType) !== 1) {
            throw new ValidationException("Migration target type is invalid.");
        }
        if ($this->targetId === "" || trim($this->targetId) === "" || preg_match('//u', $this->targetId) !== 1 || mb_strlen($this->targetId) > 191) {
            throw new ValidationException("Migration target ID is invalid.");
        }
        if ($this->reasonCode !== null && preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $this->reasonCode) !== 1) {
            throw new ValidationException("Migration mapping reason is invalid.");
        }
    }

    public function targetType(): string { return $this->targetType; }
    public function targetId(): string { return $this->targetId; }
    public function disposition(): MappingDisposition { return $this->disposition; }
    public function reasonCode(): ?string { return $this->reasonCode; }
}
