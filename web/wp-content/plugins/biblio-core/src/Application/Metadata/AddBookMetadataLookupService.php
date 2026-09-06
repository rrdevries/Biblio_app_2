<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Application\Catalog\LocalEditionResolutionType;
use Biblio\Core\Application\Catalog\LocalEditionResolver;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\WorkRepository;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;

final readonly class AddBookMetadataLookupService
{
    public function __construct(
        private LibraryContextQueryService $libraryContexts,
        private LocalEditionResolver $localEditions,
        private WorkRepository $works,
        private FirstSufficientMetadataLookupService $metadata,
        private AddBookMetadataReviewPolicy $reviewPolicy
    ) {
    }

    public function lookup(
        LibraryId $libraryId,
        string $identifier
    ): AddBookMetadataLookupResult {
        $library = $this->libraryContexts->get($libraryId);

        if (!$library->capabilities()->canAddCatalogItem()) {
            throw new AuthorizationException(
                "Add Book is not available in this Library Context."
            );
        }

        $local = $this->localEditions->resolveInput($identifier);

        if ($local->type() === LocalEditionResolutionType::LocalNone) {
            return AddBookMetadataLookupResult::fromMetadata(
                $library,
                $local->identity(),
                $this->metadata->lookup($local->identity()),
                $this->reviewPolicy
            );
        }

        $matches = array_map(
            fn (Edition $edition): AddBookExistingEdition =>
                new AddBookExistingEdition(
                    $this->requireWork($edition),
                    $edition
                ),
            $local->editions()
        );

        return AddBookMetadataLookupResult::local(
            $library,
            $local->identity(),
            $local->type(),
            $matches,
            $this->reviewPolicy
        );
    }

    private function requireWork(Edition $edition): \Biblio\Core\Catalog\Work
    {
        $work = $this->works->find($edition->workId());

        if ($work === null) {
            throw new PersistenceException(
                "Existing Edition references a missing Work.",
                failureReason: FailureReason::PersistenceReadFailed
            );
        }

        return $work;
    }
}
