<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Rest;

use Biblio\Core\Application\Metadata\Search\BibliographicEditionSearchContract;
use Biblio\Core\Application\Metadata\Search\BibliographicEditionSearchPage;
use Biblio\Core\Application\Metadata\Search\BibliographicEditionSearchRequest;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkSelectorCodec;
use Biblio\Core\Exception\ValidationException;

final readonly class RestBibliographicWorkEditionSearchContract
{
    public function __construct(
        private BibliographicWorkSelectorCodec $selectors,
        private BibliographicEditionSearchContract $contract
    ) {
    }

    /** @param array<string,mixed> $body */
    public function decodeRequest(array $body): BibliographicEditionSearchRequest
    {
        if (array_diff(array_keys($body), ["work_selector", "cursor"]) !== []) {
            throw RestRequestException::unknownFields();
        }
        if (!array_key_exists("work_selector", $body)) {
            throw RestRequestException::missing("work_selector");
        }
        if (!is_string($body["work_selector"])) {
            throw RestRequestException::wrongType("work_selector", "a string");
        }
        if (array_key_exists("cursor", $body)
            && $body["cursor"] !== null
            && !is_string($body["cursor"])) {
            throw RestRequestException::wrongType("cursor", "a string or null");
        }

        try {
            $work = $this->selectors->decode($body["work_selector"]);
            return $this->contract->decodeRequest($work, [
                "cursor" => $body["cursor"] ?? null,
            ]);
        } catch (ValidationException) {
            throw RestRequestException::invalid("bibliographic_work_editions");
        }
    }

    /** @return array<string,mixed> */
    public function serialize(BibliographicEditionSearchPage $page): array
    {
        return $this->contract->serialize($page);
    }
}
