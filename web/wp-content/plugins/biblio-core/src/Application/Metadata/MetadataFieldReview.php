<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Identity\UserId;
use DateTimeImmutable;
use LogicException;

final class MetadataFieldReview
{
    /** @var array<string, MetadataFieldProposal> */
    private array $proposals;

    /** @param list<MetadataFieldProposal> $proposals */
    public function __construct(
        private readonly MetadataRecordId $recordId,
        private readonly MetadataField $field,
        private MetadataFieldConfirmationState $confirmationState,
        private ?MetadataFieldValue $canonicalValue,
        private ?UserId $confirmedBy,
        private int $version,
        private DateTimeImmutable $updatedAt,
        array $proposals = []
    ) {
        $this->assertCanonicalState();
        if ($version < 1) {
            throw new LogicException("Metadata field version must be positive.");
        }

        $this->proposals = [];
        foreach ($proposals as $proposal) {
            $this->proposals[$proposal->value()->hash()] = $proposal;
        }
    }

    public static function empty(
        MetadataRecordId $recordId,
        MetadataField $field,
        DateTimeImmutable $at
    ): self {
        return new self(
            $recordId,
            $field,
            MetadataFieldConfirmationState::Unknown,
            null,
            null,
            1,
            $at
        );
    }

    public function observe(
        MetadataFieldValue $value,
        MetadataFieldEvidence $evidence,
        DateTimeImmutable $at
    ): void {
        $hash = $value->hash();
        $proposal = $this->proposals[$hash] ?? null;
        $isCanonical = $this->canonicalValue?->equals($value) ?? false;

        if ($proposal === null) {
            $state = match (true) {
                $isCanonical => MetadataFieldProposalState::Supporting,
                $this->confirmationState === MetadataFieldConfirmationState::IntentionallyBlank =>
                    MetadataFieldProposalState::BlockedByIntentionalBlank,
                default => MetadataFieldProposalState::Active,
            };
            $proposal = new MetadataFieldProposal($value, $state, $at, $at);
            $this->proposals[$hash] = $proposal;
        } elseif ($isCanonical && $proposal->state() !== MetadataFieldProposalState::Confirmed) {
            $proposal->transition(MetadataFieldProposalState::Supporting, null, $at);
        }

        $proposal->observe($evidence);
        $this->touch($at);
    }

    public function recordUnconfirmedValue(
        MetadataFieldValue $value,
        DateTimeImmutable $at
    ): void {
        if (
            $this->confirmationState === MetadataFieldConfirmationState::UserConfirmed
            || $this->confirmationState === MetadataFieldConfirmationState::IntentionallyBlank
        ) {
            throw new LogicException(
                "Unconfirmed metadata cannot replace an explicit user decision."
            );
        }
        $this->replaceCanonical(
            $value,
            MetadataFieldConfirmationState::Unconfirmed,
            null,
            $at
        );
    }

    public function correctManually(
        MetadataFieldValue $value,
        UserId $actor,
        DateTimeImmutable $at
    ): void {
        $this->replaceCanonical(
            $value,
            MetadataFieldConfirmationState::UserConfirmed,
            $actor,
            $at
        );
    }

    public function confirm(
        string $valueHash,
        UserId $actor,
        DateTimeImmutable $at
    ): void {
        $proposal = $this->proposals[$valueHash] ?? null;
        if ($proposal === null || $proposal->state() !== MetadataFieldProposalState::Active) {
            throw new LogicException("Only an active metadata proposal can be confirmed.");
        }

        foreach ($this->proposals as $candidate) {
            if ($candidate === $proposal) {
                $candidate->transition(MetadataFieldProposalState::Confirmed, $actor, $at);
            } elseif (
                $candidate->state() !== MetadataFieldProposalState::Rejected
                && $candidate->state() !== MetadataFieldProposalState::Superseded
            ) {
                $candidate->transition(MetadataFieldProposalState::Superseded, $actor, $at);
            }
        }

        $this->canonicalValue = $proposal->value();
        $this->confirmationState = MetadataFieldConfirmationState::UserConfirmed;
        $this->confirmedBy = $actor;
        $this->touch($at);
    }

    public function reject(
        string $valueHash,
        UserId $actor,
        DateTimeImmutable $at
    ): void {
        $proposal = $this->proposals[$valueHash] ?? null;
        if ($proposal === null || $proposal->state() !== MetadataFieldProposalState::Active) {
            throw new LogicException("Only an active metadata proposal can be rejected.");
        }

        $proposal->transition(MetadataFieldProposalState::Rejected, $actor, $at);
        $this->touch($at);
    }

    public function markIntentionallyBlank(UserId $actor, DateTimeImmutable $at): void
    {
        foreach ($this->proposals as $proposal) {
            if (
                $proposal->state() === MetadataFieldProposalState::Active
                || $proposal->state() === MetadataFieldProposalState::Supporting
                || $proposal->state() === MetadataFieldProposalState::Confirmed
            ) {
                $proposal->transition(
                    MetadataFieldProposalState::BlockedByIntentionalBlank,
                    $actor,
                    $at
                );
            }
        }
        $this->canonicalValue = null;
        $this->confirmationState = MetadataFieldConfirmationState::IntentionallyBlank;
        $this->confirmedBy = $actor;
        $this->touch($at);
    }

    public function allowProposalsAgain(DateTimeImmutable $at): void
    {
        if ($this->confirmationState !== MetadataFieldConfirmationState::IntentionallyBlank) {
            throw new LogicException("Only an intentionally blank field can be reopened.");
        }
        foreach ($this->proposals as $proposal) {
            if ($proposal->state() === MetadataFieldProposalState::BlockedByIntentionalBlank) {
                $proposal->transition(MetadataFieldProposalState::Active, null, $at);
            }
        }
        $this->confirmationState = MetadataFieldConfirmationState::Unknown;
        $this->confirmedBy = null;
        $this->touch($at);
    }

    private function replaceCanonical(
        MetadataFieldValue $value,
        MetadataFieldConfirmationState $state,
        ?UserId $actor,
        DateTimeImmutable $at
    ): void {
        foreach ($this->proposals as $proposal) {
            if ($proposal->value()->equals($value)) {
                $proposal->transition(
                    $state === MetadataFieldConfirmationState::UserConfirmed
                        ? MetadataFieldProposalState::Confirmed
                        : MetadataFieldProposalState::Supporting,
                    $actor,
                    $at
                );
            } elseif (
                $proposal->state() === MetadataFieldProposalState::Active
                || $proposal->state() === MetadataFieldProposalState::Supporting
                || $proposal->state() === MetadataFieldProposalState::Confirmed
            ) {
                $proposal->transition(MetadataFieldProposalState::Superseded, $actor, $at);
            }
        }

        if (!isset($this->proposals[$value->hash()])) {
            $this->proposals[$value->hash()] = new MetadataFieldProposal(
                $value,
                $state === MetadataFieldConfirmationState::UserConfirmed
                    ? MetadataFieldProposalState::Confirmed
                    : MetadataFieldProposalState::Supporting,
                $at,
                $at,
                [],
                $actor,
                $actor === null ? null : $at
            );
        }

        $this->canonicalValue = $value;
        $this->confirmationState = $state;
        $this->confirmedBy = $actor;
        $this->touch($at);
    }

    private function touch(DateTimeImmutable $at): void
    {
        ++$this->version;
        $this->updatedAt = $at;
    }

    private function assertCanonicalState(): void
    {
        $hasValue = $this->canonicalValue !== null;
        $hasActor = $this->confirmedBy !== null;
        $valid = match ($this->confirmationState) {
            MetadataFieldConfirmationState::Unknown => !$hasValue && !$hasActor,
            MetadataFieldConfirmationState::Unconfirmed => $hasValue && !$hasActor,
            MetadataFieldConfirmationState::UserConfirmed => $hasValue && $hasActor,
            MetadataFieldConfirmationState::IntentionallyBlank => !$hasValue && $hasActor,
        };
        if (!$valid) {
            throw new LogicException("Metadata field canonical state is inconsistent.");
        }
    }

    public function recordId(): MetadataRecordId { return $this->recordId; }
    public function field(): MetadataField { return $this->field; }
    public function confirmationState(): MetadataFieldConfirmationState
    {
        return $this->confirmationState;
    }
    public function canonicalValue(): ?MetadataFieldValue { return $this->canonicalValue; }
    public function confirmedBy(): ?UserId { return $this->confirmedBy; }
    public function version(): int { return $this->version; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    /** @return list<MetadataFieldProposal> */
    public function proposals(): array { return array_values($this->proposals); }

    /** @return list<MetadataFieldProposal> */
    public function activeProposals(): array
    {
        return array_values(array_filter(
            $this->proposals,
            static fn (MetadataFieldProposal $proposal): bool =>
                $proposal->state() === MetadataFieldProposalState::Active
        ));
    }

    public function hasConflict(): bool
    {
        return count($this->activeProposals()) > 1
            || ($this->canonicalValue !== null && $this->activeProposals() !== []);
    }
}
