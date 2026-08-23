<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Application\Catalog\Read\CatalogOverviewCursor;
use Biblio\Core\Application\Catalog\Read\CatalogOverviewPageSize;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Reading\ReadingDate;
use Throwable;
use WP_REST_Request;

final readonly class RestRequestParser
{
    public function __construct(private CatalogCursorCodec $cursors)
    {
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

        if (
            preg_match(
                '/^(?<year>[0-9]{4})-(?<month>[0-9]{2})-(?<day>[0-9]{2})$/D',
                $body["started_on"],
                $parts
            ) !== 1
            || !checkdate((int) $parts["month"], (int) $parts["day"], (int) $parts["year"])
        ) {
            throw RestRequestException::invalid("started_on");
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
