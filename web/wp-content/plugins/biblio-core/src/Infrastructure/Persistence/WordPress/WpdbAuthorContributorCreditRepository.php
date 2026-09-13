<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Metadata\Author\{
    AuthorContributorCredit,
    AuthorContributorCreditConflict,
    AuthorContributorCreditId,
    AuthorContributorCreditKey,
    AuthorContributorCreditRace,
    AuthorContributorCreditRepository,
    AuthorContributorCreditSourceIdentity,
    AuthorContributorCreditStatus,
    AuthorContributorCreditVersion,
    AuthorCreditEvidence,
    AuthorCreditEvidenceSourceKind,
    AuthorCreditReviewReason
};
use Biblio\Core\Catalog\{AuthorId,ContributorPosition,ContributorRole,WorkId};
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use wpdb;

final readonly class WpdbAuthorContributorCreditRepository implements
    AuthorContributorCreditRepository
{
    public function __construct(
        private wpdb $database,
        private CoreTableNames $tables
    ) {
    }

    public function findByKey(
        AuthorContributorCreditKey $key
    ): ?AuthorContributorCredit {
        $row = $this->database->get_row($this->database->prepare(
            "SELECT credit_id,credit_key,work_id,contributor_role,"
                . "contributor_position,observed_display_name,"
                . "normalized_name_hash,author_id,materialization_status,"
                . "review_reason,created_at,updated_at,credit_version "
                . "FROM `{$this->tables->authorContributorCredits()}` "
                . "WHERE credit_key=%s",
            $key->value()
        ));
        if ($row === null) {
            return null;
        }

        try {
            return $this->hydrateCredit($row);
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Author contributor credit is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    public function create(
        AuthorContributorCredit $credit
    ): AuthorContributorCredit {
        if (
            $credit->version()->value()
            !== AuthorContributorCreditVersion::initial()->value()
        ) {
            throw new \InvalidArgumentException(
                "New Author contributor credit must start at the initial version."
            );
        }
        $existing = $this->findByKey($credit->key());
        if ($existing !== null) {
            if (!$this->sameCreditIdentity($existing, $credit)) {
                throw new AuthorContributorCreditConflict();
            }
            return $existing;
        }

        $previous = $this->database->suppress_errors(true);
        try {
            $inserted = $this->database->insert(
                $this->tables->authorContributorCredits(),
                [
                    "credit_id" => $credit->id()->value(),
                    "credit_key" => $credit->key()->value(),
                    "work_id" => $credit->workId()->value(),
                    "contributor_role" => $credit->role()->value,
                    "contributor_position" => $credit->position()->value(),
                    "observed_display_name" => $credit->observedDisplayName(),
                    "normalized_name_hash" => $credit->key()->normalizedNameHash(),
                    "author_id" => $credit->authorId()?->value(),
                    "materialization_status" => $credit->status()->value,
                    "review_reason" => $credit->reviewReason()?->value,
                    "created_at" => $this->formatDate($credit->createdAt()),
                    "updated_at" => $this->formatDate($credit->updatedAt()),
                    "credit_version" => $credit->version()->value(),
                ],
                [
                    "%s", "%s", "%s", "%s", "%d", "%s", "%s",
                    "%s", "%s", "%s", "%s", "%s", "%d",
                ]
            );
        } finally {
            $this->database->suppress_errors($previous);
        }
        if ($inserted === 1) {
            return $credit;
        }

        $writeError = $this->database->last_error;
        if (WpdbErrorTranslator::conflict($writeError) === null) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist Author contributor credit.",
                $writeError
            );
        }
        if (!str_contains($writeError, "author_credit_key_unique")) {
            throw new AuthorContributorCreditConflict();
        }
        throw new AuthorContributorCreditRace();
    }

    public function observeEvidence(AuthorCreditEvidence $evidence): void
    {
        $table = $this->tables->authorCreditEvidence();
        $previous = $this->database->suppress_errors(true);
        try {
            $inserted = $this->database->insert($table, [
                "credit_id" => $evidence->creditId()->value(),
                "evidence_id" => $evidence->evidenceId(),
                "source_kind" => $evidence->sourceKind()->value,
                "source_identity" => $evidence->sourceIdentity()->value(),
                "provider_key" => $evidence->providerKey(),
                "source_entity_type" => $evidence->sourceEntityType(),
                "source_record_id" => $evidence->sourceRecordId(),
                "strong_provider_author_id" => $evidence->strongProviderAuthorId(),
                "observed_display_name" => $evidence->observedDisplayName(),
                "contributor_role" => $evidence->role()->value,
                "source_position" => $evidence->sourcePosition()->value(),
                "first_observed_at" => $this->formatDate(
                    $evidence->firstObservedAt()
                ),
                "last_observed_at" => $this->formatDate(
                    $evidence->lastObservedAt()
                ),
                "observation_count" => 1,
            ], [
                "%s", "%s", "%s", "%s", "%s", "%s", "%s", "%s",
                "%s", "%s", "%d", "%s", "%s", "%d",
            ]);
        } finally {
            $this->database->suppress_errors($previous);
        }
        if ($inserted === 1) {
            return;
        }
        $writeError = $this->database->last_error;
        if (WpdbErrorTranslator::conflict($writeError) === null) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not persist Author credit evidence.",
                $writeError
            );
        }

        $stored = null;
        foreach ($this->evidenceForCredit($evidence->creditId()) as $candidate) {
            if ($candidate->evidenceId() === $evidence->evidenceId()) {
                $stored = $candidate;
                break;
            }
        }
        if ($stored === null) {
            throw new AuthorContributorCreditRace();
        }
        if (!$this->sameEvidenceIdentity($stored, $evidence)) {
            throw new AuthorContributorCreditConflict();
        }

        $updated = $this->database->query($this->database->prepare(
            "UPDATE `{$table}` SET "
                . "first_observed_at=LEAST(first_observed_at,%s),"
                . "last_observed_at=GREATEST(last_observed_at,%s),"
                . "observation_count=observation_count+1 "
                . "WHERE credit_id=%s AND evidence_id=%s",
            $this->formatDate($evidence->firstObservedAt()),
            $this->formatDate($evidence->lastObservedAt()),
            $evidence->creditId()->value(),
            $evidence->evidenceId()
        ));
        if ($updated !== 1) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not update Author credit evidence observation history.",
                $this->database->last_error
            );
        }
    }

    public function setReviewReasonIfVersionMatches(
        AuthorContributorCreditId $creditId,
        AuthorContributorCreditVersion $expectedVersion,
        AuthorCreditReviewReason $reason,
        DateTimeImmutable $updatedAt
    ): bool {
        $result = $this->database->query($this->database->prepare(
            "UPDATE `{$this->tables->authorContributorCredits()}` SET "
                . "review_reason=%s,updated_at=%s,credit_version=credit_version+1 "
                . "WHERE credit_id=%s AND credit_version=%d "
                . "AND (review_reason IS NULL OR review_reason=%s)",
            $reason->value,
            $this->formatDate($updatedAt),
            $creditId->value(),
            $expectedVersion->value(),
            $reason->value
        ));
        if ($result === false) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not flag Author contributor credit for review.",
                $this->database->last_error
            );
        }
        return $result === 1;
    }

    public function evidenceForCredit(
        AuthorContributorCreditId $creditId
    ): array {
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT credit_id,evidence_id,source_kind,source_identity,"
                . "provider_key,source_entity_type,source_record_id,"
                . "strong_provider_author_id,observed_display_name,"
                . "contributor_role,source_position,first_observed_at,"
                . "last_observed_at,observation_count "
                . "FROM `{$this->tables->authorCreditEvidence()}` "
                . "WHERE credit_id=%s ORDER BY evidence_id",
            $creditId->value()
        ));

        try {
            return array_map(function (object $row): AuthorCreditEvidence {
                $evidence = AuthorCreditEvidence::stored(
                    new AuthorContributorCreditId((string) $row->credit_id),
                    AuthorCreditEvidenceSourceKind::from((string) $row->source_kind),
                    AuthorContributorCreditSourceIdentity::stored(
                        (string) $row->source_identity
                    ),
                    $row->provider_key === null ? null : (string) $row->provider_key,
                    $row->source_entity_type === null
                        ? null : (string) $row->source_entity_type,
                    $row->source_record_id === null
                        ? null : (string) $row->source_record_id,
                    $row->strong_provider_author_id === null
                        ? null : (string) $row->strong_provider_author_id,
                    (string) $row->observed_display_name,
                    ContributorRole::from((string) $row->contributor_role),
                    new ContributorPosition((int) $row->source_position),
                    $this->date((string) $row->first_observed_at),
                    $this->date((string) $row->last_observed_at),
                    (int) $row->observation_count
                );
                if ($evidence->evidenceId() !== (string) $row->evidence_id) {
                    throw new \UnexpectedValueException(
                        "Stored Author credit evidence identity is invalid."
                    );
                }
                return $evidence;
            }, $rows);
        } catch (Throwable $exception) {
            throw new PersistenceException(
                "Stored Author credit evidence is invalid.",
                0,
                $exception,
                FailureReason::PersistenceReadFailed
            );
        }
    }

    private function hydrateCredit(object $row): AuthorContributorCredit
    {
        return new AuthorContributorCredit(
            new AuthorContributorCreditId((string) $row->credit_id),
            AuthorContributorCreditKey::stored(
                (string) $row->credit_key,
                (string) $row->normalized_name_hash
            ),
            new WorkId((string) $row->work_id),
            ContributorRole::from((string) $row->contributor_role),
            new ContributorPosition((int) $row->contributor_position),
            (string) $row->observed_display_name,
            $row->author_id === null
                ? null : new AuthorId((string) $row->author_id),
            AuthorContributorCreditStatus::from(
                (string) $row->materialization_status
            ),
            $row->review_reason === null
                ? null : AuthorCreditReviewReason::from(
                    (string) $row->review_reason
                ),
            $this->date((string) $row->created_at),
            $this->date((string) $row->updated_at),
            new AuthorContributorCreditVersion((int) $row->credit_version)
        );
    }

    private function sameCreditIdentity(
        AuthorContributorCredit $left,
        AuthorContributorCredit $right
    ): bool {
        return $left->key()->value() === $right->key()->value()
            && $left->key()->normalizedNameHash()
                === $right->key()->normalizedNameHash()
            && $left->workId()->value() === $right->workId()->value()
            && $left->role() === $right->role()
            && $left->position()->value() === $right->position()->value()
            && $left->authorId()?->value() === $right->authorId()?->value()
            && $left->status() === $right->status()
            && $left->reviewReason() === $right->reviewReason();
    }

    private function sameEvidenceIdentity(
        AuthorCreditEvidence $left,
        AuthorCreditEvidence $right
    ): bool {
        return $left->creditId()->value() === $right->creditId()->value()
            && $left->evidenceId() === $right->evidenceId()
            && $left->sourceKind() === $right->sourceKind()
            && $left->sourceIdentity()->value()
                === $right->sourceIdentity()->value()
            && $left->providerKey() === $right->providerKey()
            && $left->sourceEntityType() === $right->sourceEntityType()
            && $left->sourceRecordId() === $right->sourceRecordId()
            && $left->strongProviderAuthorId()
                === $right->strongProviderAuthorId()
            && $left->observedDisplayName() === $right->observedDisplayName()
            && $left->role() === $right->role()
            && $left->sourcePosition()->value()
                === $right->sourcePosition()->value();
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone("UTC"))
            ->format("Y-m-d H:i:s.u");
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat(
            "!Y-m-d H:i:s.u",
            $value,
            new DateTimeZone("UTC")
        );
        if (!$date instanceof DateTimeImmutable) {
            throw new \UnexpectedValueException("Stored UTC timestamp is invalid.");
        }
        return $date;
    }
}
