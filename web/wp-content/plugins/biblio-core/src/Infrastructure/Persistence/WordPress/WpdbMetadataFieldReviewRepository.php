<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Metadata\MetadataField;
use Biblio\Core\Application\Metadata\MetadataFieldConfirmationState;
use Biblio\Core\Application\Metadata\MetadataFieldEvidence;
use Biblio\Core\Application\Metadata\MetadataFieldProposal;
use Biblio\Core\Application\Metadata\MetadataFieldProposalState;
use Biblio\Core\Application\Metadata\MetadataFieldReview;
use Biblio\Core\Application\Metadata\MetadataFieldReviewRepository;
use Biblio\Core\Application\Metadata\MetadataFieldValue;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Application\Metadata\MetadataRecordId;
use Biblio\Core\Catalog\IsbnType;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use wpdb;

final readonly class WpdbMetadataFieldReviewRepository implements MetadataFieldReviewRepository
{
    private const string DATE_FORMAT = "Y-m-d H:i:s.u";

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
    }

    public function find(
        MetadataRecordId $recordId,
        MetadataField $field
    ): ?MetadataFieldReview {
        return $this->read($recordId, $field, false);
    }

    public function findForUpdate(
        MetadataRecordId $recordId,
        MetadataField $field,
        DateTimeImmutable $whenMissing
    ): MetadataFieldReview {
        $existing = $this->read($recordId, $field, true);
        if ($existing !== null) {
            return $existing;
        }

        $previousSuppression = $this->database->suppress_errors(true);
        try {
            $inserted = $this->database->insert(
                $this->tables->metadataFieldStates(),
                [
                    "metadata_record_id" => $recordId->value(),
                    "field_key" => $field->value,
                    "canonical_value_json" => null,
                    "canonical_value_hash" => null,
                    "confirmation_state" => MetadataFieldConfirmationState::Unknown->value,
                    "confirmed_by_user_id" => null,
                    "field_version" => 1,
                    "updated_at" => $this->formatDate($whenMissing),
                ],
                ["%s", "%s", "%s", "%s", "%s", "%s", "%d", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($inserted !== 1 && WpdbErrorTranslator::conflict($this->database->last_error) === null) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not initialize metadata field review.",
                $this->database->last_error
            );
        }

        $review = $this->read($recordId, $field, true);
        if ($review === null) {
            throw new PersistenceException(
                "Initialized metadata field review could not be read.",
                failureReason: FailureReason::PersistenceReadFailed
            );
        }
        return $review;
    }

    public function save(MetadataFieldReview $review): void
    {
        $stateUpdated = $this->database->update(
            $this->tables->metadataFieldStates(),
            [
                "canonical_value_json" => $review->canonicalValue()?->json(),
                "canonical_value_hash" => $review->canonicalValue()?->hash(),
                "confirmation_state" => $review->confirmationState()->value,
                "confirmed_by_user_id" => $review->confirmedBy()?->value(),
                "field_version" => $review->version(),
                "updated_at" => $this->formatDate($review->updatedAt()),
            ],
            [
                "metadata_record_id" => $review->recordId()->value(),
                "field_key" => $review->field()->value,
            ],
            ["%s", "%s", "%s", "%s", "%d", "%s"],
            ["%s", "%s"]
        );
        if ($stateUpdated !== 1) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not update metadata field review.",
                $this->database->last_error
            );
        }

        foreach ($review->proposals() as $proposal) {
            $this->saveProposal($review, $proposal);
            foreach ($proposal->evidence() as $evidence) {
                $this->saveEvidence($review, $proposal, $evidence);
            }
        }
    }

    private function read(
        MetadataRecordId $recordId,
        MetadataField $field,
        bool $forUpdate
    ): ?MetadataFieldReview {
        $states = $this->tables->metadataFieldStates();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT * FROM `{$states}` WHERE metadata_record_id=%s AND field_key=%s"
                . ($forUpdate ? " FOR UPDATE" : ""),
            $recordId->value(),
            $field->value
        ));
        if ($row === null) {
            return null;
        }

        try {
            $proposalRows = $this->database->get_results($this->database->prepare(
                "SELECT * FROM `{$this->tables->metadataFieldValues()}` "
                    . "WHERE metadata_record_id=%s AND field_key=%s "
                    . "ORDER BY first_seen_at,value_hash",
                $recordId->value(),
                $field->value
            ));
            $proposals = [];
            foreach ($proposalRows as $proposalRow) {
                $valueHash = (string) $proposalRow->value_hash;
                $evidenceRows = $this->database->get_results($this->database->prepare(
                    "SELECT * FROM `{$this->tables->metadataFieldEvidence()}` "
                        . "WHERE metadata_record_id=%s AND field_key=%s AND value_hash=%s "
                        . "ORDER BY first_retrieved_at,evidence_id",
                    $recordId->value(),
                    $field->value,
                    $valueHash
                ));
                $evidence = array_map($this->hydrateEvidence(...), $evidenceRows);
                $value = MetadataFieldValue::fromJson((string) $proposalRow->value_json);
                if (!hash_equals($value->hash(), $valueHash)) {
                    throw new \UnexpectedValueException("Stored metadata value hash is invalid.");
                }
                $proposals[] = new MetadataFieldProposal(
                    $value,
                    MetadataFieldProposalState::from((string) $proposalRow->review_state),
                    $this->date((string) $proposalRow->first_seen_at),
                    $this->date((string) $proposalRow->last_seen_at),
                    $evidence,
                    $proposalRow->decided_by_user_id === null
                        ? null
                        : new UserId((string) $proposalRow->decided_by_user_id),
                    $proposalRow->decided_at === null
                        ? null
                        : $this->date((string) $proposalRow->decided_at)
                );
            }

            $canonicalValue = $row->canonical_value_json === null
                ? null
                : MetadataFieldValue::fromJson((string) $row->canonical_value_json);
            if (
                $canonicalValue !== null
                && !hash_equals($canonicalValue->hash(), (string) $row->canonical_value_hash)
            ) {
                throw new \UnexpectedValueException("Stored canonical metadata hash is invalid.");
            }

            return new MetadataFieldReview(
                $recordId,
                $field,
                MetadataFieldConfirmationState::from((string) $row->confirmation_state),
                $canonicalValue,
                $row->confirmed_by_user_id === null
                    ? null
                    : new UserId((string) $row->confirmed_by_user_id),
                (int) $row->field_version,
                $this->date((string) $row->updated_at),
                $proposals
            );
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored metadata field review is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    private function saveProposal(
        MetadataFieldReview $review,
        MetadataFieldProposal $proposal
    ): void {
        $table = $this->tables->metadataFieldValues();
        $decidedBy = $proposal->decidedBy() === null
            ? "NULL"
            : $this->database->prepare("%s", $proposal->decidedBy()->value());
        $decidedAt = $proposal->decidedAt() === null
            ? "NULL"
            : $this->database->prepare(
                "%s",
                $this->formatDate($proposal->decidedAt())
            );
        $sql = $this->database->prepare(
            "INSERT INTO `{$table}` "
                . "(metadata_record_id,field_key,value_hash,value_json,review_state,"
                . "first_seen_at,last_seen_at,decided_by_user_id,decided_at) "
                . "VALUES (%s,%s,%s,%s,%s,%s,%s,{$decidedBy},{$decidedAt}) "
                . "ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),"
                . "review_state=VALUES(review_state),last_seen_at=VALUES(last_seen_at),"
                . "decided_by_user_id=VALUES(decided_by_user_id),decided_at=VALUES(decided_at)",
            $review->recordId()->value(),
            $review->field()->value,
            $proposal->value()->hash(),
            $proposal->value()->json(),
            $proposal->state()->value,
            $this->formatDate($proposal->firstSeenAt()),
            $this->formatDate($proposal->lastSeenAt())
        );
        if ($this->database->query($sql) === false) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist metadata field value.",
                $this->database->last_error
            );
        }
    }

    private function saveEvidence(
        MetadataFieldReview $review,
        MetadataFieldProposal $proposal,
        MetadataFieldEvidence $evidence
    ): void {
        $table = $this->tables->metadataFieldEvidence();
        $evidenceId = $this->evidenceId($review, $proposal, $evidence);
        $sql = $this->database->prepare(
            "INSERT INTO `{$table}` "
                . "(evidence_id,metadata_record_id,field_key,value_hash,provider_key,"
                . "provider_record_id,first_retrieved_at,last_retrieved_at,observation_count,"
                . "match_method,queried_identifier_type,queried_identifier) "
                . "VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s) "
                . "ON DUPLICATE KEY UPDATE first_retrieved_at=VALUES(first_retrieved_at),"
                . "last_retrieved_at=VALUES(last_retrieved_at),"
                . "observation_count=VALUES(observation_count)",
            $evidenceId,
            $review->recordId()->value(),
            $review->field()->value,
            $proposal->value()->hash(),
            $evidence->providerKey(),
            $evidence->providerRecordId(),
            $this->formatDate($evidence->firstRetrievedAt()),
            $this->formatDate($evidence->lastRetrievedAt()),
            $evidence->observationCount(),
            $evidence->matchMethod()->value,
            $evidence->queriedIdentifierType()->value,
            $evidence->queriedIdentifier()
        );
        if ($this->database->query($sql) === false) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist metadata field evidence.",
                $this->database->last_error
            );
        }
    }

    private function hydrateEvidence(object $row): MetadataFieldEvidence
    {
        $evidence = new MetadataFieldEvidence(
            (string) $row->provider_key,
            (string) $row->provider_record_id,
            $this->date((string) $row->first_retrieved_at),
            $this->date((string) $row->last_retrieved_at),
            (int) $row->observation_count,
            MetadataMatchMethod::from((string) $row->match_method),
            IsbnType::from((string) $row->queried_identifier_type),
            (string) $row->queried_identifier
        );
        $expectedId = hash("sha256", implode("\0", [
            (string) $row->metadata_record_id,
            (string) $row->field_key,
            (string) $row->value_hash,
            $evidence->identity(),
        ]));
        if (!hash_equals($expectedId, (string) $row->evidence_id)) {
            throw new \UnexpectedValueException("Stored metadata evidence identity is invalid.");
        }
        return $evidence;
    }

    private function evidenceId(
        MetadataFieldReview $review,
        MetadataFieldProposal $proposal,
        MetadataFieldEvidence $evidence
    ): string {
        return hash("sha256", implode("\0", [
            $review->recordId()->value(),
            $review->field()->value,
            $proposal->value()->hash(),
            $evidence->identity(),
        ]));
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone("UTC"))->format(self::DATE_FORMAT);
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            "!" . self::DATE_FORMAT,
            $value,
            new DateTimeZone("UTC")
        );
        if ($date === false) {
            throw new \UnexpectedValueException("Stored metadata date is invalid.");
        }
        return $date;
    }
}
