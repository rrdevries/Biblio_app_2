<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use Biblio\Core\Catalog\{AuthorId,ContributorPosition,ContributorRole,WorkId};
use Biblio\Core\Exception\ValidationException;
use DateTimeImmutable;

final readonly class AuthorContributorCredit
{
    public function __construct(
        private AuthorContributorCreditId $id,
        private AuthorContributorCreditKey $key,
        private WorkId $workId,
        private ContributorRole $role,
        private ContributorPosition $position,
        private string $observedDisplayName,
        private ?AuthorId $authorId,
        private AuthorContributorCreditStatus $status,
        private ?AuthorCreditReviewReason $reviewReason,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private AuthorContributorCreditVersion $version
    ) {
        AuthorContributorCreditKey::normalizeObservedName($observedDisplayName);
        if ($updatedAt < $createdAt) {
            throw new ValidationException(
                "Author contributor credit update time precedes creation time."
            );
        }
        if (
            ($status === AuthorContributorCreditStatus::Linked
                && $authorId === null)
            || ($status === AuthorContributorCreditStatus::Unresolved
                && ($authorId !== null || $reviewReason === null))
        ) {
            throw new ValidationException(
                "Author contributor credit link state is inconsistent."
            );
        }
    }

    public function id(): AuthorContributorCreditId { return $this->id; }
    public function key(): AuthorContributorCreditKey { return $this->key; }
    public function workId(): WorkId { return $this->workId; }
    public function role(): ContributorRole { return $this->role; }
    public function position(): ContributorPosition { return $this->position; }
    public function observedDisplayName(): string
    {
        return $this->observedDisplayName;
    }
    public function authorId(): ?AuthorId { return $this->authorId; }
    public function status(): AuthorContributorCreditStatus
    {
        return $this->status;
    }
    public function reviewReason(): ?AuthorCreditReviewReason
    {
        return $this->reviewReason;
    }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function version(): AuthorContributorCreditVersion
    {
        return $this->version;
    }
}
