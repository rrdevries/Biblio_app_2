<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Application\Catalog\LocalEditionResolutionType;
use Biblio\Core\Application\Library\LibraryContextView;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use LogicException;

final readonly class AddBookMetadataLookupResult
{
    /**
     * @param list<AddBookExistingEdition> $localMatches
     */
    private function __construct(
        private LibraryContextView $library,
        private CanonicalIsbnIdentity $identifier,
        private LocalEditionResolutionType $localStatus,
        private array $localMatches,
        private ?FirstSufficientMetadataLookupResult $metadata,
        private AddBookMetadataReviewPolicy $reviewPolicy,
        private ?MetadataLookupId $lookupId
    ) {
        if (
            ($localStatus === LocalEditionResolutionType::LocalNone)
                !== ($metadata !== null)
        ) {
            throw new LogicException(
                "Only a local miss may contain a provider lookup result."
            );
        }

        $hasProviderCandidates = $metadata !== null
            && $metadata->candidates() !== [];
        if (($lookupId !== null) !== $hasProviderCandidates) {
            throw new LogicException(
                "Only provider candidates may have a lookup snapshot."
            );
        }

        if (
            ($localStatus === LocalEditionResolutionType::LocalExact
                && count($localMatches) !== 1)
            || ($localStatus === LocalEditionResolutionType::LocalNone
                && $localMatches !== [])
            || ($localStatus === LocalEditionResolutionType::LocalAmbiguous
                && count($localMatches) < 2)
        ) {
            throw new LogicException(
                "Local Add Book status and matches disagree."
            );
        }
    }

    /** @param list<AddBookExistingEdition> $matches */
    public static function local(
        LibraryContextView $library,
        CanonicalIsbnIdentity $identifier,
        LocalEditionResolutionType $status,
        array $matches,
        AddBookMetadataReviewPolicy $reviewPolicy
    ): self {
        return new self(
            $library,
            $identifier,
            $status,
            $matches,
            null,
            $reviewPolicy,
            null
        );
    }

    public static function fromMetadata(
        LibraryContextView $library,
        CanonicalIsbnIdentity $identifier,
        FirstSufficientMetadataLookupResult $metadata,
        AddBookMetadataReviewPolicy $reviewPolicy,
        ?MetadataLookupId $lookupId
    ): self {
        return new self(
            $library,
            $identifier,
            LocalEditionResolutionType::LocalNone,
            [],
            $metadata,
            $reviewPolicy,
            $lookupId
        );
    }

    public function library(): LibraryContextView { return $this->library; }
    public function identifier(): CanonicalIsbnIdentity { return $this->identifier; }
    public function localStatus(): LocalEditionResolutionType { return $this->localStatus; }

    /** @return list<AddBookExistingEdition> */
    public function localMatches(): array { return $this->localMatches; }

    public function metadataResult(): ?FirstSufficientMetadataLookupResult
    {
        return $this->metadata;
    }

    public function reviewPolicy(): AddBookMetadataReviewPolicy
    {
        return $this->reviewPolicy;
    }

    public function lookupId(): ?MetadataLookupId { return $this->lookupId; }
}
