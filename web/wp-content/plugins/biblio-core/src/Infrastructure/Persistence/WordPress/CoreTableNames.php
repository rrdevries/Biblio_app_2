<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Exception\ValidationException;

final readonly class CoreTableNames
{
    private const MAX_TABLE_NAME_LENGTH = 64;

    private string $libraries;
    private string $memberships;
    private string $personalLibraryDesignations;
    private string $works;
    private string $editions;
    private string $items;
    private string $externalLoans;
    private string $readingRounds;
    private string $privateNotes;
    private string $ratings;
    private string $reviews;
    private string $contributionPublications;
    private string $nextReadingLists;
    private string $nextReadingEntries;
    private string $nextReadingUndo;
    private string $nextReadingInsertTrigger;
    private string $nextReadingUpdateTrigger;
    private string $nextReadingUndoInsertTrigger;
    private string $nextReadingUndoUpdateTrigger;
    private string $libraryBookTypes;
    private string $libraryGenres;
    private string $librarySubjects;
    private string $libraryCatalogContexts;
    private string $libraryCatalogContextGenres;
    private string $libraryCatalogContextSubjects;
    private string $libraryActivityEvents;
    private string $authors;
    private string $workContributors;
    private string $series;
    private string $workSeries;
    private string $workAlternateTitles;
    private string $workContainments;
    private string $locations;
    private string $itemArchivePeriods;
    private string $collections;
    private string $collectionMemberships;
    private string $editionIdentifierClaims;
    private string $editionMetadataProvenance;
    private string $metadataFieldStates;
    private string $metadataFieldValues;
    private string $metadataFieldEvidence;
    private string $metadataLookupSnapshots;
    private string $metadataLookupCandidates;
    private string $metadataUserObservations;
    private string $migrationRuns;
    private string $migrationRunLocks;
    private string $migrationSourceObservations;
    private string $migrationTargetMappings;
    private string $migrationQuarantine;
    private string $migrationPreservations;
    private string $personalWorkReadingLocks;
    private string $personalReadingTruths;

    public function __construct(string $prefix)
    {
        $this->libraries = $prefix . "biblio_libraries";
        $this->memberships = $prefix . "biblio_library_memberships";
        $this->personalLibraryDesignations = $prefix
            . "biblio_personal_library_designations";
        $this->works = $prefix . "biblio_works";
        $this->editions = $prefix . "biblio_editions";
        $this->items = $prefix . "biblio_items";
        $this->externalLoans = $prefix . "biblio_external_loans";
        $this->readingRounds = $prefix . "biblio_reading_rounds";
        $this->privateNotes = $prefix . "biblio_private_notes";
        $this->ratings = $prefix . "biblio_ratings";
        $this->reviews = $prefix . "biblio_reviews";
        $this->contributionPublications = $prefix
            . "biblio_contribution_publications";
        $this->nextReadingLists = $prefix . "biblio_next_reading_lists";
        $this->nextReadingEntries = $prefix . "biblio_next_reading_entries";
        $this->nextReadingUndo = $prefix . "biblio_next_reading_undo";
        $this->nextReadingInsertTrigger = $prefix . "biblio_nr_entry_bi";
        $this->nextReadingUpdateTrigger = $prefix . "biblio_nr_entry_bu";
        $this->nextReadingUndoInsertTrigger = $prefix . "biblio_nr_undo_bi";
        $this->nextReadingUndoUpdateTrigger = $prefix . "biblio_nr_undo_bu";
        $this->libraryBookTypes = $prefix . "biblio_library_book_types";
        $this->libraryGenres = $prefix . "biblio_library_genres";
        $this->librarySubjects = $prefix . "biblio_library_subjects";
        $this->libraryCatalogContexts = $prefix
            . "biblio_library_catalog_contexts";
        $this->libraryCatalogContextGenres = $prefix
            . "biblio_library_catalog_context_genres";
        $this->libraryCatalogContextSubjects = $prefix
            . "biblio_library_catalog_context_subjects";
        $this->libraryActivityEvents = $prefix
            . "biblio_library_activity_events";
        $this->authors = $prefix . "biblio_authors";
        $this->workContributors = $prefix . "biblio_work_contributors";
        $this->series = $prefix . "biblio_series";
        $this->workSeries = $prefix . "biblio_work_series";
        $this->workAlternateTitles = $prefix . "biblio_work_alternate_titles";
        $this->workContainments = $prefix . "biblio_work_containments";
        $this->locations = $prefix . "biblio_locations";
        $this->itemArchivePeriods = $prefix . "biblio_item_archive_periods";
        $this->collections = $prefix . "biblio_collections";
        $this->collectionMemberships = $prefix . "biblio_collection_memberships";
        $this->editionIdentifierClaims = $prefix
            . "biblio_edition_identifier_claims";
        $this->editionMetadataProvenance = $prefix
            . "biblio_edition_metadata_provenance";
        $this->metadataFieldStates = $prefix . "biblio_metadata_field_states";
        $this->metadataFieldValues = $prefix . "biblio_metadata_field_values";
        $this->metadataFieldEvidence = $prefix . "biblio_metadata_field_evidence";
        $this->metadataLookupSnapshots = $prefix . "biblio_metadata_lookup_snapshots";
        $this->metadataLookupCandidates = $prefix . "biblio_metadata_lookup_candidates";
        $this->metadataUserObservations = $prefix . "biblio_metadata_user_observations";
        $this->migrationRuns = $prefix . "biblio_migration_runs";
        $this->migrationRunLocks = $prefix . "biblio_migration_run_locks";
        $this->migrationSourceObservations = $prefix
            . "biblio_migration_source_observations";
        $this->migrationTargetMappings = $prefix
            . "biblio_migration_target_mappings";
        $this->migrationQuarantine = $prefix . "biblio_migration_quarantine";
        $this->migrationPreservations = $prefix
            . "biblio_migration_preservations";
        $this->personalWorkReadingLocks = $prefix
            . "biblio_personal_work_reading_locks";
        $this->personalReadingTruths = $prefix
            . "biblio_personal_reading_truths";

        foreach ($this->schema1019() as $tableName) {
            $this->assertSafe($tableName);
        }
        $this->assertSafe($this->nextReadingInsertTrigger);
        $this->assertSafe($this->nextReadingUpdateTrigger);
        $this->assertSafe($this->nextReadingUndo);
        $this->assertSafe($this->nextReadingUndoInsertTrigger);
        $this->assertSafe($this->nextReadingUndoUpdateTrigger);
    }

    public function libraries(): string
    {
        return $this->libraries;
    }

    public function memberships(): string
    {
        return $this->memberships;
    }

    public function personalLibraryDesignations(): string
    {
        return $this->personalLibraryDesignations;
    }

    public function works(): string
    {
        return $this->works;
    }

    public function editions(): string
    {
        return $this->editions;
    }

    public function items(): string
    {
        return $this->items;
    }

    public function externalLoans(): string
    {
        return $this->externalLoans;
    }

    public function readingRounds(): string
    {
        return $this->readingRounds;
    }

    public function privateNotes(): string
    {
        return $this->privateNotes;
    }

    public function ratings(): string { return $this->ratings; }
    public function reviews(): string { return $this->reviews; }
    public function contributionPublications(): string
    {
        return $this->contributionPublications;
    }

    public function nextReadingLists(): string { return $this->nextReadingLists; }
    public function nextReadingEntries(): string { return $this->nextReadingEntries; }
    public function nextReadingUndo(): string { return $this->nextReadingUndo; }
    public function nextReadingInsertTrigger(): string { return $this->nextReadingInsertTrigger; }
    public function nextReadingUpdateTrigger(): string { return $this->nextReadingUpdateTrigger; }
    public function nextReadingUndoInsertTrigger(): string { return $this->nextReadingUndoInsertTrigger; }
    public function nextReadingUndoUpdateTrigger(): string { return $this->nextReadingUndoUpdateTrigger; }

    public function libraryBookTypes(): string
    {
        return $this->libraryBookTypes;
    }

    public function libraryGenres(): string
    {
        return $this->libraryGenres;
    }

    public function librarySubjects(): string
    {
        return $this->librarySubjects;
    }

    public function libraryCatalogContexts(): string
    {
        return $this->libraryCatalogContexts;
    }

    public function libraryCatalogContextGenres(): string
    {
        return $this->libraryCatalogContextGenres;
    }

    public function libraryCatalogContextSubjects(): string
    {
        return $this->libraryCatalogContextSubjects;
    }

    public function libraryActivityEvents(): string
    {
        return $this->libraryActivityEvents;
    }

    public function authors(): string { return $this->authors; }
    public function workContributors(): string { return $this->workContributors; }
    public function series(): string { return $this->series; }
    public function workSeries(): string { return $this->workSeries; }
    public function workAlternateTitles(): string
    {
        return $this->workAlternateTitles;
    }
    public function workContainments(): string { return $this->workContainments; }
    public function locations(): string { return $this->locations; }
    public function itemArchivePeriods(): string { return $this->itemArchivePeriods; }
    public function collections(): string { return $this->collections; }
    public function collectionMemberships(): string { return $this->collectionMemberships; }
    public function editionIdentifierClaims(): string
    {
        return $this->editionIdentifierClaims;
    }
    public function editionMetadataProvenance(): string
    {
        return $this->editionMetadataProvenance;
    }
    public function metadataFieldStates(): string { return $this->metadataFieldStates; }
    public function metadataFieldValues(): string { return $this->metadataFieldValues; }
    public function metadataFieldEvidence(): string { return $this->metadataFieldEvidence; }

    /** @return list<string> */
    public function all(): array
    {
        return [
            $this->libraries,
            $this->memberships,
            $this->personalLibraryDesignations,
            $this->works,
            $this->editions,
            $this->items,
            $this->externalLoans,
            $this->readingRounds,
        ];
    }

    /** @return list<string> */
    public function schema1001Additions(): array
    {
        return [
            $this->libraryBookTypes,
            $this->libraryGenres,
            $this->librarySubjects,
            $this->libraryCatalogContexts,
            $this->libraryCatalogContextGenres,
            $this->libraryCatalogContextSubjects,
            $this->libraryActivityEvents,
        ];
    }

    /** @return list<string> */
    public function schema1001(): array
    {
        return array_merge($this->all(), $this->schema1001Additions());
    }

    /** @return list<string> */
    public function schema1004(): array
    {
        return [...$this->schema1001(), $this->privateNotes];
    }

    /** @return list<string> */
    public function schema1005(): array
    {
        return [
            ...$this->schema1004(),
            $this->ratings,
            $this->reviews,
            $this->contributionPublications,
        ];
    }

    /** @return list<string> */
    public function schema1006(): array
    {
        return [...$this->schema1005(), $this->nextReadingLists, $this->nextReadingEntries];
    }

    /** @return list<string> */
    public function schema1008(): array
    {
        return [...$this->schema1006(), $this->nextReadingUndo];
    }

    /** @return list<string> */
    public function schema1009Additions(): array
    {
        return [
            $this->authors,
            $this->workContributors,
            $this->series,
            $this->workSeries,
        ];
    }

    /** @return list<string> */
    public function schema1009(): array
    {
        return [...$this->schema1008(), ...$this->schema1009Additions()];
    }

    /** @return list<string> */
    public function schema1010Additions(): array
    {
        return [$this->workAlternateTitles, $this->workContainments];
    }

    /** @return list<string> */
    public function schema1010(): array
    {
        return [...$this->schema1009(), ...$this->schema1010Additions()];
    }

    /** @return list<string> */
    public function schema1011Additions(): array
    {
        return [$this->locations];
    }

    /** @return list<string> */
    public function schema1011(): array
    {
        $tables = $this->schema1010();
        $itemOffset = array_search($this->items, $tables, true);
        array_splice($tables, (int) $itemOffset, 0, [$this->locations]);

        return $tables;
    }

    /** @return list<string> */
    public function schema1012Additions(): array
    {
        return [$this->itemArchivePeriods];
    }

    /** @return list<string> */
    public function schema1012(): array
    {
        $tables = $this->schema1011();
        $itemOffset = array_search($this->items, $tables, true);
        array_splice($tables, (int) $itemOffset + 1, 0, [$this->itemArchivePeriods]);

        return $tables;
    }

    /** @return list<string> */
    public function schema1013Additions(): array
    {
        return [$this->collections, $this->collectionMemberships];
    }

    /** @return list<string> */
    public function schema1013(): array
    {
        $tables = $this->schema1012();
        $itemOffset = array_search($this->items, $tables, true);
        array_splice($tables, (int) $itemOffset + 2, 0, $this->schema1013Additions());
        return $tables;
    }

    /** @return list<string> */
    public function schema1014Additions(): array
    {
        return [
            $this->editionIdentifierClaims,
            $this->editionMetadataProvenance,
        ];
    }

    /** @return list<string> */
    public function schema1014(): array
    {
        $tables = $this->schema1013();
        $editionOffset = array_search($this->editions, $tables, true);
        array_splice(
            $tables,
            (int) $editionOffset + 1,
            0,
            $this->schema1014Additions()
        );

        return $tables;
    }

    /** @return list<string> */
    public function schema1015Additions(): array
    {
        return [
            $this->metadataFieldStates,
            $this->metadataFieldValues,
            $this->metadataFieldEvidence,
        ];
    }

    /** @return list<string> */
    public function schema1015(): array
    {
        return [...$this->schema1014(), ...$this->schema1015Additions()];
    }

    /** @return list<string> */
    public function schema1016(): array
    {
        return $this->schema1015();
    }

    public function metadataLookupSnapshots(): string
    {
        return $this->metadataLookupSnapshots;
    }

    public function metadataLookupCandidates(): string
    {
        return $this->metadataLookupCandidates;
    }

    public function metadataUserObservations(): string
    {
        return $this->metadataUserObservations;
    }

    /** @return list<string> */
    public function schema1017Additions(): array
    {
        return [
            $this->metadataLookupSnapshots,
            $this->metadataLookupCandidates,
            $this->metadataUserObservations,
        ];
    }

    /** @return list<string> */
    public function schema1017(): array
    {
        return [...$this->schema1016(), ...$this->schema1017Additions()];
    }

    public function migrationRuns(): string { return $this->migrationRuns; }
    public function migrationRunLocks(): string { return $this->migrationRunLocks; }
    public function migrationSourceObservations(): string
    {
        return $this->migrationSourceObservations;
    }
    public function migrationTargetMappings(): string
    {
        return $this->migrationTargetMappings;
    }
    public function migrationQuarantine(): string
    {
        return $this->migrationQuarantine;
    }
    public function migrationPreservations(): string
    {
        return $this->migrationPreservations;
    }

    /** @return list<string> */
    public function schema1018Additions(): array
    {
        return [
            $this->migrationRuns,
            $this->migrationRunLocks,
            $this->migrationSourceObservations,
            $this->migrationTargetMappings,
            $this->migrationQuarantine,
            $this->migrationPreservations,
        ];
    }

    /** @return list<string> */
    public function schema1018(): array
    {
        return [...$this->schema1017(), ...$this->schema1018Additions()];
    }

    public function personalWorkReadingLocks(): string
    {
        return $this->personalWorkReadingLocks;
    }

    public function personalReadingTruths(): string
    {
        return $this->personalReadingTruths;
    }

    /** @return list<string> */
    public function schema1019Additions(): array
    {
        return [
            $this->personalWorkReadingLocks,
            $this->personalReadingTruths,
        ];
    }

    /** @return list<string> */
    public function schema1019(): array
    {
        return [...$this->schema1018(), ...$this->schema1019Additions()];
    }

    private function assertSafe(string $tableName): void
    {
        if (
            preg_match('/^[a-zA-Z0-9_]+$/', $tableName) !== 1
            || strlen($tableName) > self::MAX_TABLE_NAME_LENGTH
        ) {
            throw new ValidationException("Unsafe database table name.");
        }
    }
}
