<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchContract;
use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchRequest;
use Biblio\Core\Application\Metadata\Search\BibliographicTextSearchResult;
use Biblio\Core\Exception\ValidationException;

final readonly class RestBibliographicTextSearchContract
{
    public function __construct(private BibliographicTextSearchContract $contract)
    {
    }

    /** @param array<string,mixed> $body */
    public function decodeRequest(array $body): BibliographicTextSearchRequest
    {
        if (array_diff(
            array_keys($body),
            ["query", "author_cursor", "work_cursor"]
        ) !== []) {
            throw RestRequestException::unknownFields();
        }
        if (!array_key_exists("query", $body)) {
            throw RestRequestException::missing("query");
        }
        if (!is_string($body["query"])) {
            throw RestRequestException::wrongType("query", "a string");
        }
        foreach (["author_cursor", "work_cursor"] as $field) {
            if (array_key_exists($field, $body)
                && $body[$field] !== null
                && !is_string($body[$field])) {
                throw RestRequestException::wrongType($field, "a string or null");
            }
        }

        try {
            return $this->contract->decodeRequest($body);
        } catch (ValidationException) {
            throw RestRequestException::invalid("bibliographic_search");
        }
    }

    /** @return array<string,mixed> */
    public function serialize(BibliographicTextSearchResult $result): array
    {
        return $this->contract->serialize($result);
    }
}
