<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

final readonly class EffectivePersonalReadingStatus
{
    public function __construct(
        private PersonalWorkReadingStatus $status,
        private EffectivePersonalReadingStatusSource $source,
        private ?PersonalReadingTruthState $truthState,
        private bool $hasPriorReadEvidence,
        private ?bool $readDateKnown
    ) {
    }

    public function status(): PersonalWorkReadingStatus { return $this->status; }
    public function source(): EffectivePersonalReadingStatusSource { return $this->source; }
    public function truthState(): ?PersonalReadingTruthState { return $this->truthState; }
    public function hasPriorReadEvidence(): bool { return $this->hasPriorReadEvidence; }
    public function readDateKnown(): ?bool { return $this->readDateKnown; }
}
