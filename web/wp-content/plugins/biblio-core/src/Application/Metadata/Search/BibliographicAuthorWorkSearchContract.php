<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;

final readonly class BibliographicAuthorWorkSearchContract
{
    public function __construct(private BibliographicAuthorWorkSearchCursorCodec $cursors) {}

    /** @param array<string,mixed> $payload */
    public function decodeRequest(
        BibliographicAuthorReference $selectedAuthor,
        array $payload
    ): BibliographicAuthorWorkSearchRequest {
        if (array_diff(array_keys($payload), ["cursor"]) !== []) {
            throw new ValidationException("Invalid Works-by-Author request.");
        }
        $cursor = $payload["cursor"] ?? null;
        if (!($cursor === null || is_string($cursor))) {
            throw new ValidationException("Invalid Works-by-Author request.");
        }
        return new BibliographicAuthorWorkSearchRequest(
            $selectedAuthor,
            $cursor === null ? null : $this->cursors->decode($cursor)
        );
    }

    /** @return array<string,mixed> */
    public function serialize(BibliographicAuthorWorkSearchPage $page): array
    {
        return [
            "items" => array_map($this->serializeWork(...), $page->items()),
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
    private function serializeWork(BibliographicWorkSearchResult $work): array
    {
        return [
            "result_id" => $work->reference()->resultId(),
            "result_kind" => $work->reference()->kind()->value,
            "work_id" => $work->reference()->workId()?->value(),
            "title" => $work->title(),
            "authors" => array_map(
                static fn (BibliographicWorkAuthor $author): array => [
                    "author_id" => $author->authorId()?->value(),
                    "display_name" => $author->displayName(),
                ],
                $work->authors()
            ),
            "series" => array_map(
                static fn (BibliographicWorkSeriesContext $series): array => [
                    "series_id" => $series->seriesId()?->value(),
                    "display_name" => $series->displayName(),
                    "position" => $series->position()?->value(),
                ],
                $work->series()
            ),
        ];
    }
}
