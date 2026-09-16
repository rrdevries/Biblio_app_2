<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Migration\Runner\MigrationPlanningTarget;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerFailure;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerReason;
use Biblio\Core\Application\Migration\Runner\MigrationSourceInspection;
use Biblio\Core\Application\Migration\Runner\MigrationSourceMappingFinding;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;
use Biblio\Core\Catalog\Classification\ClassificationSeedKey;
use Biblio\Core\Catalog\Classification\ClassificationTermStatus;
use Biblio\Core\Catalog\Classification\LibraryBookTypeId;
use Biblio\Core\Catalog\Classification\LibraryBookTypeRepository;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\Classification\LibraryGenreId;
use Biblio\Core\Catalog\Classification\LibraryGenreRepository;
use Biblio\Core\Library\LibraryId;

/**
 * D-MIG-CLASS-MAP-01: explicit CURRENT values to exact Library-local typed
 * targets. No normalized-name, display-name, synonym or fallback lookup exists.
 */
final readonly class CurrentV1ClassificationMapper
{
    public function __construct(
        private LibraryBookTypeRepository $bookTypes,
        private LibraryGenreRepository $genres,
        private CurrentV1ReviewedClassificationContract $contract =
            new CurrentV1ReviewedClassificationContract()
    ) {
    }

    /**
     * @param array<string,MigrationSourceRecord> $booksByKey
     * @param array<string,string> $representativeByBookId
     * @param array<string,true> $itemCandidateBookIds
     */
    public function map(
        MigrationSourceInspection $inspection,
        MigrationPlanningTarget $target,
        array $booksByKey,
        array $representativeByBookId,
        array $itemCandidateBookIds
    ): CurrentV1ClassificationMappingResult {
        if (!hash_equals(
            $this->contract->manifestSha256(),
            $inspection->package()->manifestDigest()
        )) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::SourceChanged,
                "The reviewed CURRENT classification contract does not match the source manifest."
            );
        }

        $libraryId = new LibraryId($target->libraryId());
        $bookTypeIds = $this->resolveBookTypes($libraryId);
        $genreIds = $this->resolveGenres($libraryId);
        $findings = [];
        $proposed = [];
        $ready = [];
        $recordsById = [];

        foreach ($booksByKey as $book) {
            $bookId = $book->sourceId();
            $recordsById[$bookId] = $book;
            $payload = $book->payload();
            $bookType = $payload["bookType"] ?? null;
            if (!is_string($bookType) || trim($bookType) === "") {
                $findings[] = $this->finding(
                    $book,
                    MigrationDisposition::Quarantined,
                    CurrentV1ClassificationMappingReason::UnknownBookTypeBlocked
                );
                continue;
            }
            $bookTypeSeed = $this->contract->bookTypeSeedKey($bookType);
            if ($bookTypeSeed === null || !isset($bookTypeIds[$bookTypeSeed])) {
                $findings[] = $this->finding(
                    $book,
                    MigrationDisposition::PreservedDeferred,
                    CurrentV1ClassificationMappingReason::UnknownBookTypeBlocked
                );
                continue;
            }
            $findings[] = $this->finding(
                $book,
                in_array($bookType, ["Jeugdboek", "Kinderboek"], true)
                    ? MigrationDisposition::Transformed
                    : MigrationDisposition::Mapped,
                $this->bookTypeReason($bookType)
            );

            $mappedGenres = [];
            $rawGenres = $this->stringList($payload["genres"] ?? [], "genres");
            foreach ($rawGenres as $rawGenre) {
                $seed = $this->contract->genreSeedKey($rawGenre);
                if ($seed === null) {
                    $findings[] = $this->finding(
                        $book,
                        MigrationDisposition::PreservedDeferred,
                        CurrentV1ClassificationMappingReason::GenreAssignmentPreserved
                    );
                    continue;
                }
                $mappedGenres[] = $genreIds[$seed];
                $findings[] = $this->finding(
                    $book,
                    MigrationDisposition::Mapped,
                    $this->genreReason($rawGenre)
                );
            }
            foreach ($this->stringList($payload["categories"] ?? [], "categories") as $_) {
                $findings[] = $this->finding(
                    $book,
                    MigrationDisposition::PreservedDeferred,
                    CurrentV1ClassificationMappingReason::CategoryAssignmentPreserved
                );
            }

            $selection = new LibraryCatalogSelection(
                $bookTypeIds[$bookTypeSeed],
                $mappedGenres
            );
            $proposed[$bookId] = $selection;
            $reviewState = $this->reviewState($payload);
            $approval = $this->contract->perBookApproval(
                $bookId,
                $book->payloadHash(),
                $bookType,
                $reviewState
            );
            $requiresApproval = in_array(
                $reviewState,
                ["review", "no_signal"],
                true
            );
            if ($requiresApproval && $approval === null) {
                $findings[] = $this->finding(
                    $book,
                    MigrationDisposition::PreservedDeferred,
                    CurrentV1ClassificationMappingReason::SourceReviewBlocked
                );
                $ready[$bookId] = false;
            } else {
                if ($requiresApproval) {
                    $selection = $this->approvedSelection(
                        $approval,
                        $bookTypeIds,
                        $genreIds
                    );
                    $proposed[$bookId] = $selection;
                }
                $ready[$bookId] = true;
            }
        }

        $groups = [];
        foreach ($representativeByBookId as $bookId => $representative) {
            if (isset($proposed[$bookId])) {
                $groups[$representative][] = $bookId;
            }
        }
        foreach ($groups as $representative => $memberIds) {
            if (count($memberIds) < 2) {
                continue;
            }
            sort($memberIds, SORT_STRING);
            $first = $proposed[$memberIds[0]];
            $conflict = false;
            foreach (array_slice($memberIds, 1) as $memberId) {
                if (!$first->equals($proposed[$memberId])) {
                    $conflict = true;
                    break;
                }
            }
            if (!$conflict) {
                continue;
            }
            foreach ($memberIds as $memberId) {
                $ready[$memberId] = false;
            }
            $record = $recordsById[$representative] ?? $recordsById[$memberIds[0]];
            $findings[] = $this->finding(
                $record,
                MigrationDisposition::Quarantined,
                CurrentV1ClassificationMappingReason::ConvergedConflict
            );
            foreach ($memberIds as $memberId) {
                $member = $recordsById[$memberId];
                $findings[] = new MigrationSourceMappingFinding(
                    "v1.classification_conflict_member",
                    $representative . ":" . $memberId . ":" . $member->payloadHash(),
                    MigrationDisposition::Quarantined,
                    CurrentV1ClassificationMappingReason::ConvergedConflictMember->value
                );
            }
        }

        $selections = [];
        foreach (array_keys($itemCandidateBookIds) as $bookId) {
            if (($ready[$bookId] ?? false) !== true || !isset($proposed[$bookId])) {
                continue;
            }
            $selections[$bookId] = $proposed[$bookId];
            $findings[] = $this->finding(
                $recordsById[$bookId],
                MigrationDisposition::Mapped,
                CurrentV1ClassificationMappingReason::ClassificationReady
            );
        }

        $adapter = $inspection->adapter();
        $findings[] = new MigrationSourceMappingFinding(
            "v1.classification_auxiliary",
            "mapping_contract:" . $this->contract->identity() . ":"
                . substr($this->contract->manifestSha256(), 0, 24),
            MigrationDisposition::Mapped,
            CurrentV1ClassificationMappingReason::MappingContractApplied->value
        );
        if ($adapter instanceof CurrentV1SourceAdapter && $inspection->package()->files() !== []) {
            $this->auxiliaryFindings(
                $adapter->classificationEvidence($inspection->package()),
                $findings
            );
        }

        return new CurrentV1ClassificationMappingResult($selections, $findings);
    }

    /**
     * @param array{
     *   payload_hash:string,
     *   source_book_type_value:string,
     *   source_book_type_review_state:string,
     *   assignment_decision_status:string,
     *   book_type_seed_key:string,
     *   genre_seed_keys:list<string>,
     *   decision_provenance:string
     * } $approval
     * @param array<string,LibraryBookTypeId> $bookTypeIds
     * @param array<string,LibraryGenreId> $genreIds
     */
    private function approvedSelection(
        array $approval,
        array $bookTypeIds,
        array $genreIds
    ): LibraryCatalogSelection {
        $bookTypeId = $bookTypeIds[$approval["book_type_seed_key"]] ?? null;
        if ($bookTypeId === null) {
            throw $this->invalidTarget();
        }
        $approvedGenres = [];
        foreach ($approval["genre_seed_keys"] as $seed) {
            if (!isset($genreIds[$seed])) {
                throw $this->invalidTarget();
            }
            $approvedGenres[] = $genreIds[$seed];
        }

        return new LibraryCatalogSelection($bookTypeId, $approvedGenres);
    }

    /** @return array<string,LibraryBookTypeId> */
    private function resolveBookTypes(LibraryId $libraryId): array
    {
        $resolved = [];
        foreach (array_unique(array_values($this->contract->bookTypes())) as $seed) {
            $term = $this->bookTypes->findBySeedKey(
                $libraryId,
                new ClassificationSeedKey($seed)
            );
            if (
                $term === null
                || !$term->libraryId()->equals($libraryId)
                || $term->status() !== ClassificationTermStatus::Active
            ) {
                throw $this->invalidTarget();
            }
            $resolved[$seed] = $term->id();
        }
        return $resolved;
    }

    /** @return array<string,LibraryGenreId> */
    private function resolveGenres(LibraryId $libraryId): array
    {
        $resolved = [];
        foreach (array_unique(array_values($this->contract->genres())) as $seed) {
            $term = $this->genres->findBySeedKey(
                $libraryId,
                new ClassificationSeedKey($seed)
            );
            if (
                $term === null
                || !$term->libraryId()->equals($libraryId)
                || $term->status() !== ClassificationTermStatus::Active
            ) {
                throw $this->invalidTarget();
            }
            $resolved[$seed] = $term->id();
        }
        return $resolved;
    }

    private function invalidTarget(): MigrationRunnerFailure
    {
        return new MigrationRunnerFailure(
            MigrationRunnerReason::InvalidTarget,
            "An approved Library-local classification target is unavailable or inactive."
        );
    }

    /** @param array<string,mixed> $payload */
    private function reviewState(array $payload): ?string
    {
        $taxonomy = $payload["taxonomyMeta"] ?? null;
        if ($taxonomy === null) {
            return null;
        }
        if (!is_array($taxonomy) || array_is_list($taxonomy)) {
            throw $this->unsupportedClassificationStructure();
        }
        $bookType = $taxonomy["bookType"] ?? null;
        if ($bookType === null) {
            return null;
        }
        if (!is_array($bookType) || array_is_list($bookType)) {
            throw $this->unsupportedClassificationStructure();
        }
        $migration = $bookType["migration"] ?? null;
        if (!is_array($migration) || array_is_list($migration)) {
            throw $this->unsupportedClassificationStructure();
        }
        $state = $migration["status"] ?? null;
        if (!is_string($state) || !in_array(
            $state,
            ["migrate", "review", "no_signal"],
            true
        )) {
            throw $this->unsupportedClassificationStructure();
        }
        return $state;
    }

    private function unsupportedClassificationStructure(): MigrationRunnerFailure
    {
        return new MigrationRunnerFailure(
            MigrationRunnerReason::UnsupportedStructure,
            "CURRENT V1 Book Type review provenance is unsupported."
        );
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::UnsupportedStructure,
                "CURRENT V1 classification assignment structure is unsupported."
            );
        }
        foreach ($value as $entry) {
            if (!is_string($entry) || trim($entry) === "") {
                throw new MigrationRunnerFailure(
                    MigrationRunnerReason::UnsupportedStructure,
                    "CURRENT V1 {$field} assignment is malformed."
                );
            }
        }
        return $value;
    }

    private function bookTypeReason(string $raw): CurrentV1ClassificationMappingReason
    {
        return match ($raw) {
            "Leesboek" => CurrentV1ClassificationMappingReason::BookTypeLeesboekMapped,
            "Kennisboek" => CurrentV1ClassificationMappingReason::BookTypeKennisboekMapped,
            "Kookboek" => CurrentV1ClassificationMappingReason::BookTypeKookboekMapped,
            "Stripboek" => CurrentV1ClassificationMappingReason::BookTypeStripboekMapped,
            "Studieboek" => CurrentV1ClassificationMappingReason::BookTypeStudieboekMapped,
            "Jeugdboek" => CurrentV1ClassificationMappingReason::BookTypeJeugdboekReviewedMapping,
            "Kinderboek" => CurrentV1ClassificationMappingReason::BookTypeKinderboekReviewedMapping,
            default => CurrentV1ClassificationMappingReason::UnknownBookTypeBlocked,
        };
    }

    private function genreReason(string $raw): CurrentV1ClassificationMappingReason
    {
        return match ($raw) {
            "Fantasy" => CurrentV1ClassificationMappingReason::GenreFantasyMapped,
            "Sciencefiction" => CurrentV1ClassificationMappingReason::GenreSciencefictionMapped,
            "Thriller" => CurrentV1ClassificationMappingReason::GenreThrillerMapped,
            default => CurrentV1ClassificationMappingReason::GenreAssignmentPreserved,
        };
    }

    /** @param list<MigrationSourceMappingFinding> $findings */
    private function auxiliaryFindings(
        CurrentV1ClassificationEvidence $evidence,
        array &$findings
    ): void {
        foreach ($evidence->definitions() as $definition) {
            $raw = $definition["raw_value"];
            $reason = CurrentV1ClassificationMappingReason::ClassificationDefinitionPreserved;
            $disposition = MigrationDisposition::PreservedDeferred;
            if ($definition["dimension"] === "book_type"
                && $this->contract->bookTypeSeedKey($raw) !== null) {
                $reason = in_array($raw, ["Jeugdboek", "Kinderboek"], true)
                    ? CurrentV1ClassificationMappingReason::ClassificationDefinitionReviewed
                    : CurrentV1ClassificationMappingReason::ClassificationDefinitionExact;
                $disposition = in_array($raw, ["Jeugdboek", "Kinderboek"], true)
                    ? MigrationDisposition::Transformed
                    : MigrationDisposition::Mapped;
            } elseif ($definition["dimension"] === "genre"
                && $this->contract->genreSeedKey($raw) !== null) {
                $reason = CurrentV1ClassificationMappingReason::ClassificationDefinitionExact;
                $disposition = MigrationDisposition::Mapped;
            }
            $findings[] = new MigrationSourceMappingFinding(
                "v1.classification_definition",
                $definition["dimension"] . ":" . $definition["status"] . ":" . $raw,
                $disposition,
                $reason->value
            );
        }
        $queue = $evidence->reviewQueue();
        if ($queue["count"] > 0) {
            $findings[] = new MigrationSourceMappingFinding(
                "v1.classification_auxiliary",
                "review_queue:" . substr($queue["sha256"], 0, 24),
                MigrationDisposition::PreservedDeferred,
                CurrentV1ClassificationMappingReason::TaxonomyReviewQueuePreserved->value,
                [],
                $queue["count"]
            );
        }
        $aliases = $evidence->aliasRules();
        foreach ($aliases["ids"] as $id) {
            $findings[] = new MigrationSourceMappingFinding(
                "v1.classification_alias_rule",
                $id . ":" . substr($aliases["sha256"], 0, 24),
                MigrationDisposition::PreservedDeferred,
                CurrentV1ClassificationMappingReason::TaxonomyAliasRulesPreserved->value
            );
        }
    }

    private function finding(
        MigrationSourceRecord $record,
        MigrationDisposition $disposition,
        CurrentV1ClassificationMappingReason $reason
    ): MigrationSourceMappingFinding {
        return new MigrationSourceMappingFinding(
            $record->sourceType(),
            $record->sourceId(),
            $disposition,
            $reason->value
        );
    }
}
