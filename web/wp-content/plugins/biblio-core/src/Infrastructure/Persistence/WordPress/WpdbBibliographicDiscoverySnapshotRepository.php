<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Metadata\Discovery\BibliographicCandidateType;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryCandidate;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoveryQuery;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoverySnapshot;
use Biblio\Core\Application\Metadata\Discovery\BibliographicDiscoverySnapshotRepository;
use Biblio\Core\Application\Metadata\Discovery\BibliographicQueryType;
use Biblio\Core\Application\Metadata\Discovery\BibliographicTextQuery;
use Biblio\Core\Application\Metadata\MetadataCandidateId;
use Biblio\Core\Application\Metadata\MetadataLookupId;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\Isbn10;
use Biblio\Core\Catalog\Isbn13;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;
use wpdb;

final readonly class WpdbBibliographicDiscoverySnapshotRepository implements
    BibliographicDiscoverySnapshotRepository
{
    private const string DATE_FORMAT = "Y-m-d H:i:s.u";

    public function __construct(private wpdb $database, private CoreTableNames $tables) {}

    public function save(BibliographicDiscoverySnapshot $snapshot): void
    {
        $query = $snapshot->query();
        if ($this->database->insert($this->tables->bibliographicDiscoverySnapshots(), [
            "discovery_id" => $snapshot->id()->value(),
            "actor_user_id" => $snapshot->actorId()->value(),
            "canonical_isbn_13" => $query->type() === BibliographicQueryType::Isbn
                ? $query->requireIsbn()->isbn13()->value() : null,
            "query_type" => $query->type()->value,
            "normalized_query" => $query->normalizedValue(),
            "created_at" => $this->date($snapshot->createdAt()),
            "expires_at" => $this->date($snapshot->expiresAt()),
        ], ["%s", "%s", "%s", "%s", "%s", "%s", "%s"]) !== 1) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist bibliographic discovery snapshot.",
                $this->database->last_error
            );
        }

        foreach ($snapshot->candidates() as $id => $candidate) {
            $json = $this->json($candidate);
            if ($this->database->insert($this->tables->bibliographicDiscoveryCandidates(), [
                "discovery_id" => $snapshot->id()->value(),
                "candidate_id" => $id,
                "candidate_json" => $json,
                "candidate_hash" => hash("sha256", $json),
            ], ["%s", "%s", "%s", "%s"]) !== 1) {
                throw WpdbErrorTranslator::writeFailure(
                    "Could not persist bibliographic discovery candidate.",
                    $this->database->last_error
                );
            }
        }
    }

    public function candidateForMaterialization(
        MetadataLookupId $discoveryId,
        MetadataCandidateId $candidateId,
        UserId $actorId,
        DateTimeImmutable $at
    ): ?BibliographicDiscoveryCandidate {
        $snapshots = $this->tables->bibliographicDiscoverySnapshots();
        $candidates = $this->tables->bibliographicDiscoveryCandidates();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT c.candidate_json,c.candidate_hash FROM `{$snapshots}` s "
                . "INNER JOIN `{$candidates}` c ON c.discovery_id=s.discovery_id "
                . "WHERE s.discovery_id=%s AND c.candidate_id=%s "
                . "AND s.actor_user_id=%s "
                . "AND s.expires_at>%s",
            $discoveryId->value(),
            $candidateId->value(),
            $actorId->value(),
            $this->date($at)
        ));
        if ($row === null) { return null; }

        try {
            $json = (string) $row->candidate_json;
            if (!hash_equals(hash("sha256", $json), (string) $row->candidate_hash)) {
                throw new \UnexpectedValueException("Discovery candidate hash mismatch.");
            }
            $candidate = $this->fromJson($json);
            if (!hash_equals($candidate->id(), $candidateId->value())) {
                throw new \UnexpectedValueException("Discovery candidate ID mismatch.");
            }
            return $candidate;
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored bibliographic discovery candidate is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    private function json(BibliographicDiscoveryCandidate $candidate): string
    {
        try {
            return json_encode([
                "type" => $candidate->type()->value,
                "provider_key" => $candidate->providerKey(),
                "provider_record_id" => $candidate->providerRecordId(),
                "provider_work_id" => $candidate->providerWorkId(),
                "retrieved_at" => $candidate->retrievedAt()?->setTimezone(new DateTimeZone("UTC"))->format(self::DATE_FORMAT),
                "match_method" => $candidate->matchMethod()?->value,
                "query_type" => $candidate->queryType()->value,
                "normalized_query" => $candidate->normalizedQuery(),
                "isbn_10" => $candidate->isbn()?->isbn10()?->value(),
                "isbn_13" => $candidate->isbn()?->isbn13()->value(),
                "title" => $candidate->title(),
                "subtitle" => $candidate->subtitle(),
                "contributors" => $candidate->contributors(),
                "languages" => $candidate->languages(),
                "publishers" => $candidate->publishers(),
                "publication_date" => $candidate->publicationDate(),
                "page_count" => $candidate->pageCount(),
                "format" => $candidate->format(),
                "presentation_order" => $candidate->presentationOrder(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new PersistenceException(
                "Could not encode bibliographic discovery candidate.",
                0,
                $exception,
                FailureReason::PersistenceWriteFailed
            );
        }
    }

    private function fromJson(string $json): BibliographicDiscoveryCandidate
    {
        $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new \UnexpectedValueException("Candidate is not an object."); }
        $queryType = BibliographicQueryType::from($this->string($data, "query_type"));
        $isbn13 = $this->nullableString($data, "isbn_13");
        $isbn = $isbn13 === null ? null : new CanonicalIsbnIdentity(
            new Isbn13($isbn13),
            ($isbn10 = $this->nullableString($data, "isbn_10")) === null ? null : new Isbn10($isbn10)
        );
        $query = $queryType === BibliographicQueryType::Isbn
            ? BibliographicDiscoveryQuery::isbn($isbn ?? throw new \UnexpectedValueException("ISBN query lacks ISBN."))
            : BibliographicDiscoveryQuery::text(new BibliographicTextQuery($this->string($data, "normalized_query")));
        return BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::from($this->string($data, "type")),
            $this->string($data, "provider_key"),
            $this->string($data, "provider_record_id"),
            $this->nullableString($data, "provider_work_id"),
            $this->dateFrom($this->string($data, "retrieved_at")),
            MetadataMatchMethod::from($this->string($data, "match_method")),
            $query,
            $this->string($data, "title"),
            $isbn,
            $this->nullableString($data, "subtitle"),
            $this->strings($data, "contributors"),
            $this->strings($data, "languages"),
            $this->strings($data, "publishers"),
            $this->nullableString($data, "publication_date"),
            $this->nullableInt($data, "page_count"),
            $this->nullableString($data, "format"),
            $this->integer($data, "presentation_order")
        );
    }

    /** @param array<string,mixed> $data */
    private function string(array $data, string $key): string
    {
        if (!isset($data[$key]) || !is_string($data[$key])) { throw new \UnexpectedValueException("Invalid string field."); }
        return $data[$key];
    }
    /** @param array<string,mixed> $data */
    private function nullableString(array $data, string $key): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) { return null; }
        return $this->string($data, $key);
    }
    /**
     * @param array<string,mixed> $data
     * @return list<string>
     */
    private function strings(array $data, string $key): array
    {
        if (!isset($data[$key]) || !is_array($data[$key]) || !array_is_list($data[$key])) { throw new \UnexpectedValueException("Invalid list field."); }
        foreach ($data[$key] as $value) { if (!is_string($value)) { throw new \UnexpectedValueException("Invalid list value."); } }
        return $data[$key];
    }
    /** @param array<string,mixed> $data */
    private function nullableInt(array $data, string $key): ?int
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) { return null; }
        return $this->integer($data, $key);
    }
    /** @param array<string,mixed> $data */
    private function integer(array $data, string $key): int
    {
        if (!isset($data[$key]) || !is_int($data[$key])) { throw new \UnexpectedValueException("Invalid integer field."); }
        return $data[$key];
    }
    private function date(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone("UTC"))->format(self::DATE_FORMAT);
    }
    private function dateFrom(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat("!" . self::DATE_FORMAT, $value, new DateTimeZone("UTC"));
        return $date ?: throw new \UnexpectedValueException("Invalid date field.");
    }
}
