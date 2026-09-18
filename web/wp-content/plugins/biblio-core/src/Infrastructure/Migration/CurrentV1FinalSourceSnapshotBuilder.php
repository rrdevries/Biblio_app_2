<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Circulation\CirculationPlan;
use Biblio\Core\Application\Migration\Cutover\FinalSourceObservation;
use Biblio\Core\Application\Migration\Cutover\FinalSourcePackageIdentity;
use Biblio\Core\Application\Migration\Cutover\FinalSourceSnapshot;
use Biblio\Core\Application\Migration\Runner\DeterministicJson;
use Biblio\Core\Application\Migration\Runner\MigrationSourceInspection;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;
use Biblio\Core\Exception\ValidationException;
use JsonException;

final readonly class CurrentV1FinalSourceSnapshotBuilder
{
    public function build(
        MigrationSourceInspection $inspection,
        FinalSourcePackageIdentity $identity
    ): FinalSourceSnapshot {
        if (
            $inspection->adapter()->adapterId() !== CurrentV1SourceAdapter::ADAPTER_ID
            || $identity->adapterId() !== $inspection->adapter()->adapterId()
            || $identity->sourceFamily() !== $inspection->adapter()->sourceFamily()
            || $identity->sourceVersion() !== $inspection->profile()->sourceVersion()
            || !hash_equals($identity->manifestSha256(), $inspection->package()->manifestDigest())
        ) {
            throw new ValidationException("Final source identity does not match its inspected package.");
        }

        $booksDocument = $this->object($inspection, "data/books.json");
        $books = $booksDocument["books"];
        $copies = $booksDocument["copies"];
        $wishlist = $booksDocument["wishlistItems"];
        $observations = [];

        foreach ($inspection->records() as $record) {
            $domain = $this->stableDomain($record->sourceType());
            $state = "ordinary";
            if ($record->typedPlan() instanceof CirculationPlan) {
                $state = $record->typedPlan()->hasMaterialLifecycleConflict()
                    ? "conflict"
                    : $record->typedPlan()->sourceState();
            }
            $observations[] = new FinalSourceObservation(
                $domain,
                $record->sourceType(),
                $record->sourceId(),
                $record->payloadHash(),
                $this->shapeHash($record->payload()),
                "reviewed_current_v1_shape",
                false,
                $state
            );
        }

        foreach ($books as $book) {
            if (!is_array($book) || !is_string($book["id"] ?? null)) {
                continue;
            }
            $bookId = $book["id"];
            $this->addVector(
                $observations,
                "authors_contributors",
                "v1.contributor_vector",
                "v1.book/{$bookId}/contributors",
                $this->alignedContributors($book)
            );
            $this->addVector(
                $observations,
                "classifications",
                "v1.classification_vector",
                "v1.book/{$bookId}/classifications",
                [
                    "book_type" => $book["bookType"] ?? null,
                    "categories" => $book["categories"] ?? [],
                    "genres" => $book["genres"] ?? [],
                ]
            );
            $this->addVector(
                $observations,
                "item_local_evidence",
                "v1.item_local_evidence",
                "v1.book/{$bookId}/item-local",
                [
                    "acquisition" => $book["acquisition"] ?? null,
                    "acquired_at" => $book["acquiredAt"] ?? null,
                    "acquired_via" => $book["acquiredVia"] ?? null,
                    "acquisition_legacy" => $book["acquisition"] ?? null,
                ]
            );
            $this->addVector(
                $observations,
                "contained_works",
                "v1.contained_work_vector",
                "v1.book/{$bookId}/contained-works",
                $book["containedWorks"] ?? [],
                true,
                "ordinary",
                is_array($book["containedWorks"] ?? null) && $book["containedWorks"] !== []
                    ? "contained_work_vector_nonempty"
                    : "contained_work_vector_empty"
            );
            $readingEvidence = [
                "read_marker" => $book["readMarker"] ?? null,
                "read_status" => $book["readStatus"] ?? null,
                "read_registration" => $book["readRegistration"] ?? null,
                "finish_dates" => $book["finishDates"] ?? [],
                "finished_dates" => $book["finishedDates"] ?? [],
            ];
            $this->addVector(
                $observations,
                "personal_reading_truth",
                "v1.reading_truth_evidence",
                "v1.book/{$bookId}/reading-truth",
                $readingEvidence
            );
            if (is_int($book["rating"] ?? null) && $book["rating"] > 0) {
                $this->addVector(
                    $observations,
                    "ratings",
                    "v1.rating_slot",
                    "v1.book/{$bookId}/rating/1",
                    ["value" => $book["rating"]],
                    true
                );
            }
            foreach (($book["reviews"] ?? []) as $index => $review) {
                $this->addVector(
                    $observations,
                    "written_reviews",
                    "v1.review_slot",
                    "v1.book/{$bookId}/review/" . ($index + 1),
                    $review,
                    true
                );
            }
            if (is_string($book["reflection"] ?? null) && trim($book["reflection"]) !== "") {
                $this->addVector(
                    $observations,
                    "reflections",
                    "v1.reflection_slot",
                    "v1.book/{$bookId}/reflection/1",
                    ["reflection" => $book["reflection"]],
                    true
                );
            }
            foreach ([
                "bookType", "carrier", "collectionStatus", "ownershipStatus",
                "readMarker", "readStatus",
            ] as $field) {
                $this->addSemanticValue($observations, "book.{$field}", $book[$field] ?? null);
            }
            $this->addSemanticValue(
                $observations,
                "book.acquisition.type",
                is_array($book["acquisition"] ?? null) ? ($book["acquisition"]["type"] ?? null) : null
            );
        }

        foreach ($copies as $copy) {
            if (!is_array($copy) || !is_string($copy["id"] ?? null)) {
                continue;
            }
            $copyId = $copy["id"];
            $this->addVector(
                $observations,
                "item_local_evidence",
                "v1.item_local_evidence",
                "v1.copy/{$copyId}/item-local",
                [
                    "acquisition" => $copy["acquisition"] ?? null,
                    "condition" => $copy["condition"] ?? null,
                    "location" => $copy["location"] ?? null,
                    "notes" => $copy["notes"] ?? null,
                    "photos" => $copy["exemplarPhotos"] ?? null,
                ]
            );
            if ($this->isErroneousCopyCandidate($copy)) {
                $this->addVector(
                    $observations,
                    "erroneous_copies",
                    "v1.erroneous_copy_candidate",
                    "v1.copy/{$copyId}/erroneous-exclusion",
                    $copy,
                    false,
                    "quarantine_candidate"
                );
            }
            foreach (["status", "ownershipStatus", "archiveReason"] as $field) {
                $this->addSemanticValue($observations, "copy.{$field}", $copy[$field] ?? null);
            }
            $this->addSemanticValue(
                $observations,
                "copy.acquisition.type",
                is_array($copy["acquisition"] ?? null) ? ($copy["acquisition"]["type"] ?? null) : null
            );
        }

        foreach ($wishlist as $item) {
            if (is_array($item)) {
                $this->addSemanticValue($observations, "wishlist.type", $item["type"] ?? null);
                $this->addSemanticValue($observations, "wishlist.status", $item["status"] ?? null);
            }
        }

        $this->addClassificationDefinitions($observations, $inspection);
        $this->addIsbnGroups($observations, $books);
        $this->addSeriesGroups($observations, $books);
        $circulation = $this->circulationProfile($inspection->records(), $copies);
        $quarantine = [];
        foreach ($inspection->records() as $record) {
            $plan = $record->typedPlan();
            if (!$plan instanceof CirculationPlan || !$plan->hasMaterialLifecycleConflict()) {
                continue;
            }
            $quarantine[] = [
                "source_type" => $record->sourceType(),
                "source_id" => $record->sourceId(),
                "reason" => "circulation_source_conflict_candidate",
                "evidence_hash" => $record->payloadHash(),
                "no_target" => true,
            ];
        }

        return new FinalSourceSnapshot(
            $identity,
            $this->unique($observations),
            $inspection->typeCounts(),
            $circulation,
            $quarantine
        );
    }

    /** @return array<string,mixed> */
    private function object(MigrationSourceInspection $inspection, string $path): array
    {
        try {
            $value = json_decode(
                $inspection->package()->read($path),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new ValidationException("Inspected CURRENT source JSON became invalid.", 0, $exception);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new ValidationException("Inspected CURRENT source document is invalid.");
        }
        return $value;
    }

    /** @return list<mixed> */
    private function list(MigrationSourceInspection $inspection, string $path): array
    {
        try {
            $value = json_decode(
                $inspection->package()->read($path),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException $exception) {
            throw new ValidationException("Inspected CURRENT source JSON became invalid.", 0, $exception);
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new ValidationException("Inspected CURRENT source list is invalid.");
        }
        return $value;
    }

    private function stableDomain(string $sourceType): string
    {
        return match ($sourceType) {
            CurrentV1SourceAdapter::BOOK => "catalog_books",
            CurrentV1SourceAdapter::COPY => "copies",
            CurrentV1SourceAdapter::AUTHOR => "authors_contributors",
            CurrentV1SourceAdapter::WISHLIST_ITEM => "wishlist",
            CurrentV1SourceAdapter::READING_ROUND => "reading_rounds",
            CurrentV1SourceAdapter::NOTE => "notes",
            CurrentV1SourceAdapter::CIRCULATION_ROUND => "circulation",
            CurrentV1SourceAdapter::READING_GOAL => "reading_goals",
            default => "unsupported_source_types",
        };
    }

    /**
     * @param array<string,mixed> $book
     * @return list<array<string,mixed>>
     */
    private function alignedContributors(array $book): array
    {
        $names = is_array($book["authors"] ?? null) ? $book["authors"] : [];
        $ids = is_array($book["authorIds"] ?? null) ? $book["authorIds"] : [];
        $count = max(count($names), count($ids));
        $result = [];
        for ($index = 0; $index < $count; $index++) {
            $result[] = [
                "position" => $index + 1,
                "name" => $names[$index] ?? null,
                "author_id" => $ids[$index] ?? null,
                "role" => "author",
            ];
        }
        return $result;
    }

    /**
     * @param list<FinalSourceObservation> $observations
     * @param mixed $value
     */
    private function addVector(
        array &$observations,
        string $domain,
        string $sourceType,
        string $sourceId,
        mixed $value,
        bool $structural = false,
        string $state = "ordinary",
        string $semanticClass = "reviewed_current_v1_shape"
    ): void {
        $observations[] = new FinalSourceObservation(
            $domain,
            $sourceType,
            $sourceId,
            DeterministicJson::hash($value),
            $this->shapeHash($value),
            $semanticClass,
            $structural,
            $state
        );
    }

    /** @param list<FinalSourceObservation> $observations */
    private function addSemanticValue(array &$observations, string $field, mixed $value): void
    {
        if ($value === null || $value === "") {
            return;
        }
        $evidence = ["field" => $field, "value" => $value];
        $observations[] = new FinalSourceObservation(
            "source_semantics",
            "v1.semantic_value",
            $field . "/" . DeterministicJson::hash($value),
            DeterministicJson::hash($evidence),
            $this->shapeHash($evidence),
            "reviewed_raw_value",
            false,
            "enum"
        );
    }

    /**
     * @param list<FinalSourceObservation> $observations
     * @param list<mixed> $books
     */
    private function addSeriesGroups(array &$observations, array $books): void
    {
        $groups = [];
        foreach ($books as $book) {
            if (!is_array($book) || !is_string($book["id"] ?? null)) {
                continue;
            }
            $name = $book["seriesName"] ?? null;
            if (is_string($name) && $name !== "") {
                $groups[$name][] = [
                    "book_id" => $book["id"],
                    "position" => $book["seriesNumber"] ?? null,
                ];
            }
            foreach (($book["containedWorks"] ?? []) as $index => $contained) {
                if (!is_array($contained) || !is_string($contained["series"] ?? null) || $contained["series"] === "") {
                    continue;
                }
                $groups[$contained["series"]][] = [
                    "book_id" => $book["id"],
                    "contained_slot" => $index + 1,
                    "position" => $contained["seriesIndex"] ?? null,
                ];
            }
        }
        ksort($groups, SORT_STRING);
        foreach ($groups as $name => $members) {
            $this->addVector(
                $observations,
                "series",
                "v1.series_group",
                "series-group/" . DeterministicJson::hash($name),
                ["exact_name_hash" => DeterministicJson::hash($name), "members" => $members],
                true
            );
        }
    }

    /** @param list<FinalSourceObservation> $observations */
    private function addClassificationDefinitions(
        array &$observations,
        MigrationSourceInspection $inspection
    ): void {
        foreach ([
            "book_type" => "data/book_types.json",
            "carrier" => "data/carriers.json",
            "category" => "data/categories.json",
            "genre" => "data/genres.json",
        ] as $dimension => $path) {
            foreach ($this->list($inspection, $path) as $row) {
                if (!is_array($row) || !is_string($row["name"] ?? null)) {
                    continue;
                }
                $this->addVector(
                    $observations,
                    "classifications",
                    "v1.classification_definition",
                    "classification-definition/{$dimension}/"
                        . DeterministicJson::hash($row["name"]),
                    $row,
                    false,
                    "ordinary",
                    "classification_definition_{$dimension}"
                );
                $this->addSemanticValue(
                    $observations,
                    "classification.{$dimension}.name",
                    $row["name"]
                );
                $this->addSemanticValue(
                    $observations,
                    "classification.{$dimension}.status",
                    $row["status"] ?? null
                );
            }
        }
        $this->addVector(
            $observations,
            "classifications",
            "v1.classification_review_vector",
            "classification-review-queue",
            $this->list($inspection, "data/taxonomy_review_queue.json"),
            true
        );
        $this->addVector(
            $observations,
            "classifications",
            "v1.classification_review_vector",
            "classification-alias-rules",
            $this->object($inspection, "data/taxonomy_aliases.json"),
            true
        );
    }

    /**
     * @param list<FinalSourceObservation> $observations
     * @param list<mixed> $books
     */
    private function addIsbnGroups(array &$observations, array $books): void
    {
        $groups = [];
        foreach ($books as $book) {
            if (!is_array($book) || !is_string($book["id"] ?? null)) {
                continue;
            }
            $values = [];
            foreach (["isbn", "isbn10", "isbn13"] as $field) {
                if (!is_string($book[$field] ?? null) || trim($book[$field]) === "") {
                    continue;
                }
                $normalized = strtoupper((string) preg_replace('/[^0-9X]/i', '', $book[$field]));
                if ($normalized !== "") {
                    $values[$normalized] = true;
                }
            }
            foreach (array_keys($values) as $normalized) {
                $groups[$normalized][] = $book["id"];
            }
        }
        ksort($groups, SORT_STRING);
        foreach ($groups as $normalized => $bookIds) {
            sort($bookIds, SORT_STRING);
            $this->addVector(
                $observations,
                "catalog_identity",
                "v1.isbn_group",
                "isbn-group/" . DeterministicJson::hash($normalized),
                ["isbn_hash" => DeterministicJson::hash($normalized), "book_ids" => $bookIds],
                true
            );
        }
    }

    /**
     * @param list<MigrationSourceRecord> $records
     * @param list<mixed> $copies
     * @return array<string,mixed>
     */
    private function circulationProfile(array $records, array $copies): array
    {
        $erroneousCopyIds = [];
        foreach ($copies as $copy) {
            if (is_array($copy) && is_string($copy["id"] ?? null) && $this->isErroneousCopyCandidate($copy)) {
                $erroneousCopyIds[$copy["id"]] = true;
            }
        }
        $profile = [
            "open_borrowed" => 0,
            "open_lent_out" => 0,
            "open_total" => 0,
            "contradictory" => 0,
            "closed" => 0,
            "counterparty_present" => 0,
            "dependency_present" => 0,
            "archived_error_copy_overlap" => 0,
            "source_ids" => [],
        ];
        foreach ($records as $record) {
            $plan = $record->typedPlan();
            if (!$plan instanceof CirculationPlan) {
                continue;
            }
            $profile["source_ids"][] = $record->sourceId();
            if ($plan->hasMaterialLifecycleConflict()) {
                $profile["contradictory"]++;
            } elseif ($plan->sourceState() === "closed") {
                $profile["closed"]++;
            } else {
                foreach ($plan->sourceTypes() as $type) {
                    if ($type === "borrowed") {
                        $profile["open_borrowed"]++;
                    } elseif ($type === "lent_out") {
                        $profile["open_lent_out"]++;
                    }
                }
                $profile["open_total"]++;
            }
            if ($record->references() !== []) {
                $profile["dependency_present"]++;
            }
            foreach ($record->references() as $reference) {
                if (str_starts_with($reference, CurrentV1SourceAdapter::COPY . ":")) {
                    $copyId = substr($reference, strlen(CurrentV1SourceAdapter::COPY . ":"));
                    if (isset($erroneousCopyIds[$copyId])) {
                        $profile["archived_error_copy_overlap"]++;
                        break;
                    }
                }
            }
            $payload = $plan->canonicalPayload();
            foreach ([...$payload["book_occurrences"], ...$payload["copy_occurrences"]] as $occurrence) {
                if (is_string($occurrence["record"]["counterparty"] ?? null)
                    && trim($occurrence["record"]["counterparty"]) !== "") {
                    $profile["counterparty_present"]++;
                    break;
                }
            }
        }
        $profile["gate"] = $profile["open_total"] > 0
            ? "CIRCULATION_CUTOVER_REVIEW_REQUIRED"
            : "NO_OPEN_CIRCULATION_GATE";
        sort($profile["source_ids"], SORT_STRING);
        return $profile;
    }

    /** @param array<string,mixed> $copy */
    private function isErroneousCopyCandidate(array $copy): bool
    {
        return ($copy["archived"] ?? null) === true
            && is_string($copy["archivedAt"] ?? null)
            && $copy["archivedAt"] !== ""
            && ($copy["status"] ?? null) === "disposed"
            && ($copy["ownershipStatus"] ?? null) === "none"
            && in_array(
                $copy["archiveReason"] ?? null,
                ["duplicate_correction", "incorrectly_registered", "wishlist_correction"],
                true
            );
    }

    private function shapeHash(mixed $value): string
    {
        return DeterministicJson::hash($this->shape($value));
    }

    /**
     * @param list<FinalSourceObservation> $observations
     * @return list<FinalSourceObservation>
     */
    private function unique(array $observations): array
    {
        $indexed = [];
        foreach ($observations as $observation) {
            $key = $observation->key();
            $existing = $indexed[$key] ?? null;
            if ($existing instanceof FinalSourceObservation) {
                if (!hash_equals($existing->payloadHash(), $observation->payloadHash())) {
                    throw new ValidationException("Final source semantic observation collided.");
                }
                continue;
            }
            $indexed[$key] = $observation;
        }
        return array_values($indexed);
    }

    private function shape(mixed $value): mixed
    {
        if (!is_array($value)) {
            return get_debug_type($value);
        }
        if (array_is_list($value)) {
            return "list";
        }
        ksort($value, SORT_STRING);
        return array_map($this->shape(...), $value);
    }
}
