<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Search;

use Biblio\Core\Exception\ValidationException;
use Throwable;

final readonly class BibliographicTextSearchContract
{
    public function __construct(
        private BibliographicSearchCursorCodec $cursors,
        private BibliographicAuthorSelectorCodec $authorSelectors
    ) {}

    /** @param array<string,mixed> $payload */
    public function decodeRequest(array $payload): BibliographicTextSearchRequest
    {
        try {
            if (!array_key_exists("query", $payload)
                || array_diff(
                    array_keys($payload),
                    ["query", "author_cursor", "work_cursor"]
                ) !== []
                || !is_string($payload["query"])) {
                throw new ValidationException("Invalid bibliographic text-search request.");
            }
            $authorCursor = $payload["author_cursor"] ?? null;
            $workCursor = $payload["work_cursor"] ?? null;
            if (!($authorCursor === null || is_string($authorCursor))
                || !($workCursor === null || is_string($workCursor))) {
                throw new ValidationException("Invalid bibliographic text-search request.");
            }
            return new BibliographicTextSearchRequest(
                new BibliographicTextSearchQuery($payload["query"]),
                $authorCursor === null
                    ? null
                    : $this->cursors->decode($authorCursor),
                $workCursor === null
                    ? null
                    : $this->cursors->decode($workCursor)
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new ValidationException("Invalid bibliographic text-search request.");
        }
    }

    /** @return array<string,mixed> */
    public function serialize(BibliographicTextSearchResult $result): array
    {
        return [
            "query" => $result->query()->value(),
            "authors" => [
                "items" => array_map(
                    fn (BibliographicAuthorSearchResult $author): array => [
                        "result_id" => $author->reference()->resultId(),
                        "result_kind" => $author->reference()->kind()->value,
                        "author_id" => $author->reference()->authorId()?->value(),
                        "display_name" => $author->displayName(),
                        "author_selector" => $this->authorSelectors->encode(
                            $author->reference()
                        ),
                    ],
                    $result->authors()->items()
                ),
                "next_cursor" => $result->authors()->nextCursor() === null
                    ? null
                    : $this->cursors->encode($result->authors()->nextCursor()),
                "provider_attempts" => $this->serializeAttempts(
                    $result->authorProviderAttempts()
                ),
            ],
            "works" => [
                "items" => array_map($this->serializeWork(...), $result->works()->items()),
                "next_cursor" => $result->works()->nextCursor() === null
                    ? null
                    : $this->cursors->encode($result->works()->nextCursor()),
                "provider_attempts" => $this->serializeAttempts(
                    $result->workProviderAttempts()
                ),
            ],
        ];
    }

    /**
     * @param list<BibliographicSearchProviderAttempt> $attempts
     * @return list<array{provider_key:string,status:string,failure_reason:?string}>
     */
    private function serializeAttempts(array $attempts): array
    {
        return array_map(
            static fn (BibliographicSearchProviderAttempt $attempt): array => [
                "provider_key" => $attempt->providerKey(),
                "status" => $attempt->status()->value,
                "failure_reason" => $attempt->failureReason()?->value,
            ],
            $attempts
        );
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
