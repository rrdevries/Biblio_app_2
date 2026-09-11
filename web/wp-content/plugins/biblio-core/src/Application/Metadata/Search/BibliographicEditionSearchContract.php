<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicEditionSearchContract
{
    public function __construct(private BibliographicEditionSearchCursorCodec $cursors) {}

    /** @param array<string,mixed> $payload */
    public function decodeRequest(
        BibliographicWorkReference $selectedWork,
        array $payload
    ): BibliographicEditionSearchRequest {
        if (array_diff(array_keys($payload), ["cursor"]) !== []) {
            throw new ValidationException("Invalid Editions-for-Work request.");
        }
        $cursor = $payload["cursor"] ?? null;
        if (!($cursor === null || is_string($cursor))) {
            throw new ValidationException("Invalid Editions-for-Work request.");
        }
        return new BibliographicEditionSearchRequest(
            $selectedWork,
            $cursor === null ? null : $this->cursors->decode($cursor)
        );
    }

    /** @return array<string,mixed> */
    public function serialize(BibliographicEditionSearchPage $page): array
    {
        return [
            "items" => array_map($this->serializeEdition(...), $page->items()),
            "next_cursor" => $page->nextCursor() === null
                ? null
                : $this->cursors->encode($page->nextCursor()),
            "provider_attempts" => array_map(
                static fn (BibliographicSearchProviderAttempt $attempt): array => [
                    "provider_key" => $attempt->providerKey(),
                    "status" => $attempt->status()->value,
                    "failure_reason" => $attempt->failureReason()?->value,
                ],
                $page->providerAttempts()
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function serializeEdition(BibliographicEditionSearchResult $edition): array
    {
        $provider = $edition->reference()->providerIdentity();
        $providerWork = $edition->providerWorkIdentity();
        return [
            "result_id" => $edition->reference()->resultId(),
            "result_kind" => $edition->reference()->kind()->value,
            "edition_id" => $edition->reference()->editionId()?->value(),
            "provider_identity" => $provider === null ? null : [
                "provider_key" => $provider->providerKey(),
                "record_id" => $provider->providerRecordId(),
            ],
            "provider_work_identity" => $providerWork === null ? null : [
                "provider_key" => $providerWork->providerKey(),
                "record_id" => $providerWork->providerRecordId(),
            ],
            "parent_work_result_id" => $edition->parentWork()->resultId(),
            "title" => $edition->title(),
            "subtitle" => $edition->subtitle(),
            "contributors" => $edition->contributors(),
            "languages" => $edition->languages(),
            "publishers" => $edition->publishers(),
            "publication_date" => $edition->publicationDate(),
            "isbn_10" => $edition->isbn()?->isbn10()?->value(),
            "isbn_13" => $edition->isbn()?->isbn13()->value(),
            "format" => $edition->format(),
            "page_count" => $edition->pageCount(),
            "presentation_order" => $edition->presentationOrder(),
            "requires_materialization" => $edition->requiresMaterialization(),
            "can_add_work_only" => $edition->canAddWorkOnly(),
            "can_add_edition_specific" => $edition->canAddEditionSpecific(),
        ];
    }
}
