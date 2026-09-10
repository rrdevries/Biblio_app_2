<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

use Biblio\Core\Application\Metadata\MetadataLookupId;
use Biblio\Core\Identity\UserId;
use DateTimeImmutable;
use LogicException;

final readonly class BibliographicDiscoverySnapshot
{
    /** @var array<string, BibliographicDiscoveryCandidate> */
    private array $candidates;

    /** @param list<BibliographicDiscoveryCandidate> $candidates */
    public function __construct(
        private MetadataLookupId $id,
        private UserId $actorId,
        private BibliographicDiscoveryQuery $query,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $expiresAt,
        array $candidates
    ) {
        if ($expiresAt <= $createdAt || $candidates === []) {
            throw new LogicException("Invalid bibliographic discovery snapshot.");
        }
        $indexed = [];
        foreach ($candidates as $candidate) {
            if ($candidate->type() !== BibliographicCandidateType::ExternalWork
                && $candidate->type() !== BibliographicCandidateType::ExternalEdition) {
                throw new LogicException("Only external candidates require snapshots.");
            }
            if ($candidate->queryType() !== $query->type()
                || $candidate->normalizedQuery() !== $query->normalizedValue()) {
                throw new LogicException("Candidate query does not match snapshot.");
            }
            if (isset($indexed[$candidate->id()])) {
                throw new LogicException("Duplicate discovery candidate identity.");
            }
            $indexed[$candidate->id()] = $candidate;
        }
        $this->candidates = $indexed;
    }

    public function id(): MetadataLookupId { return $this->id; }
    public function actorId(): UserId { return $this->actorId; }
    public function query(): BibliographicDiscoveryQuery { return $this->query; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
    /** @return array<string,BibliographicDiscoveryCandidate> */ public function candidates(): array { return $this->candidates; }
}
