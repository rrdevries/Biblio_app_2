<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

interface BibliographicMetadataRepository
{
    /**
     * @param list<WorkId> $workIds
     * @return array<string, list<AlternateWorkTitle>>
     */
    public function alternateTitlesForWorks(array $workIds): array;

    /**
     * @param list<WorkId> $workIds
     * @return array<string, list<Edition>>
     */
    public function editionsForWorks(array $workIds): array;

    /**
     * @param list<Isbn> $isbns
     * @return array<string, list<Edition>>
     */
    public function editionsForIsbns(array $isbns): array;

    /**
     * @param list<WorkId> $parentWorkIds
     * @return array<string, list<WorkContainment>>
     */
    public function containedWorksForParents(array $parentWorkIds): array;

    /**
     * @param list<WorkId> $containedWorkIds
     * @return array<string, list<WorkContainment>>
     */
    public function parentWorksForContained(array $containedWorkIds): array;
}
