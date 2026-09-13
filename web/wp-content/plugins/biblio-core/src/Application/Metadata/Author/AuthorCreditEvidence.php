<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use Biblio\Core\Catalog\{ContributorPosition,ContributorRole};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\IdentifierConstraints;
use DateTimeImmutable;

final readonly class AuthorCreditEvidence
{
    private string $evidenceId;

    private function __construct(
        private AuthorContributorCreditId $creditId,
        private AuthorCreditEvidenceSourceKind $sourceKind,
        private AuthorContributorCreditSourceIdentity $sourceIdentity,
        private ?string $provider,
        private ?string $sourceEntityType,
        private ?string $sourceRecordId,
        private ?string $strongProviderAuthorId,
        private string $observedDisplayName,
        private ContributorRole $role,
        private ContributorPosition $sourcePosition,
        private DateTimeImmutable $firstObservedAt,
        private DateTimeImmutable $lastObservedAt,
        private int $observationCount
    ) {
        AuthorContributorCreditKey::normalizeObservedName($observedDisplayName);
        if ($lastObservedAt < $firstObservedAt || $observationCount < 1) {
            throw new ValidationException(
                "Author credit evidence observation history is invalid."
            );
        }
        $isProvider = $sourceKind === AuthorCreditEvidenceSourceKind::Provider;
        if ($isProvider) {
            if (
                $provider === null
                || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $provider) !== 1
                || !in_array($sourceEntityType, ["work", "edition"], true)
                || $sourceRecordId === null
            ) {
                throw new ValidationException(
                    "Provider Author credit evidence shape is invalid."
                );
            }
            IdentifierConstraints::assertValid(
                $sourceRecordId,
                "Provider source record ID"
            );
            if ($strongProviderAuthorId !== null) {
                IdentifierConstraints::assertValid(
                    $strongProviderAuthorId,
                    "Strong provider Author ID"
                );
            }
            $expectedSourceIdentity = AuthorContributorCreditSourceIdentity::provider(
                $provider,
                $sourceEntityType,
                $sourceRecordId,
                $sourcePosition->value()
            );
        } else {
            if (
                $provider !== null
                || $sourceEntityType !== null
                || $sourceRecordId === null
                || $strongProviderAuthorId !== null
            ) {
                throw new ValidationException(
                    "Non-provider Author credit evidence shape is invalid."
                );
            }
            IdentifierConstraints::assertValid(
                $sourceRecordId,
                "Author credit source observation ID"
            );
            $expectedSourceIdentity = $sourceKind
                === AuthorCreditEvidenceSourceKind::UserObservation
                ? AuthorContributorCreditSourceIdentity::userObservation(
                    $sourceRecordId
                )
                : AuthorContributorCreditSourceIdentity::migration(
                    $sourceRecordId
                );
        }
        if ($expectedSourceIdentity->value() !== $sourceIdentity->value()) {
            throw new ValidationException(
                "Author credit source identity does not match its traceable source."
            );
        }

        $this->evidenceId = hash("sha256", implode("\0", [
            "author-credit-evidence-v1",
            $sourceKind->value,
            $sourceIdentity->value(),
            $provider ?? "",
            $sourceEntityType ?? "",
            $sourceRecordId,
            $strongProviderAuthorId ?? "",
            $observedDisplayName,
            $role->value,
            (string) $sourcePosition->value(),
        ]));
    }

    public static function provider(
        AuthorContributorCreditId $creditId,
        string $provider,
        string $sourceEntityType,
        string $sourceRecordId,
        ?string $strongProviderAuthorId,
        string $observedDisplayName,
        ContributorRole $role,
        ContributorPosition $sourcePosition,
        DateTimeImmutable $observedAt
    ): self {
        return new self(
            $creditId,
            AuthorCreditEvidenceSourceKind::Provider,
            AuthorContributorCreditSourceIdentity::provider(
                $provider,
                $sourceEntityType,
                $sourceRecordId,
                $sourcePosition->value()
            ),
            $provider,
            $sourceEntityType,
            $sourceRecordId,
            $strongProviderAuthorId,
            $observedDisplayName,
            $role,
            $sourcePosition,
            $observedAt,
            $observedAt,
            1
        );
    }

    public static function userObservation(
        AuthorContributorCreditId $creditId,
        string $observationId,
        string $observedDisplayName,
        ContributorRole $role,
        ContributorPosition $sourcePosition,
        DateTimeImmutable $observedAt
    ): self {
        return self::nonProvider(
            $creditId,
            AuthorCreditEvidenceSourceKind::UserObservation,
            $observationId,
            $observedDisplayName,
            $role,
            $sourcePosition,
            $observedAt
        );
    }

    public static function migration(
        AuthorContributorCreditId $creditId,
        string $observationId,
        string $observedDisplayName,
        ContributorRole $role,
        ContributorPosition $sourcePosition,
        DateTimeImmutable $observedAt
    ): self {
        return self::nonProvider(
            $creditId,
            AuthorCreditEvidenceSourceKind::Migration,
            $observationId,
            $observedDisplayName,
            $role,
            $sourcePosition,
            $observedAt
        );
    }

    public static function stored(
        AuthorContributorCreditId $creditId,
        AuthorCreditEvidenceSourceKind $sourceKind,
        AuthorContributorCreditSourceIdentity $sourceIdentity,
        ?string $provider,
        ?string $sourceEntityType,
        ?string $sourceRecordId,
        ?string $strongProviderAuthorId,
        string $observedDisplayName,
        ContributorRole $role,
        ContributorPosition $sourcePosition,
        DateTimeImmutable $firstObservedAt,
        DateTimeImmutable $lastObservedAt,
        int $observationCount
    ): self {
        return new self(
            $creditId,
            $sourceKind,
            $sourceIdentity,
            $provider,
            $sourceEntityType,
            $sourceRecordId,
            $strongProviderAuthorId,
            $observedDisplayName,
            $role,
            $sourcePosition,
            $firstObservedAt,
            $lastObservedAt,
            $observationCount
        );
    }

    public function creditId(): AuthorContributorCreditId
    {
        return $this->creditId;
    }
    public function evidenceId(): string { return $this->evidenceId; }
    public function sourceKind(): AuthorCreditEvidenceSourceKind
    {
        return $this->sourceKind;
    }
    public function sourceIdentity(): AuthorContributorCreditSourceIdentity
    {
        return $this->sourceIdentity;
    }
    public function providerKey(): ?string { return $this->provider; }
    public function sourceEntityType(): ?string { return $this->sourceEntityType; }
    public function sourceRecordId(): ?string { return $this->sourceRecordId; }
    public function strongProviderAuthorId(): ?string
    {
        return $this->strongProviderAuthorId;
    }
    public function observedDisplayName(): string
    {
        return $this->observedDisplayName;
    }
    public function role(): ContributorRole { return $this->role; }
    public function sourcePosition(): ContributorPosition
    {
        return $this->sourcePosition;
    }
    public function firstObservedAt(): DateTimeImmutable
    {
        return $this->firstObservedAt;
    }
    public function lastObservedAt(): DateTimeImmutable
    {
        return $this->lastObservedAt;
    }
    public function observationCount(): int { return $this->observationCount; }

    private static function nonProvider(
        AuthorContributorCreditId $creditId,
        AuthorCreditEvidenceSourceKind $sourceKind,
        string $observationId,
        string $observedDisplayName,
        ContributorRole $role,
        ContributorPosition $sourcePosition,
        DateTimeImmutable $observedAt
    ): self {
        $sourceIdentity = match ($sourceKind) {
            AuthorCreditEvidenceSourceKind::UserObservation =>
                AuthorContributorCreditSourceIdentity::userObservation(
                    $observationId
                ),
            AuthorCreditEvidenceSourceKind::Migration =>
                AuthorContributorCreditSourceIdentity::migration($observationId),
            AuthorCreditEvidenceSourceKind::Provider => throw new \LogicException(
                "Provider evidence requires the provider factory."
            ),
        };
        return new self(
            $creditId,
            $sourceKind,
            $sourceIdentity,
            null,
            null,
            $observationId,
            null,
            $observedDisplayName,
            $role,
            $sourcePosition,
            $observedAt,
            $observedAt,
            1
        );
    }
}
