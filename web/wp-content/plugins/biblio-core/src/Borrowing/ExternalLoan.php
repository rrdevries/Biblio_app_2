<?php

declare(strict_types=1);

namespace Biblio\Core\Borrowing;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use DateTimeImmutable;

final readonly class ExternalLoan
{
    public function __construct(
        private ExternalLoanId $id,
        private UserId $userId,
        private WorkId $workId,
        private ExternalLoanStatus $status,
        private DateTimeImmutable $borrowedAt,
        private ?DateTimeImmutable $dueAt
    ) {
    }

    public static function active(
        ExternalLoanId $id,
        UserId $userId,
        WorkId $workId,
        DateTimeImmutable $borrowedAt,
        ?DateTimeImmutable $dueAt = null
    ): self {
        return new self(
            $id,
            $userId,
            $workId,
            ExternalLoanStatus::Active,
            $borrowedAt,
            $dueAt
        );
    }

    public function id(): ExternalLoanId
    {
        return $this->id;
    }

    public function userId(): UserId
    {
        return $this->userId;
    }

    public function workId(): WorkId
    {
        return $this->workId;
    }

    public function status(): ExternalLoanStatus
    {
        return $this->status;
    }

    public function borrowedAt(): DateTimeImmutable
    {
        return $this->borrowedAt;
    }

    public function dueAt(): ?DateTimeImmutable
    {
        return $this->dueAt;
    }
}
