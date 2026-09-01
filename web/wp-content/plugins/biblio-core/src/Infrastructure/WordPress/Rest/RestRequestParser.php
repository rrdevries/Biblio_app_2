<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Application\Catalog\Read\CatalogOverviewCursor;
use Biblio\Core\Application\Catalog\Read\CatalogOverviewPageSize;
use Biblio\Core\Application\Reading\History\ReadingHistoryCursor;
use Biblio\Core\Application\Reading\History\ReadingHistoryPageSize;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Notes\PrivateNoteId;
use Biblio\Core\Notes\PrivateNotePageRequest;
use Biblio\Core\Notes\PrivateNoteVersion;
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
        private PrivateNoteCursorCodec $privateNoteCursors
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
}
