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
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundOutcome;
use Biblio\Core\Reading\ReadingRoundVersion;
use Throwable;
use WP_REST_Request;

final readonly class RestRequestParser
{
    public function __construct(
        private CatalogCursorCodec $cursors,
        private ReadingHistoryCursorCodec $historyCursors
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
        if (
            array_diff(
                array_keys($request->get_query_params()),
                ["limit", "cursor"]
            ) !== []
        ) {
            throw RestRequestException::unknownFields();
        }
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
