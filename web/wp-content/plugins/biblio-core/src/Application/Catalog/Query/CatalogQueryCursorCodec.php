<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Query;

use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use JsonException;

final readonly class CatalogQueryCursorCodec
{
    public function __construct(private string $secret)
    {
        if (strlen($secret) < 32) {
            throw new ValidationException('Catalog cursor secret must contain at least 32 bytes.');
        }
    }

    public function encode(
        CatalogQuery $query,
        LibraryId $libraryId,
        UserId $actorId,
        ItemId $lastItemId
    ): CatalogQueryCursor {
        $payload = $this->base64UrlEncode(json_encode([
            'v' => 1,
            'f' => $this->fingerprint($query, $libraryId, $actorId),
            'i' => $lastItemId->value(),
        ], JSON_THROW_ON_ERROR));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->secret, true));
        return new CatalogQueryCursor($payload . '.' . $signature);
    }

    public function decode(
        CatalogQueryCursor $cursor,
        CatalogQuery $query,
        LibraryId $libraryId,
        UserId $actorId
    ): ItemId {
        $parts = explode('.', $cursor->opaqueValue());
        if (count($parts) !== 2) {
            throw new ValidationException('Catalog query cursor is invalid.');
        }
        [$payload, $signature] = $parts;
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->secret, true));
        if (!hash_equals($expected, $signature)) {
            throw new ValidationException('Catalog query cursor is invalid.');
        }
        try {
            $decoded = json_decode($this->base64UrlDecode($payload), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ValidationException('Catalog query cursor is invalid.');
        }
        if (
            !is_array($decoded)
            || array_keys($decoded) !== ['v', 'f', 'i']
            || $decoded['v'] !== 1
            || !is_string($decoded['f'])
            || !is_string($decoded['i'])
            || !hash_equals($this->fingerprint($query, $libraryId, $actorId), $decoded['f'])
        ) {
            throw new ValidationException('Catalog query cursor does not match the query context.');
        }
        return new ItemId($decoded['i']);
    }

    public function fingerprint(CatalogQuery $query, LibraryId $libraryId, UserId $actorId): string
    {
        $filters = $query->filters();
        $canonical = [
            'library' => $libraryId->value(),
            'actor' => $actorId->value(),
            'search' => $query->search()?->value(),
            'sort' => $query->sort()->value,
            'page_size' => $query->pageSize()->value(),
            'archive' => $query->archiveScope()->value,
            'reading' => $this->enumValues($filters->readingStatuses()),
            'authors' => $this->idValues($filters->authorIds()),
            'series' => $this->idValues($filters->seriesIds()),
            'locations' => $this->idValues($filters->locationIds()),
            'book_types' => $this->idValues($filters->bookTypeIds()),
            'genres' => $this->idValues($filters->genreIds()),
            'subjects' => $this->idValues($filters->subjectIds()),
            'collections' => $this->idValues($filters->collections()->collectionIds()),
            'without_collection' => $filters->collections()->isWithoutCollection(),
        ];
        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<object> $ids
     * @return list<string>
     */
    private function idValues(array $ids): array
    {
        $values = array_map(static fn (object $id): string => $id->value(), $ids);
        sort($values, SORT_STRING);
        return $values;
    }

    /**
     * @param list<\BackedEnum> $values
     * @return list<string>
     */
    private function enumValues(array $values): array
    {
        $result = array_map(static fn (\BackedEnum $value): string => (string) $value->value, $values);
        sort($result, SORT_STRING);
        return $result;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new ValidationException('Catalog query cursor is invalid.');
        }
        return $decoded;
    }
}
