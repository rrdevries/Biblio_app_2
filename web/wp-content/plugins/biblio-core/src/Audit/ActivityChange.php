<?php

declare(strict_types=1);

namespace Biblio\Core\Audit;

use Biblio\Core\Identity\IdentifierConstraints;

final readonly class ActivityChange
{
    public function __construct(
        private string $field,
        private ActivityPayload $oldValue,
        private ActivityPayload $newValue
    ) {
        IdentifierConstraints::assertValid($this->field, "Activity change field");
    }

    public function field(): string
    {
        return $this->field;
    }

    public function oldValue(): ActivityPayload
    {
        return $this->oldValue;
    }

    public function newValue(): ActivityPayload
    {
        return $this->newValue;
    }
}
