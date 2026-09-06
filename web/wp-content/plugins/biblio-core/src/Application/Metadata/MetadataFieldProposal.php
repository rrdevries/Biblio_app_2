<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Identity\UserId;
use DateTimeImmutable;

final class MetadataFieldProposal
{
    /** @var array<string, MetadataFieldEvidence> */
    private array $evidence;

    /** @param list<MetadataFieldEvidence> $evidence */
    public function __construct(
        private readonly MetadataFieldValue $value,
        private MetadataFieldProposalState $state,
        private readonly DateTimeImmutable $firstSeenAt,
        private DateTimeImmutable $lastSeenAt,
        array $evidence = [],
        private ?UserId $decidedBy = null,
        private ?DateTimeImmutable $decidedAt = null
    ) {
        $this->evidence = [];
        foreach ($evidence as $item) {
            $this->evidence[$item->identity()] = $item;
        }
    }

    public function observe(MetadataFieldEvidence $evidence): void
    {
        $identity = $evidence->identity();
        if (isset($this->evidence[$identity])) {
            $this->evidence[$identity]->observeAt($evidence->lastRetrievedAt());
        } else {
            $this->evidence[$identity] = $evidence;
        }
        if ($evidence->lastRetrievedAt() > $this->lastSeenAt) {
            $this->lastSeenAt = $evidence->lastRetrievedAt();
        }
    }

    public function transition(
        MetadataFieldProposalState $state,
        ?UserId $actor,
        DateTimeImmutable $at
    ): void {
        $this->state = $state;
        $this->decidedBy = $actor;
        $this->decidedAt = $actor === null ? null : $at;
    }

    public function value(): MetadataFieldValue { return $this->value; }
    public function state(): MetadataFieldProposalState { return $this->state; }
    public function firstSeenAt(): DateTimeImmutable { return $this->firstSeenAt; }
    public function lastSeenAt(): DateTimeImmutable { return $this->lastSeenAt; }
    public function decidedBy(): ?UserId { return $this->decidedBy; }
    public function decidedAt(): ?DateTimeImmutable { return $this->decidedAt; }

    /** @return list<MetadataFieldEvidence> */
    public function evidence(): array { return array_values($this->evidence); }
}
