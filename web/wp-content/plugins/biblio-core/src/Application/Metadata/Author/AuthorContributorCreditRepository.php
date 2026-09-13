<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use DateTimeImmutable;

interface AuthorContributorCreditRepository
{
    public function findByKey(
        AuthorContributorCreditKey $key
    ): ?AuthorContributorCredit;

    public function create(
        AuthorContributorCredit $credit
    ): AuthorContributorCredit;

    public function observeEvidence(AuthorCreditEvidence $evidence): void;

    public function setReviewReasonIfVersionMatches(
        AuthorContributorCreditId $creditId,
        AuthorContributorCreditVersion $expectedVersion,
        AuthorCreditReviewReason $reason,
        DateTimeImmutable $updatedAt
    ): bool;

    /** @return list<AuthorCreditEvidence> */
    public function evidenceForCredit(
        AuthorContributorCreditId $creditId
    ): array;
}
