<?php

declare(strict_types=1);

namespace Biblio\Core\Application\NextReading;

use Biblio\Core\NextReading\PreferredReadingSourceType;

final readonly class PreferredReadingSourceView
{
    public function __construct(
        private ?PreferredReadingSourceType $type,
        private PreferredReadingSourceState $state,
        private ?string $label
    ) {
    }

    public function type(): ?PreferredReadingSourceType { return $this->type; }
    public function state(): PreferredReadingSourceState { return $this->state; }
    public function label(): ?string { return $this->label; }
}
