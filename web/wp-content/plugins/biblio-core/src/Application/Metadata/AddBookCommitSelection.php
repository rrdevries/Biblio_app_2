<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\WorkId;
use LogicException;

final readonly class AddBookCommitSelection
{
    private function __construct(
        private AddBookSelectionType $type,
        private ?MetadataLookupId $lookupId,
        private ?MetadataCandidateId $candidateId,
        private ?EditionId $editionId,
        private ?WorkId $workId
    ) {
        if (
            ($type === AddBookSelectionType::Candidate)
                !== ($lookupId !== null && $candidateId !== null)
        ) {
            throw new LogicException("Invalid Add Book selection.");
        }
        if (
            ($type === AddBookSelectionType::ExistingEdition)
                !== ($editionId !== null)
            || ($type === AddBookSelectionType::Manual) !== (
                $lookupId === null
                && $candidateId === null
                && $editionId === null
            )
            || ($workId !== null && $type !== AddBookSelectionType::Manual)
        ) {
            throw new LogicException("Invalid Add Book selection.");
        }
    }

    public static function manual(?WorkId $workId = null): self
    {
        return new self(
            AddBookSelectionType::Manual,
            null,
            null,
            null,
            $workId
        );
    }

    public static function candidate(
        MetadataLookupId $lookupId,
        MetadataCandidateId $candidateId
    ): self {
        return new self(
            AddBookSelectionType::Candidate,
            $lookupId,
            $candidateId,
            null,
            null
        );
    }

    public static function existingEdition(EditionId $editionId): self
    {
        return new self(
            AddBookSelectionType::ExistingEdition,
            null,
            null,
            $editionId,
            null
        );
    }

    public function type(): AddBookSelectionType { return $this->type; }
    public function lookupId(): ?MetadataLookupId { return $this->lookupId; }
    public function candidateId(): ?MetadataCandidateId { return $this->candidateId; }
    public function editionId(): ?EditionId { return $this->editionId; }
    public function workId(): ?WorkId { return $this->workId; }
}
