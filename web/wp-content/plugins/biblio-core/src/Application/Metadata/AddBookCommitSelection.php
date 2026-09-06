<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use LogicException;

final readonly class AddBookCommitSelection
{
    private function __construct(
        private AddBookSelectionType $type,
        private ?MetadataLookupId $lookupId,
        private ?MetadataCandidateId $candidateId
    ) {
        if (
            ($type === AddBookSelectionType::Candidate)
                !== ($lookupId !== null && $candidateId !== null)
        ) {
            throw new LogicException("Invalid Add Book selection.");
        }
    }

    public static function manual(): self
    {
        return new self(AddBookSelectionType::Manual, null, null);
    }

    public static function candidate(
        MetadataLookupId $lookupId,
        MetadataCandidateId $candidateId
    ): self {
        return new self(
            AddBookSelectionType::Candidate,
            $lookupId,
            $candidateId
        );
    }

    public function type(): AddBookSelectionType { return $this->type; }
    public function lookupId(): ?MetadataLookupId { return $this->lookupId; }
    public function candidateId(): ?MetadataCandidateId { return $this->candidateId; }
}
