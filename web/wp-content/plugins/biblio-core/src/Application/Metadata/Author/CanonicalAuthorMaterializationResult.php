<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

use Biblio\Core\Catalog\{AuthorId,AuthorIdentityStatus};

final readonly class CanonicalAuthorMaterializationResult
{
    public function __construct(
        private AuthorMaterializationStatus $status,
        private ?AuthorId $authorId,
        private AuthorContributorCreditId $creditId,
        private ?AuthorIdentityStatus $authorIdentityStatus,
        private AuthorMaterializationWriteDisposition $author,
        private AuthorMaterializationWriteDisposition $providerClaim,
        private AuthorMaterializationWriteDisposition $credit,
        private AuthorMaterializationWriteDisposition $evidence,
        private AuthorMaterializationWriteDisposition $contributorEdge
    ) {
    }

    public function status(): AuthorMaterializationStatus { return $this->status; }
    public function authorId(): ?AuthorId { return $this->authorId; }
    public function creditId(): AuthorContributorCreditId { return $this->creditId; }
    public function authorIdentityStatus(): ?AuthorIdentityStatus
    {
        return $this->authorIdentityStatus;
    }
    public function author(): AuthorMaterializationWriteDisposition
    {
        return $this->author;
    }
    public function providerClaim(): AuthorMaterializationWriteDisposition
    {
        return $this->providerClaim;
    }
    public function credit(): AuthorMaterializationWriteDisposition
    {
        return $this->credit;
    }
    public function evidence(): AuthorMaterializationWriteDisposition
    {
        return $this->evidence;
    }
    public function contributorEdge(): AuthorMaterializationWriteDisposition
    {
        return $this->contributorEdge;
    }
}
