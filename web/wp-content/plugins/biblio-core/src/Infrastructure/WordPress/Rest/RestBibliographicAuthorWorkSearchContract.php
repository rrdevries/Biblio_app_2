<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Application\Metadata\Search\BibliographicAuthorSelectorCodec;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorWorkSearchContract;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorWorkSearchPage;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorWorkSearchRequest;
use Biblio\Core\Exception\ValidationException;

final readonly class RestBibliographicAuthorWorkSearchContract
{
    public function __construct(
        private BibliographicAuthorSelectorCodec $selectors,
        private BibliographicAuthorWorkSearchContract $contract
    ) {
    }

    /** @param array<string,mixed> $body */
    public function decodeRequest(array $body): BibliographicAuthorWorkSearchRequest
    {
        if (array_diff(array_keys($body), ["author_selector", "cursor"]) !== []) {
            throw RestRequestException::unknownFields();
        }
        if (!array_key_exists("author_selector", $body)) {
            throw RestRequestException::missing("author_selector");
        }
        if (!is_string($body["author_selector"])) {
            throw RestRequestException::wrongType("author_selector", "a string");
        }
        if (array_key_exists("cursor", $body)
            && $body["cursor"] !== null
            && !is_string($body["cursor"])) {
            throw RestRequestException::wrongType("cursor", "a string or null");
        }

        try {
            $author = $this->selectors->decode($body["author_selector"]);
            return $this->contract->decodeRequest($author, [
                "cursor" => $body["cursor"] ?? null,
            ]);
        } catch (ValidationException) {
            throw RestRequestException::invalid("bibliographic_author_works");
        }
    }

    /** @return array<string,mixed> */
    public function serialize(BibliographicAuthorWorkSearchPage $page): array
    {
        return $this->contract->serialize($page);
    }
}
