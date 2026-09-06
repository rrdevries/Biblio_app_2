<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Metadata\MetadataCandidate;
use Biblio\Core\Application\Metadata\MetadataCandidateId;
use Biblio\Core\Application\Metadata\MetadataLookupId;
use Biblio\Core\Application\Metadata\MetadataLookupSnapshot;
use Biblio\Core\Application\Metadata\MetadataLookupSnapshotRepository;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Application\Metadata\MetadataWorkLink;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\Isbn10;
use Biblio\Core\Catalog\Isbn13;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;
use wpdb;

final readonly class WpdbMetadataLookupSnapshotRepository implements
    MetadataLookupSnapshotRepository
{
    private const string DATE_FORMAT = "Y-m-d H:i:s.u";

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
    }

    public function save(MetadataLookupSnapshot $snapshot): void
    {
        $createdAt = $this->date($snapshot->createdAt());
        $expiresAt = $this->date($snapshot->expiresAt());
        if ($this->database->insert(
            $this->tables->metadataLookupSnapshots(),
            [
                "lookup_id" => $snapshot->id()->value(),
                "actor_user_id" => $snapshot->actorId()->value(),
                "library_id" => $snapshot->libraryId()->value(),
                "canonical_isbn_13" => $snapshot->identifier()->isbn13()->value(),
                "created_at" => $createdAt,
                "expires_at" => $expiresAt,
            ],
            ["%s", "%s", "%s", "%s", "%s", "%s"]
        ) !== 1) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist metadata lookup snapshot.",
                $this->database->last_error
            );
        }

        foreach ($snapshot->candidates() as $candidateId => $candidate) {
            $json = $this->candidateJson($candidate);
            if ($this->database->insert(
                $this->tables->metadataLookupCandidates(),
                [
                    "lookup_id" => $snapshot->id()->value(),
                    "candidate_id" => $candidateId,
                    "candidate_json" => $json,
                    "candidate_hash" => hash("sha256", $json),
                ],
                ["%s", "%s", "%s", "%s"]
            ) !== 1) {
                throw WpdbErrorTranslator::writeFailure(
                    "Could not persist reviewed metadata candidate.",
                    $this->database->last_error
                );
            }
        }
    }

    public function candidateForCommit(
        MetadataLookupId $lookupId,
        MetadataCandidateId $candidateId,
        UserId $actorId,
        LibraryId $libraryId,
        DateTimeImmutable $at
    ): ?MetadataCandidate {
        $snapshots = $this->tables->metadataLookupSnapshots();
        $candidates = $this->tables->metadataLookupCandidates();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT c.candidate_json,c.candidate_hash "
                . "FROM `{$snapshots}` s INNER JOIN `{$candidates}` c "
                . "ON c.lookup_id=s.lookup_id "
                . "WHERE s.lookup_id=%s AND c.candidate_id=%s "
                . "AND s.actor_user_id=%s AND s.library_id=%s "
                . "AND s.expires_at>%s",
            $lookupId->value(),
            $candidateId->value(),
            $actorId->value(),
            $libraryId->value(),
            $this->date($at)
        ));
        if ($row === null) {
            return null;
        }

        try {
            $json = (string) $row->candidate_json;
            if (!hash_equals(hash("sha256", $json), (string) $row->candidate_hash)) {
                throw new \UnexpectedValueException("Candidate snapshot hash mismatch.");
            }
            $candidate = $this->candidateFromJson($json);
            if (
                MetadataCandidateId::fromCandidate($candidate)->value()
                    !== $candidateId->value()
            ) {
                throw new \UnexpectedValueException("Candidate snapshot ID mismatch.");
            }
            return $candidate;
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored metadata candidate snapshot is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    private function candidateJson(MetadataCandidate $candidate): string
    {
        try {
            return json_encode([
                "provider_key" => $candidate->providerKey(),
                "provider_record_id" => $candidate->providerRecordId(),
                "retrieved_at" => $candidate->retrievedAt()
                    ->setTimezone(new DateTimeZone("UTC"))->format(self::DATE_FORMAT),
                "match_method" => $candidate->matchMethod()->value,
                "queried_isbn_10" => $candidate->queriedIsbn()->isbn10()?->value(),
                "queried_isbn_13" => $candidate->queriedIsbn()->isbn13()->value(),
                "returned_isbn_10" => $candidate->returnedIsbn()->isbn10()?->value(),
                "returned_isbn_13" => $candidate->returnedIsbn()->isbn13()->value(),
                "title" => $candidate->title(),
                "subtitle" => $candidate->subtitle(),
                "contributors" => $candidate->contributors(),
                "languages" => $candidate->languages(),
                "publishers" => $candidate->publishers(),
                "publication_date" => $candidate->publicationDate(),
                "page_count" => $candidate->pageCount(),
                "format" => $candidate->format(),
                "provider_work_key" => $candidate->workLink()?->providerWorkKey(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new PersistenceException(
                "Could not encode metadata candidate snapshot.",
                0,
                $exception,
                FailureReason::PersistenceWriteFailed
            );
        }
    }

    private function candidateFromJson(string $json): MetadataCandidate
    {
        $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \UnexpectedValueException("Candidate snapshot is not an object.");
        }

        $queried = new CanonicalIsbnIdentity(
            new Isbn13($this->string($data, "queried_isbn_13")),
            $this->nullableString($data, "queried_isbn_10") === null
                ? null
                : new Isbn10($this->string($data, "queried_isbn_10"))
        );
        $returned = new CanonicalIsbnIdentity(
            new Isbn13($this->string($data, "returned_isbn_13")),
            $this->nullableString($data, "returned_isbn_10") === null
                ? null
                : new Isbn10($this->string($data, "returned_isbn_10"))
        );
        $workKey = $this->nullableString($data, "provider_work_key");

        return new MetadataCandidate(
            $this->string($data, "provider_key"),
            $this->string($data, "provider_record_id"),
            $this->parseDate($this->string($data, "retrieved_at")),
            MetadataMatchMethod::from($this->string($data, "match_method")),
            $queried,
            $returned,
            $this->nullableString($data, "title"),
            $this->nullableString($data, "subtitle"),
            $this->stringList($data, "contributors"),
            $this->stringList($data, "languages"),
            $this->stringList($data, "publishers"),
            $this->nullableString($data, "publication_date"),
            $this->nullableInt($data, "page_count"),
            $this->nullableString($data, "format"),
            $workKey === null ? null : new MetadataWorkLink($workKey)
        );
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) {
            throw new \UnexpectedValueException("Invalid candidate snapshot field.");
        }
        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private function nullableString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        return $this->string($data, $key);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function stringList(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key]) || !array_is_list($data[$key])) {
            throw new \UnexpectedValueException("Invalid candidate snapshot list.");
        }

        $strings = [];
        foreach ($data[$key] as $value) {
            if (!is_string($value)) {
                throw new \UnexpectedValueException("Invalid candidate snapshot list value.");
            }
            $strings[] = $value;
        }
        return $strings;
    }

    /** @param array<string, mixed> $data */
    private function nullableInt(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (!is_int($data[$key])) {
            throw new \UnexpectedValueException("Invalid candidate snapshot integer.");
        }
        return $data[$key];
    }

    private function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone("UTC"))->format(self::DATE_FORMAT);
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            "!" . self::DATE_FORMAT,
            $value,
            new DateTimeZone("UTC")
        );
        if ($date === false) {
            throw new \UnexpectedValueException("Invalid candidate snapshot date.");
        }
        return $date;
    }
}
