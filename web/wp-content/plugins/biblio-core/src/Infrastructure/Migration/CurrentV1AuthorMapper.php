<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Metadata\Author\AuthorContributorCreditKey;
use Biblio\Core\Application\Migration\Author\{
    CatalogAuthorMigrationParticipant,
    CatalogAuthorPlan,
    CatalogWorkContributorMigrationParticipant,
    CatalogWorkContributorPlan
};
use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\Runner\{
    MigrationRunnerFailure,
    MigrationRunnerReason,
    MigrationSourceInspection,
    MigrationSourceMappingFinding,
    MigrationSourceRecord
};
use Biblio\Core\Catalog\{ContributorPosition, ContributorRole};
use Biblio\Core\Exception\ValidationException;

/**
 * Manifest-bound CURRENT Author semantic bridge. It emits only existing AUTH
 * typed plans and never performs product or ledger writes.
 */
final readonly class CurrentV1AuthorMapper
{
    public function __construct(
        private CurrentV1ReviewedAuthorContract $contract =
            new CurrentV1ReviewedAuthorContract()
    ) {
    }

    /**
     * @param array<string,MigrationSourceRecord> $authorsByKey
     * @param array<string,MigrationSourceRecord> $booksByKey
     * @param array<string,string> $workRepresentativeByBookId
     */
    public function map(
        MigrationSourceInspection $inspection,
        array $authorsByKey,
        array $booksByKey,
        array $workRepresentativeByBookId,
        bool $includeContainedWorkFindings = true
    ): CurrentV1AuthorMappingResult {
        if (!hash_equals(
            $this->contract->manifestSha256(),
            $inspection->package()->manifestDigest()
        )) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::SourceChanged,
                "The reviewed CURRENT Author contract does not match the source manifest."
            );
        }

        $authorPlans = [];
        $contributorPlans = [];
        $findings = [];
        $authors = [];
        $activeStableAuthorSources = [];

        foreach ($authorsByKey as $author) {
            $authorId = $author->sourceId();
            $payload = $author->payload();
            if (($payload["id"] ?? null) !== $authorId) {
                throw $this->unsupported(
                    "CURRENT V1 Author source identity and payload disagree."
                );
            }
            $authors[$authorId] = $author;
            $targetSourceId = CurrentV1AuthorSourceIds::stableAuthor($authorId);
            $exception = $this->contract->authorException($authorId);
            if ($exception !== null) {
                $findings[] = $this->finding($author, $exception);
                continue;
            }

            $displayName = $payload["displayName"] ?? null;
            if (!is_string($displayName)) {
                $findings[] = $this->finding(
                    $author,
                    CurrentV1AuthorMappingReason::InvalidAuthorDisplayName
                );
                continue;
            }
            try {
                $plan = new CatalogAuthorPlan($displayName);
                $record = MigrationSourceRecord::typed(
                    CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                    $targetSourceId,
                    $plan
                );
            } catch (ValidationException) {
                $findings[] = $this->finding(
                    $author,
                    CurrentV1AuthorMappingReason::InvalidAuthorDisplayName
                );
                continue;
            }
            $authorPlans[] = $record;
            $activeStableAuthorSources[$authorId] = $targetSourceId;
            $findings[] = $this->finding(
                $author,
                CurrentV1AuthorMappingReason::StableAuthorPlanned,
                [$record]
            );
            if ($this->hasAdditionalAuthorEvidence($payload)) {
                $findings[] = $this->finding(
                    $author,
                    CurrentV1AuthorMappingReason::AuthorSourceEvidenceRetained
                );
            }
        }

        ksort($authors, SORT_STRING);
        $intents = [];
        foreach ($booksByKey as $book) {
            $bookId = $book->sourceId();
            $payload = $book->payload();
            if (($payload["id"] ?? null) !== $bookId) {
                throw $this->unsupported(
                    "CURRENT V1 Book source identity and payload disagree."
                );
            }
            if ($includeContainedWorkFindings) {
                $this->containedWorkFindings($book, $findings);
            }
            $names = $payload["authors"] ?? [];
            $authorIds = $payload["authorIds"] ?? [];
            if (
                !is_array($names)
                || !array_is_list($names)
                || !is_array($authorIds)
                || !array_is_list($authorIds)
                || count($authorIds) > count($names)
            ) {
                throw $this->unsupported(
                    "CURRENT V1 Author positional-prefix structure is unsupported."
                );
            }

            $seenAuthorIds = [];
            foreach ($authorIds as $index => $authorId) {
                if (!is_string($authorId) || trim($authorId) === "") {
                    continue;
                }
                if (isset($seenAuthorIds[$authorId])) {
                    throw $this->unsupported(
                        "CURRENT V1 Book repeats an Author ID within one contributor array."
                    );
                }
                $seenAuthorIds[$authorId] = true;
                $sourceAuthor = $authors[$authorId] ?? null;
                $name = $names[$index] ?? null;
                if (!$sourceAuthor instanceof MigrationSourceRecord || !is_string($name)) {
                    continue;
                }
                $entityName = $sourceAuthor->payload()["displayName"] ?? null;
                if (!is_string($entityName)) {
                    continue;
                }
                try {
                    $normalizedOccurrence =
                        AuthorContributorCreditKey::normalizeObservedName($name);
                    $normalizedEntity =
                        AuthorContributorCreditKey::normalizeObservedName($entityName);
                } catch (ValidationException) {
                    continue;
                }
                if (
                    $normalizedOccurrence !== $normalizedEntity
                    && mb_strtolower($normalizedOccurrence, "UTF-8")
                        !== mb_strtolower($normalizedEntity, "UTF-8")
                ) {
                    throw $this->unsupported(
                        "CURRENT V1 Author ID/name positional-prefix evidence changed."
                    );
                }
            }

            foreach ($names as $index => $rawName) {
                $position = $index + 1;
                $occurrenceSourceId = CurrentV1AuthorSourceIds::occurrence(
                    $bookId,
                    $position
                );
                $exception = $this->contract->occurrenceException(
                    $occurrenceSourceId
                );
                if ($exception !== null) {
                    $findings[] = $this->occurrenceFinding(
                        $occurrenceSourceId,
                        $exception
                    );
                    continue;
                }
                if (!isset($workRepresentativeByBookId[$bookId])) {
                    $findings[] = $this->occurrenceFinding(
                        $occurrenceSourceId,
                        CurrentV1AuthorMappingReason::CatalogWorkUnavailable
                    );
                    continue;
                }
                if (!is_string($rawName)) {
                    $findings[] = $this->occurrenceFinding(
                        $occurrenceSourceId,
                        CurrentV1AuthorMappingReason::InvalidAuthorDisplayName
                    );
                    continue;
                }
                try {
                    $normalizedName =
                        AuthorContributorCreditKey::normalizeObservedName($rawName);
                } catch (ValidationException) {
                    $findings[] = $this->occurrenceFinding(
                        $occurrenceSourceId,
                        CurrentV1AuthorMappingReason::InvalidAuthorDisplayName
                    );
                    continue;
                }

                $occurrenceAuthorRecord = null;
                if ($index < count($authorIds)) {
                    $authorId = $authorIds[$index];
                    if (
                        !is_string($authorId)
                        || trim($authorId) === ""
                        || !isset($authors[$authorId])
                        || !isset($activeStableAuthorSources[$authorId])
                    ) {
                        $findings[] = $this->occurrenceFinding(
                            $occurrenceSourceId,
                            CurrentV1AuthorMappingReason::InvalidAuthorReference
                        );
                        continue;
                    }
                    $authorSourceId = $activeStableAuthorSources[$authorId];
                } else {
                    $authorSourceId = CurrentV1AuthorSourceIds::occurrenceAuthor(
                        $bookId,
                        $position,
                        $normalizedName
                    );
                    $occurrenceAuthorRecord = MigrationSourceRecord::typed(
                        CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                        $authorSourceId,
                        new CatalogAuthorPlan($normalizedName)
                    );
                }

                $workSourceId = CurrentV1CatalogSourceIds::work($bookId);
                $contributorRecord = MigrationSourceRecord::typed(
                    CatalogWorkContributorMigrationParticipant::SOURCE_TYPE,
                    $occurrenceSourceId,
                    new CatalogWorkContributorPlan(
                        $authorSourceId,
                        $workSourceId,
                        ContributorRole::Author,
                        new ContributorPosition($position),
                        $normalizedName
                    ),
                    [
                        CatalogAuthorMigrationParticipant::SOURCE_TYPE . ":"
                            . $authorSourceId,
                        CatalogWorkMigrationParticipant::SOURCE_TYPE . ":"
                            . $workSourceId,
                    ]
                );
                $intents[] = [
                    "book_id" => $bookId,
                    "representative" => $workRepresentativeByBookId[$bookId],
                    "author_source_id" => $authorSourceId,
                    "position" => $position,
                    "role" => ContributorRole::Author->value,
                    "author_record" => $occurrenceAuthorRecord,
                    "contributor_record" => $contributorRecord,
                ];
            }
        }

        [$blocked, $aliasFindings] = $this->aliasSafety($intents);
        array_push($findings, ...$aliasFindings);
        foreach ($intents as $intent) {
            /** @var MigrationSourceRecord $contributor */
            $contributor = $intent["contributor_record"];
            if (isset($blocked[$contributor->sourceId()])) {
                continue;
            }
            /** @var ?MigrationSourceRecord $occurrenceAuthor */
            $occurrenceAuthor = $intent["author_record"];
            $planned = [$contributor];
            if ($occurrenceAuthor !== null) {
                $authorPlans[] = $occurrenceAuthor;
                array_unshift($planned, $occurrenceAuthor);
                $findings[] = new MigrationSourceMappingFinding(
                    "v1.author_occurrence",
                    $contributor->sourceId(),
                    CurrentV1AuthorMappingReason::OccurrenceAuthorPlanned
                        ->disposition(),
                    CurrentV1AuthorMappingReason::OccurrenceAuthorPlanned->value,
                    [$this->identity($occurrenceAuthor)]
                );
            }
            $contributorPlans[] = $contributor;
            $findings[] = new MigrationSourceMappingFinding(
                "v1.author_occurrence",
                $contributor->sourceId(),
                CurrentV1AuthorMappingReason::ContributorOccurrencePlanned
                    ->disposition(),
                CurrentV1AuthorMappingReason::ContributorOccurrencePlanned->value,
                array_map($this->identity(...), $planned)
            );
        }

        $findings[] = new MigrationSourceMappingFinding(
            "v1.author_auxiliary",
            "mapping_contract:" . $this->contract->identity(),
            CurrentV1AuthorMappingReason::MappingContractApplied->disposition(),
            CurrentV1AuthorMappingReason::MappingContractApplied->value
        );

        return new CurrentV1AuthorMappingResult(
            $authorPlans,
            $contributorPlans,
            $findings
        );
    }

    /**
     * @param list<array{
     *   book_id:string,representative:string,author_source_id:string,
     *   position:int,role:string,author_record:?MigrationSourceRecord,
     *   contributor_record:MigrationSourceRecord
     * }> $intents
     * @return array{array<string,true>,list<MigrationSourceMappingFinding>}
     */
    private function aliasSafety(array $intents): array
    {
        $groups = [];
        foreach ($intents as $intent) {
            $groups[$intent["representative"]][] = $intent;
        }
        $blocked = [];
        $findings = [];
        foreach ($groups as $representative => $group) {
            $edgesByBook = [];
            foreach ($group as $intent) {
                $edgeKey = implode("\0", [
                    $intent["author_source_id"],
                    $intent["role"],
                    (string) $intent["position"],
                ]);
                $edgesByBook[$intent["book_id"]][$edgeKey][] = $intent;
            }

            $conflicting = [];
            if (count($edgesByBook) > 1) {
                $positiveBookCount = count($edgesByBook);
                $edgeBookCounts = [];
                foreach ($edgesByBook as $edges) {
                    foreach (array_keys($edges) as $edgeKey) {
                        $edgeBookCounts[$edgeKey] = ($edgeBookCounts[$edgeKey] ?? 0) + 1;
                    }
                }
                foreach ($group as $intent) {
                    $edgeKey = implode("\0", [
                        $intent["author_source_id"],
                        $intent["role"],
                        (string) $intent["position"],
                    ]);
                    if ($edgeBookCounts[$edgeKey] !== $positiveBookCount) {
                        $conflicting[] = $intent["contributor_record"];
                    }
                }
            }
            if ($conflicting !== []) {
                $identities = [];
                foreach ($conflicting as $record) {
                    $blocked[$record->sourceId()] = true;
                    $identities[$record->sourceId()] = $this->identity($record);
                }
                ksort($identities, SORT_STRING);
                $sourceId = "v1.book/{$representative}/author-alias-conflict/"
                    . substr(hash("sha256", implode("\0", array_keys($identities))), 0, 24);
                $findings[] = new MigrationSourceMappingFinding(
                    "v1.author_alias_conflict",
                    $sourceId,
                    CurrentV1AuthorMappingReason::AliasContributorConflict
                        ->disposition(),
                    CurrentV1AuthorMappingReason::AliasContributorConflict->value,
                    [],
                    count($identities)
                );
            }

            $edges = [];
            foreach ($group as $intent) {
                /** @var MigrationSourceRecord $record */
                $record = $intent["contributor_record"];
                if (isset($blocked[$record->sourceId()])) {
                    continue;
                }
                $key = implode("\0", [
                    $intent["author_source_id"],
                    $intent["role"],
                    (string) $intent["position"],
                ]);
                $edges[$key][] = $intent;
            }
            foreach ($edges as $edge) {
                $books = [];
                $planned = [];
                foreach ($edge as $intent) {
                    $books[$intent["book_id"]] = true;
                    $planned[] = $this->identity($intent["contributor_record"]);
                }
                if (count($books) < 2) {
                    continue;
                }
                $position = $edge[0]["position"];
                $findings[] = new MigrationSourceMappingFinding(
                    "v1.author_alias_convergence",
                    "v1.book/{$representative}/author-edge/{$position}/convergence",
                    CurrentV1AuthorMappingReason::DuplicateAuthorEdgeConvergence
                        ->disposition(),
                    CurrentV1AuthorMappingReason::DuplicateAuthorEdgeConvergence->value,
                    $planned
                );
            }
        }
        return [$blocked, $findings];
    }

    /** @param list<MigrationSourceMappingFinding> $findings */
    private function containedWorkFindings(
        MigrationSourceRecord $book,
        array &$findings
    ): void {
        $contained = $book->payload()["containedWorks"] ?? [];
        if (!is_array($contained) || !array_is_list($contained)) {
            throw $this->unsupported(
                "CURRENT V1 contained-work structure is unsupported."
            );
        }
        foreach ($contained as $index => $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw $this->unsupported(
                    "CURRENT V1 contained-work row is unsupported."
                );
            }
            $author = $row["author"] ?? null;
            if (!is_string($author) || trim($author) === "") {
                continue;
            }
            $findings[] = new MigrationSourceMappingFinding(
                "v1.contained_work_author",
                CurrentV1AuthorSourceIds::containedWorkAuthor(
                    $book->sourceId(),
                    $index + 1
                ),
                CurrentV1AuthorMappingReason::ContainedWorkAuthorPreserved
                    ->disposition(),
                CurrentV1AuthorMappingReason::ContainedWorkAuthorPreserved->value
            );
        }
    }

    /** @param array<string,mixed> $payload */
    private function hasAdditionalAuthorEvidence(array $payload): bool
    {
        foreach ($payload as $field => $value) {
            if (in_array($field, ["id", "displayName"], true)) {
                continue;
            }
            if ($value !== null && $value !== "" && $value !== []) {
                return true;
            }
        }
        return false;
    }

    /** @param list<MigrationSourceRecord> $planned */
    private function finding(
        MigrationSourceRecord $source,
        CurrentV1AuthorMappingReason $reason,
        array $planned = []
    ): MigrationSourceMappingFinding {
        return new MigrationSourceMappingFinding(
            $source->sourceType(),
            $source->sourceId(),
            $reason->disposition(),
            $reason->value,
            array_map($this->identity(...), $planned)
        );
    }

    private function occurrenceFinding(
        string $sourceId,
        CurrentV1AuthorMappingReason $reason
    ): MigrationSourceMappingFinding {
        return new MigrationSourceMappingFinding(
            "v1.author_occurrence",
            $sourceId,
            $reason->disposition(),
            $reason->value
        );
    }

    /** @return array{source_type:string,source_id:string} */
    private function identity(MigrationSourceRecord $record): array
    {
        return [
            "source_type" => $record->sourceType(),
            "source_id" => $record->sourceId(),
        ];
    }

    private function unsupported(string $message): MigrationRunnerFailure
    {
        return new MigrationRunnerFailure(
            MigrationRunnerReason::UnsupportedVersion,
            $message
        );
    }
}
