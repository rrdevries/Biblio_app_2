<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

interface AuthorContributorCreditRepository
{
    public function findByKey(
        AuthorContributorCreditKey $key
    ): ?AuthorContributorCredit;

    public function create(
        AuthorContributorCredit $credit
    ): AuthorContributorCredit;

    public function observeEvidence(AuthorCreditEvidence $evidence): void;

    /** @return list<AuthorCreditEvidence> */
    public function evidenceForCredit(
        AuthorContributorCreditId $creditId
    ): array;
}
