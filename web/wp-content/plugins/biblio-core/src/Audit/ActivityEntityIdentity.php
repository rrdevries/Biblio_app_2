<?php

declare(strict_types=1);

namespace Biblio\Core\Audit;

use Biblio\Core\Identity\IdentifierConstraints;

final readonly class ActivityEntityIdentity
{
    public function __construct(
        private string $entityType,
        private string $entityId
    ) {
        IdentifierConstraints::assertValid(
            $this->entityType,
            "Activity entity type"
        );
        IdentifierConstraints::assertValid(
            $this->entityId,
            "Activity entity ID"
        );
    }

    public function entityType(): string
    {
        return $this->entityType;
    }

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function key(): string
    {
        return $this->entityType . "\0" . $this->entityId;
    }
}
