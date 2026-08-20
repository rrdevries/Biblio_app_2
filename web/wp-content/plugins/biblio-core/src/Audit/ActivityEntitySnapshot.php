<?php

declare(strict_types=1);

namespace Biblio\Core\Audit;

final readonly class ActivityEntitySnapshot
{
    public function __construct(
        private ActivityEntityIdentity $identity,
        private ?ActivityLabel $displayLabel,
        private ActivityPayload $attributes
    ) {
    }

    public function identity(): ActivityEntityIdentity
    {
        return $this->identity;
    }

    public function displayLabel(): ?ActivityLabel
    {
        return $this->displayLabel;
    }

    public function attributes(): ActivityPayload
    {
        return $this->attributes;
    }
}
