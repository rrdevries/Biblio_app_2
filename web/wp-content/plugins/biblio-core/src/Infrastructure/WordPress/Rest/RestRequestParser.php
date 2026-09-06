<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Application\Assessments\Read\{PublicAssessmentCursor,PublicAssessmentPageSize};
use Biblio\Core\Application\Catalog\Read\CatalogOverviewCursor;
use Biblio\Core\Application\Catalog\Read\CatalogOverviewPageSize;
use Biblio\Core\Application\Catalog\Query\CatalogQuery;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitialization;
use Biblio\Core\Application\Metadata\{AddBookCommitRequest,AddBookCommitSelection,AddBookObservedMetadata,MetadataCandidateId,MetadataFieldValue,MetadataLookupId,UserObservedMetadataField};
use Biblio\Core\Application\Reading\History\ReadingHistoryCursor;
use Biblio\Core\Application\Reading\History\ReadingHistoryPageSize;
use Biblio\Core\Application\NextReading\Read\{NextReadingDiscoveryLimit,NextReadingWorkCursor,NextReadingWorkSearchTerm};
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\InventoryNumber;
use Biblio\Core\Catalog\LocationId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Catalog\Classification\{LibraryBookTypeId,LibraryCatalogSelection,LibraryGenreId,LibrarySubjectId};
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Notes\PrivateNoteId;
use Biblio\Core\Notes\PrivateNotePageRequest;
use Biblio\Core\Notes\PrivateNoteVersion;
use Biblio\Core\NextReading\{NextReadingEntryId,NextReadingListVersion,NextReadingUndoToken,PreferredReadingSourceType};
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundOutcome;
use Biblio\Core\Reading\ReadingRoundVersion;
use JsonException;
use stdClass;
use Throwable;
use WP_REST_Request;

final readonly class RestRequestParser
{
    public function __construct(
        private CatalogCursorCodec $cursors,
        private ReadingHistoryCursorCodec $historyCursors,
        private PrivateNoteCursorCodec $privateNoteCursors,
        private ?NextReadingWorkCursorCodec $nextReadingWorkCursors = null,
        private ?PublicAssessmentCursorCodec $publicAssessmentCursors = null,
        private ?RestCatalogQueryParser $catalogQueries = null
    ) {
    }

    public function libraryId(WP_REST_Request $request): LibraryId
    {
        return $this->identifier(
            $request->get_url_params()["library_id"] ?? null,
            "library_id",
            static fn (string $value): LibraryId => new LibraryId($value)
        );
    }

    public function catalogQuery(WP_REST_Request $request): CatalogQuery
    {
        return ($this->catalogQueries ?? new RestCatalogQueryParser())->parse(
            $request->get_query_params()
        );
    }

    public function itemId(WP_REST_Request $request): ItemId
    {
        return $this->identifier(
            $request->get_url_params()["item_id"] ?? null,
            "item_id",
            static fn (string $value): ItemId => new ItemId($value)
        );
    }

    public function workId(WP_REST_Request $request): WorkId
    {
        return $this->identifier(
            $request->get_url_params()["work_id"] ?? null,
            "work_id",
            static fn (string $value): WorkId => new WorkId($value)
        );
    }

    public function privateNoteId(WP_REST_Request $request): PrivateNoteId
    {
        return $this->identifier(
            $request->get_url_params()["private_note_id"] ?? null,
            "private_note_id",
            static fn (string $value): PrivateNoteId => new PrivateNoteId($value)
        );
    }

    public function privateNotePage(
        WP_REST_Request $request
    ): PrivateNotePageRequest {
        $this->validateQueryFields($request, ["limit", "cursor"]);
        $query = $request->get_query_params();
        $limit = $this->positiveQueryInteger(
            $query["limit"] ?? null,
            "limit",
            50
        );
        $cursorValue = $query["cursor"] ?? null;

        try {
            $firstPage = new PrivateNotePageRequest($limit);
        } catch (Throwable) {
            throw RestRequestException::invalid("limit");
        }

        if ($cursorValue === null) {
            return $firstPage;
        }

        if (!is_string($cursorValue)) {
            throw RestRequestException::wrongType("cursor", "a string");
        }

        $cursor = $this->privateNoteCursors->decode($cursorValue);

        try {
            return new PrivateNotePageRequest(
                $limit,
                $cursor->beforeUpdatedAt(),
                $cursor->beforeId()
            );
        } catch (Throwable) {
            throw RestRequestException::invalid("cursor");
        }
    }

    public function privateNoteContent(WP_REST_Request $request): string
    {
        $this->validateQueryFields($request, []);
        $body = $this->jsonObject($request, "content");
        $this->validateBodyFields($body, ["content"]);

        if (!is_string($body["content"])) {
            throw RestRequestException::wrongType("content", "a string");
        }

        return $body["content"];
    }

    public function privateNoteUpdate(
        WP_REST_Request $request
    ): RestPrivateNoteUpdateRequest {
        $this->validateQueryFields($request, []);
        $body = $this->jsonObject($request, "content");
        $this->validateBodyFields($body, ["content", "expected_version"]);

        if (!is_string($body["content"])) {
            throw RestRequestException::wrongType("content", "a string");
        }

        return new RestPrivateNoteUpdateRequest(
            $this->privateNoteId($request),
            $body["content"],
            $this->privateNoteVersion($body["expected_version"])
        );
    }

    public function privateNoteDelete(
        WP_REST_Request $request
    ): RestPrivateNoteDeleteRequest {
        $this->validateQueryFields($request, []);
        $body = $this->jsonObject($request, "expected_version");
        $this->validateBodyFields($body, ["expected_version"]);

        return new RestPrivateNoteDeleteRequest(
            $this->privateNoteId($request),
            $this->privateNoteVersion($body["expected_version"])
        );
    }

    /** @return array{work_id: WorkId, preferred_source: ?RestNextReadingPreferredSource} */
    public function nextReadingAdd(WP_REST_Request $request): array
    {
        $this->validateQueryFields($request, []);
        $body = $this->jsonObject($request, "work_id");
        $this->validateBodyFields($body, ["work_id", "preferred_source"]);

        return [
            "work_id" => $this->identifier(
                $body["work_id"],
                "work_id",
                static fn (string $value): WorkId => new WorkId($value)
            ),
            "preferred_source" => $body["preferred_source"] === null
                ? null
                : $this->nextReadingPreferredSource($body["preferred_source"]),
        ];
    }

    /** @return array{entry_id: NextReadingEntryId, expected_version: NextReadingListVersion} */
    public function nextReadingRemove(WP_REST_Request $request): array
    {
        $this->validateQueryFields($request, []);
        $body = $this->jsonObject($request, "expected_version");
        $this->validateBodyFields($body, ["expected_version"]);

        return [
            "entry_id" => $this->nextReadingEntryId($request),
            "expected_version" => $this->nextReadingListVersion(
                $body["expected_version"]
            ),
        ];
    }

    public function nextReadingUndoToken(WP_REST_Request $request): NextReadingUndoToken
    {
        $this->validateQueryFields($request, []);
        $body = $this->jsonObject($request, "undo_token");
        $this->validateBodyFields($body, ["undo_token"]);

        return $this->identifier(
            $body["undo_token"],
            "undo_token",
            static fn (string $value): NextReadingUndoToken =>
                new NextReadingUndoToken($value)
        );
    }

    /** @return array{ordered_entry_ids: list<NextReadingEntryId>, expected_version: NextReadingListVersion} */
    public function nextReadingReorder(WP_REST_Request $request): array
    {
        $this->validateQueryFields($request, []);
        $body = $this->jsonObject($request, "ordered_entry_ids");
        $this->validateBodyFields(
            $body,
            ["ordered_entry_ids", "expected_version"]
        );

        if (!is_array($body["ordered_entry_ids"]) || !array_is_list($body["ordered_entry_ids"])) {
            throw RestRequestException::wrongType(
                "ordered_entry_ids",
                "an array"
            );
        }

        $ids = [];
        foreach ($body["ordered_entry_ids"] as $value) {
            $ids[] = $this->identifier(
                $value,
                "ordered_entry_ids",
                static fn (string $id): NextReadingEntryId =>
                    new NextReadingEntryId($id)
            );
        }

        return [
            "ordered_entry_ids" => $ids,
            "expected_version" => $this->nextReadingListVersion(
                $body["expected_version"]
            ),
        ];
    }

    /** @return array{entry_id: NextReadingEntryId, expected_version: NextReadingListVersion, preferred_source: RestNextReadingPreferredSource} */
    public function nextReadingPreferredSourceMutation(
        WP_REST_Request $request
    ): array {
        $this->validateQueryFields($request, []);
        $body = $this->jsonObject($request, "preferred_source");
        $this->validateBodyFields(
            $body,
            ["preferred_source", "expected_version"]
        );

        if ($body["preferred_source"] === null) {
            throw RestRequestException::invalid("preferred_source");
        }

        return [
            "entry_id" => $this->nextReadingEntryId($request),
            "expected_version" => $this->nextReadingListVersion(
                $body["expected_version"]
            ),
            "preferred_source" => $this->nextReadingPreferredSource(
                $body["preferred_source"]
            ),
        ];
    }

    /** @return array{entry_id: NextReadingEntryId, expected_version: NextReadingListVersion} */
    public function nextReadingPreferredSourceClear(
        WP_REST_Request $request
    ): array {
        return $this->nextReadingRemove($request);
    }

    /** @return array{search: NextReadingWorkSearchTerm, limit: NextReadingDiscoveryLimit, cursor: ?NextReadingWorkCursor} */
    public function nextReadingWorkSearch(WP_REST_Request $request): array
    {
        $this->validateQueryFields($request, ["q", "limit", "cursor"]);
        $query = $request->get_query_params();

        if (!array_key_exists("q", $query)) {
            throw RestRequestException::missing("q");
        }

        if (!is_string($query["q"])) {
            throw RestRequestException::wrongType("q", "a string");
        }

        try {
            $search = new NextReadingWorkSearchTerm($query["q"]);
        } catch (Throwable) {
            throw RestRequestException::invalid("q");
        }

        try {
            $limit = new NextReadingDiscoveryLimit(
                $this->positiveQueryInteger(
                    $query["limit"] ?? null,
                    "limit",
                    NextReadingDiscoveryLimit::DEFAULT
                )
            );
        } catch (RestRequestException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw RestRequestException::invalid("limit");
        }

        $cursorValue = $query["cursor"] ?? null;

        if ($cursorValue !== null && !is_string($cursorValue)) {
            throw RestRequestException::wrongType("cursor", "a string");
        }

        return [
            "search" => $search,
            "limit" => $limit,
            "cursor" => $cursorValue === null
                ? null
                : $this->nextReadingWorkCursorCodec()->decode($cursorValue),
        ];
    }

    public function cursor(WP_REST_Request $request): ?CatalogOverviewCursor
    {
        $value = $request->get_query_params()["cursor"] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw RestRequestException::wrongType("cursor", "a string");
        }

        return $this->cursors->decode($value);
    }

    public function pageSize(WP_REST_Request $request): CatalogOverviewPageSize
    {
        $value = $request->get_query_params()["page_size"] ?? null;

        if ($value === null) {
            return new CatalogOverviewPageSize();
        }

        if (
            !(is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1))
        ) {
            throw RestRequestException::wrongType("page_size", "an integer");
        }

        try {
            return new CatalogOverviewPageSize((int) $value);
        } catch (Throwable) {
            throw RestRequestException::invalid("page_size");
        }
    }

    public function readingHistoryCursor(
        WP_REST_Request $request
    ): ?ReadingHistoryCursor {
        $value = $request->get_query_params()["cursor"] ?? null;

        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw RestRequestException::wrongType("cursor", "a string");
        }

        return $this->historyCursors->decode($value);
    }

    public function validateReadingHistoryQuery(WP_REST_Request $request): void
    {
        $this->validateQueryFields($request, ["limit", "cursor"]);
    }

    public function readingHistoryLimit(
        WP_REST_Request $request
    ): ReadingHistoryPageSize {
        $value = $request->get_query_params()["limit"] ?? null;

        if ($value === null) {
            return new ReadingHistoryPageSize();
        }

        if (
            !(is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1))
        ) {
            throw RestRequestException::wrongType("limit", "an integer");
        }

        try {
            return new ReadingHistoryPageSize((int) $value);
        } catch (Throwable) {
            throw RestRequestException::invalid("limit");
        }
    }

    /** @return array{cursor: ?PublicAssessmentCursor, page_size: PublicAssessmentPageSize} */
    public function publicAssessmentPage(WP_REST_Request $request): array
    {
        $this->validateQueryFields($request, ["limit", "cursor"]);
        $query = $request->get_query_params();
        $value = $query["limit"] ?? null;

        if (
            $value !== null
            && !(is_int($value) || (is_string($value)
                && preg_match('/^[0-9]+$/D', $value) === 1))
        ) {
            throw RestRequestException::wrongType("limit", "an integer");
        }

        try {
            $pageSize = new PublicAssessmentPageSize(
                $value === null ? PublicAssessmentPageSize::DEFAULT : (int) $value
            );
        } catch (Throwable) {
            throw RestRequestException::invalid("limit");
        }

        $cursorValue = $query["cursor"] ?? null;
        if ($cursorValue !== null && !is_string($cursorValue)) {
            throw RestRequestException::wrongType("cursor", "a string");
        }

        return [
            "cursor" => $cursorValue === null
                ? null
                : $this->publicAssessmentCursorCodec()->decode($cursorValue),
            "page_size" => $pageSize,
        ];
    }

    public function startedOn(WP_REST_Request $request): ReadingDate
    {
        /** @var mixed $body */
        $body = $request->get_json_params();

        if ($body === null) {
            throw RestRequestException::missing("started_on");
        }

        if (!is_array($body)) {
            throw RestRequestException::wrongType("body", "a JSON object");
        }

        if (array_diff(array_keys($body), ["started_on"]) !== []) {
            throw RestRequestException::unknownFields();
        }

        if (!array_key_exists("started_on", $body)) {
            throw RestRequestException::missing("started_on");
        }

        if (!is_string($body["started_on"])) {
            throw RestRequestException::wrongType("started_on", "a string");
        }

        return $this->exactReadingDate($body["started_on"], "started_on");
    }

    public function endReadingRound(
        WP_REST_Request $request
    ): RestEndReadingRoundRequest {
        $readingRoundId = $this->identifier(
            $request->get_url_params()["reading_round_id"] ?? null,
            "reading_round_id",
            static fn (string $value): ReadingRoundId => new ReadingRoundId($value)
        );

        /** @var mixed $body */
        $body = $request->get_json_params();

        if ($body === null) {
            throw RestRequestException::missing("outcome");
        }

        if (!is_array($body)) {
            throw RestRequestException::wrongType("body", "a JSON object");
        }

        if (array_diff(array_keys($body), [
            "outcome",
            "finished_on",
            "expected_version",
        ]) !== []) {
            throw RestRequestException::unknownFields();
        }

        foreach (["outcome", "finished_on", "expected_version"] as $field) {
            if (!array_key_exists($field, $body)) {
                throw RestRequestException::missing($field);
            }
        }

        if (!is_string($body["outcome"])) {
            throw RestRequestException::wrongType("outcome", "a string");
        }

        $outcome = ReadingRoundOutcome::tryFrom($body["outcome"]);

        if ($outcome === null) {
            throw RestRequestException::invalid("outcome");
        }

        if (!is_string($body["finished_on"])) {
            throw RestRequestException::wrongType("finished_on", "a string");
        }

        if (!is_int($body["expected_version"])) {
            throw RestRequestException::wrongType(
                "expected_version",
                "an integer"
            );
        }

        try {
            $expectedVersion = new ReadingRoundVersion(
                $body["expected_version"]
            );
        } catch (Throwable) {
            throw RestRequestException::invalid("expected_version");
        }

        return new RestEndReadingRoundRequest(
            $readingRoundId,
            $outcome,
            $this->exactReadingDate($body["finished_on"], "finished_on"),
            $expectedVersion
        );
    }

    private function exactReadingDate(string $value, string $field): ReadingDate
    {
        if (
            preg_match(
                '/^(?<year>[0-9]{4})-(?<month>[0-9]{2})-(?<day>[0-9]{2})$/D',
                $value,
                $parts
            ) !== 1
            || !checkdate((int) $parts["month"], (int) $parts["day"], (int) $parts["year"])
        ) {
            throw RestRequestException::invalid($field);
        }

        return ReadingDate::exact(
            (int) $parts["year"],
            (int) $parts["month"],
            (int) $parts["day"]
        );
    }

    private function publicAssessmentCursorCodec(): PublicAssessmentCursorCodec
    {
        return $this->publicAssessmentCursors
            ?? new PublicAssessmentCursorCodec();
    }

    /**
     * @param list<string> $fields
     */
    private function validateQueryFields(
        WP_REST_Request $request,
        array $fields
    ): void {
        if (array_diff(array_keys($request->get_query_params()), $fields) !== []) {
            throw RestRequestException::unknownFields();
        }
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string> $fields
     */
    private function validateBodyFields(array $body, array $fields): void
    {
        if (array_diff(array_keys($body), $fields) !== []) {
            throw RestRequestException::unknownFields();
        }

        foreach ($fields as $field) {
            if (!array_key_exists($field, $body)) {
                throw RestRequestException::missing($field);
            }
        }
    }

    /** @return array<string, mixed> */
    private function jsonObject(
        WP_REST_Request $request,
        string $firstRequiredField
    ): array {
        $raw = $request->get_body();

        if (trim($raw) === "") {
            throw RestRequestException::missing($firstRequiredField);
        }

        try {
            $shape = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);

            if (!$shape instanceof stdClass) {
                throw RestRequestException::wrongType("body", "a JSON object");
            }

            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (RestRequestException $exception) {
            throw $exception;
        } catch (JsonException) {
            throw RestRequestException::invalid("body");
        }

        if (!is_array($body)) {
            throw RestRequestException::wrongType("body", "a JSON object");
        }

        return $body;
    }

    private function privateNoteVersion(mixed $value): PrivateNoteVersion
    {
        if (!is_int($value)) {
            throw RestRequestException::wrongType(
                "expected_version",
                "an integer"
            );
        }

        try {
            return new PrivateNoteVersion($value);
        } catch (Throwable) {
            throw RestRequestException::invalid("expected_version");
        }
    }

    private function nextReadingEntryId(
        WP_REST_Request $request
    ): NextReadingEntryId {
        return $this->identifier(
            $request->get_url_params()["entry_id"] ?? null,
            "entry_id",
            static fn (string $value): NextReadingEntryId =>
                new NextReadingEntryId($value)
        );
    }

    private function nextReadingListVersion(
        mixed $value
    ): NextReadingListVersion {
        if (!is_int($value)) {
            throw RestRequestException::wrongType(
                "expected_version",
                "an integer"
            );
        }

        try {
            return new NextReadingListVersion($value);
        } catch (Throwable) {
            throw RestRequestException::invalid("expected_version");
        }
    }

    private function nextReadingPreferredSource(
        mixed $value
    ): RestNextReadingPreferredSource {
        if (!is_array($value) || array_is_list($value)) {
            throw RestRequestException::wrongType(
                "preferred_source",
                "a JSON object"
            );
        }

        if (!isset($value["type"]) || !is_string($value["type"])) {
            throw RestRequestException::invalid("preferred_source");
        }

        $type = PreferredReadingSourceType::tryFrom($value["type"]);

        if ($type === PreferredReadingSourceType::LibraryItem) {
            if (!$this->hasExactFields(
                $value,
                ["type", "library_id", "item_id"]
            )) {
                throw RestRequestException::invalid("preferred_source");
            }

            return RestNextReadingPreferredSource::libraryItem(
                $this->identifier(
                    $value["library_id"],
                    "library_id",
                    static fn (string $id): LibraryId => new LibraryId($id)
                ),
                $this->identifier(
                    $value["item_id"],
                    "item_id",
                    static fn (string $id): ItemId => new ItemId($id)
                )
            );
        }

        if ($type === PreferredReadingSourceType::ExternalLoan) {
            if (!$this->hasExactFields(
                $value,
                ["type", "external_loan_id"]
            )) {
                throw RestRequestException::invalid("preferred_source");
            }

            return RestNextReadingPreferredSource::externalLoan(
                $this->identifier(
                    $value["external_loan_id"],
                    "external_loan_id",
                    static fn (string $id): ExternalLoanId =>
                        new ExternalLoanId($id)
                )
            );
        }

        throw RestRequestException::invalid("preferred_source");
    }

    private function nextReadingWorkCursorCodec(): NextReadingWorkCursorCodec
    {
        return $this->nextReadingWorkCursors ?? new NextReadingWorkCursorCodec();
    }

    /**
     * @param array<string, mixed> $value
     * @param list<string> $fields
     */
    private function hasExactFields(array $value, array $fields): bool
    {
        return count($value) === count($fields)
            && array_diff(array_keys($value), $fields) === [];
    }

    private function positiveQueryInteger(
        mixed $value,
        string $field,
        int $default
    ): int {
        if ($value === null) {
            return $default;
        }

        if (
            !(is_int($value)
                || (is_string($value)
                    && preg_match('/^[0-9]+$/D', $value) === 1))
        ) {
            throw RestRequestException::wrongType($field, "an integer");
        }

        return (int) $value;
    }

    /**
     * @template T
     * @param callable(string): T $create
     * @return T
     */
    private function identifier(
        mixed $value,
        string $field,
        callable $create
    ): mixed {
        if ($value === null) {
            throw RestRequestException::missing($field);
        }

        if (!is_string($value)) {
            throw RestRequestException::wrongType($field, "a string");
        }

        try {
            return $create($value);
        } catch (Throwable) {
            throw RestRequestException::invalid($field);
        }
    }

    public function metadataLookupIdentifier(WP_REST_Request $request): string
    {
        $this->validateQueryFields($request, []);
        $body = $this->jsonObject($request, "identifier");
        $this->validateBodyFields($body, ["identifier"]);

        if (
            !is_string($body["identifier"])
            || strlen($body["identifier"]) > 64
        ) {
            throw RestRequestException::wrongType(
                "identifier",
                "an ISBN string of at most 64 bytes"
            );
        }

        return $body["identifier"];
    }

    public function addBookCommit(WP_REST_Request $request): AddBookCommitRequest
    {
        $this->validateQueryFields($request, []);
        $body = $this->jsonObject($request, "selection");
        $this->validateBodyFields(
            $body,
            ["identifier", "selection", "observed_fields", "classification", "item"]
        );

        $identifier = $body["identifier"];
        if (
            $identifier !== null
            && (!is_string($identifier) || strlen($identifier) > 64)
        ) {
            throw RestRequestException::wrongType(
                "identifier",
                "null or an ISBN string of at most 64 bytes"
            );
        }

        if (!is_array($body["selection"]) || array_is_list($body["selection"])) {
            throw RestRequestException::wrongType("selection", "a JSON object");
        }
        $selection = $body["selection"];
        if (($selection["type"] ?? null) === "manual") {
            if (
                !$this->hasExactFields($selection, ["type"])
                && !$this->hasExactFields($selection, ["type", "work_id"])
            ) {
                throw RestRequestException::invalid("selection");
            }
            $commitSelection = AddBookCommitSelection::manual(
                $this->optionalIdentifierValue(
                    $selection,
                    "work_id",
                    static fn (string $value): WorkId => new WorkId($value)
                )
            );
        } elseif (($selection["type"] ?? null) === "candidate") {
            if (!$this->hasExactFields(
                $selection,
                ["type", "lookup_id", "candidate_id"]
            )) {
                throw RestRequestException::invalid("selection");
            }
            $commitSelection = AddBookCommitSelection::candidate(
                $this->identifier(
                    $selection["lookup_id"],
                    "lookup_id",
                    static fn (string $value): MetadataLookupId =>
                        new MetadataLookupId($value)
                ),
                $this->identifier(
                    $selection["candidate_id"],
                    "candidate_id",
                    static fn (string $value): MetadataCandidateId =>
                        new MetadataCandidateId($value)
                )
            );
        } elseif (($selection["type"] ?? null) === "existing_edition") {
            if (!$this->hasExactFields(
                $selection,
                ["type", "edition_id"]
            )) {
                throw RestRequestException::invalid("selection");
            }
            $commitSelection = AddBookCommitSelection::existingEdition(
                $this->identifier(
                    $selection["edition_id"],
                    "edition_id",
                    static fn (string $value): EditionId =>
                        new EditionId($value)
                )
            );
        } else {
            throw RestRequestException::invalid("selection");
        }

        $observed = $this->observedMetadata($body["observed_fields"]);
        if (
            !is_array($body["item"])
            || ($body["item"] !== [] && array_is_list($body["item"]))
        ) {
            throw RestRequestException::wrongType("item", "a JSON object");
        }
        $item = $body["item"];
        if (array_diff(array_keys($item), ["inventory_number", "location_id"]) !== []) {
            throw RestRequestException::unknownFields();
        }

        try {
            return new AddBookCommitRequest(
                $identifier,
                $commitSelection,
                $observed,
                $this->addBookClassification($body["classification"]),
                $this->optionalIdentifierValue(
                    $item,
                    "inventory_number",
                    static fn (string $value): InventoryNumber =>
                        new InventoryNumber($value)
                ),
                $this->optionalIdentifierValue(
                    $item,
                    "location_id",
                    static fn (string $value): LocationId => new LocationId($value)
                )
            );
        } catch (RestRequestException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw RestRequestException::invalid("body");
        }
    }

    private function addBookClassification(
        mixed $raw
    ): LibraryCatalogContextInitialization {
        if (!is_array($raw) || array_is_list($raw) || !$this->hasExactFields(
            $raw,
            ["book_type_id", "genre_ids", "subject_ids"]
        )) {
            throw RestRequestException::invalid("classification");
        }
        if (
            !is_array($raw["genre_ids"])
            || !array_is_list($raw["genre_ids"])
            || !is_array($raw["subject_ids"])
            || !array_is_list($raw["subject_ids"])
        ) {
            throw RestRequestException::invalid("classification");
        }

        $genreIds = [];
        foreach ($raw["genre_ids"] as $id) {
            $genreIds[] = $this->identifier(
                $id,
                "genre_ids",
                static fn (string $value): LibraryGenreId =>
                    new LibraryGenreId($value)
            );
        }
        $subjectIds = [];
        foreach ($raw["subject_ids"] as $id) {
            $subjectIds[] = $this->identifier(
                $id,
                "subject_ids",
                static fn (string $value): LibrarySubjectId =>
                    new LibrarySubjectId($value)
            );
        }

        return new LibraryCatalogContextInitialization(
            new LibraryCatalogSelection(
                $this->identifier(
                    $raw["book_type_id"],
                    "book_type_id",
                    static fn (string $value): LibraryBookTypeId =>
                        new LibraryBookTypeId($value)
                ),
                $genreIds,
                $subjectIds
            )
        );
    }

    private function observedMetadata(mixed $raw): AddBookObservedMetadata
    {
        if (!is_array($raw) || ($raw !== [] && array_is_list($raw))) {
            throw RestRequestException::wrongType(
                "observed_fields",
                "a JSON object"
            );
        }
        $values = [];
        foreach ($raw as $fieldKey => $value) {
            $field = UserObservedMetadataField::tryFrom((string) $fieldKey);
            if ($field === null || !$this->validObservedValue($field, $value)) {
                throw RestRequestException::invalid("observed_fields");
            }
            try {
                $values[$field->value] = new MetadataFieldValue($value);
            } catch (Throwable) {
                throw RestRequestException::invalid("observed_fields");
            }
        }
        return new AddBookObservedMetadata($values);
    }

    private function validObservedValue(
        UserObservedMetadataField $field,
        mixed $value
    ): bool
    {
        return match ($field) {
            UserObservedMetadataField::Contributors,
            UserObservedMetadataField::Languages,
            UserObservedMetadataField::Publishers => is_array($value)
                && array_is_list($value)
                && count(array_filter($value, "is_string")) === count($value),
            UserObservedMetadataField::PageCount => is_int($value),
            default => is_string($value),
        };
    }

    /**
     * @template T
     * @param array<string, mixed> $values
     * @param callable(string): T $create
     * @return ?T
     */
    private function optionalIdentifierValue(
        array $values,
        string $field,
        callable $create
    ): mixed {
        if (!array_key_exists($field, $values) || $values[$field] === null) {
            return null;
        }
        return $this->identifier($values[$field], $field, $create);
    }
}
