<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;
use LogicException;

final readonly class MetadataLookupSnapshot
{
    /** @var array<string, MetadataCandidate> */
    private array $candidates;

    /** @param list<MetadataCandidate> $candidates */
    public function __construct(
        private MetadataLookupId $id,
        private UserId $actorId,
        private LibraryId $libraryId,
        private CanonicalIsbnIdentity $identifier,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $expiresAt,
        array $candidates
    ) {
        if ($expiresAt <= $createdAt || $candidates === []) {
            throw new LogicException("Invalid metadata lookup snapshot.");
        }

        $indexedCandidates = [];
        foreach ($candidates as $candidate) {
            if (
                $candidate->queriedIsbn()->isbn13()->value()
                    !== $identifier->isbn13()->value()
                || $candidate->returnedIsbn()->isbn13()->value()
                    !== $identifier->isbn13()->value()
            ) {
                throw new LogicException(
                    "Snapshot candidate does not match the lookup ISBN."
                );
            }

            $candidateId = MetadataCandidateId::fromCandidate($candidate)->value();
            if (isset($indexedCandidates[$candidateId])) {
                throw new LogicException("Duplicate snapshot candidate identity.");
            }
            $indexedCandidates[$candidateId] = $candidate;
        }
        $this->candidates = $indexedCandidates;
    }

    public function id(): MetadataLookupId { return $this->id; }
    public function actorId(): UserId { return $this->actorId; }
    public function libraryId(): LibraryId { return $this->libraryId; }
    public function identifier(): CanonicalIsbnIdentity { return $this->identifier; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }

    /** @return array<string, MetadataCandidate> */
    public function candidates(): array { return $this->candidates; }
}
