<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Assessments\{
    HistoricalRatingMigrationParticipant,
    HistoricalRatingPlan,
    HistoricalWrittenReviewMigrationParticipant,
    HistoricalWrittenReviewPlan
};
use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Migration\Preservation\{
    PreservedSourceEvidenceMigrationParticipant,
    PreservedSourceEvidencePlan,
    PreservedSourceEvidencePrivacy
};
use Biblio\Core\Application\Migration\Runner\{
    DeterministicJson,
    MigrationPlanningTarget,
    MigrationRunnerFailure,
    MigrationRunnerReason,
    MigrationSourceInspection,
    MigrationSourceMappingFinding,
    MigrationSourceMappingResult,
    MigrationSourceRecord,
    TypedMigrationPlan
};
use Biblio\Core\Assessments\{RatingValue,ReviewContent};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use DateTimeImmutable;
use DateTimeZone;

/** Implements only the reviewed CURRENT assessment semantics from docs/128. */
final readonly class CurrentV1AssessmentMapper
{
    public function __construct(
        private CurrentV1ReviewedAssessmentContract $contract =
            new CurrentV1ReviewedAssessmentContract()
    ) {}

    /**
     * @param array<string, MigrationSourceRecord> $books
     * @param array<string, string> $workRepresentatives
     */
    public function map(
        MigrationSourceInspection $inspection,
        MigrationPlanningTarget $target,
        array $books,
        array $workRepresentatives
    ): MigrationSourceMappingResult {
        if (!hash_equals(
            $this->contract->manifestSha256(),
            $inspection->package()->manifestDigest()
        )) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::SourceChanged,
                "The reviewed CURRENT assessment contract does not match the source manifest."
            );
        }

        $ratingCandidates = [];
        $reviewCandidates = [];
        $records = [];
        $findings = [];
        $targetUserId = new UserId($target->userId());

        foreach ($books as $book) {
            $bookId = $book->sourceId();
            $payload = $book->payload();
            if (($payload["id"] ?? null) !== $bookId) {
                continue;
            }

            $rating = $payload["rating"] ?? null;
            if ($rating !== 0) {
                $identity = CurrentV1CatalogSourceIds::rating($bookId);
                if (!is_int($rating) || $rating < 1 || $rating > 5) {
                    $findings[] = $this->finding(
                        "v1.rating",
                        $identity,
                        MigrationDisposition::Quarantined,
                        CurrentV1AssessmentMappingReason::InvalidRating
                    );
                } elseif (!isset($workRepresentatives[$bookId])) {
                    $findings[] = $this->finding(
                        "v1.rating",
                        $identity,
                        MigrationDisposition::Quarantined,
                        CurrentV1AssessmentMappingReason::UnresolvedWorkIdentity
                    );
                } else {
                    $ratingCandidates[] = [
                        "book_id" => $bookId,
                        "representative" => $workRepresentatives[$bookId],
                        "source_id" => $identity,
                        "plan" => new HistoricalRatingPlan(
                            $targetUserId,
                            CurrentV1CatalogSourceIds::work($bookId),
                            RatingValue::fromStars((float) $rating),
                            null
                        ),
                    ];
                }
            }

            $reviews = $payload["reviews"] ?? null;
            if (!is_array($reviews) || !array_is_list($reviews)) {
                $findings[] = $this->finding(
                    "v1.review",
                    "v1.book/{$bookId}/review-slot",
                    MigrationDisposition::Quarantined,
                    CurrentV1AssessmentMappingReason::InvalidReviewStructure
                );
            } elseif (count($reviews) > 1) {
                foreach ($reviews as $offset => $_review) {
                    $findings[] = $this->finding(
                        "v1.review",
                        CurrentV1CatalogSourceIds::review($bookId, $offset + 1),
                        MigrationDisposition::Quarantined,
                        CurrentV1AssessmentMappingReason::InvalidReviewStructure
                    );
                }
            } elseif ($reviews !== []) {
                $identity = CurrentV1CatalogSourceIds::review($bookId, 1);
                $review = $reviews[0];
                $keys = is_array($review) ? array_keys($review) : [];
                sort($keys, SORT_STRING);
                if (!is_array($review) || $keys !== ["date", "text"]) {
                    $findings[] = $this->finding(
                        "v1.review",
                        $identity,
                        MigrationDisposition::Quarantined,
                        CurrentV1AssessmentMappingReason::InvalidReviewStructure
                    );
                } elseif (!isset($workRepresentatives[$bookId])) {
                    $findings[] = $this->finding(
                        "v1.review",
                        $identity,
                        MigrationDisposition::Quarantined,
                        CurrentV1AssessmentMappingReason::UnresolvedWorkIdentity
                    );
                } else {
                    $content = $this->reviewContent($review["text"] ?? null);
                    $assessedAt = $this->timestamp($review["date"] ?? null);
                    if (!$content instanceof ReviewContent) {
                        $findings[] = $this->finding(
                            "v1.review",
                            $identity,
                            MigrationDisposition::Quarantined,
                            CurrentV1AssessmentMappingReason::InvalidReviewContent
                        );
                    } elseif (!$assessedAt instanceof DateTimeImmutable) {
                        $findings[] = $this->finding(
                            "v1.review",
                            $identity,
                            MigrationDisposition::Quarantined,
                            CurrentV1AssessmentMappingReason::InvalidReviewTimestamp
                        );
                    } else {
                        $reviewCandidates[] = [
                            "book_id" => $bookId,
                            "representative" => $workRepresentatives[$bookId],
                            "source_id" => $identity,
                            "plan" => new HistoricalWrittenReviewPlan(
                                $targetUserId,
                                CurrentV1CatalogSourceIds::work($bookId),
                                $content,
                                $assessedAt
                            ),
                        ];
                    }
                }
            }

            $reflection = $payload["reflection"] ?? null;
            if (is_string($reflection) && $reflection !== "") {
                $identity = CurrentV1CatalogSourceIds::reflection($bookId);
                $valid = mb_check_encoding($reflection, "UTF-8")
                    && !str_contains($reflection, "\0");
                $evidenceHash = $valid ? DeterministicJson::hash([
                    "source_slot" => $identity,
                    "body" => $reflection,
                ]) : null;
                $plannedIdentities = [];
                if (is_string($evidenceHash)) {
                    $preservation = MigrationSourceRecord::typed(
                        PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE,
                        $identity,
                        new PreservedSourceEvidencePlan(
                            $identity,
                            "current_v1_reflection",
                            CurrentV1AssessmentMappingReason::ReflectionTargetNotAvailable->value,
                            $inspection->adapter()->adapterId(),
                            $inspection->adapter()->sourceFamily(),
                            $inspection->profile()->sourceVersion(),
                            $inspection->package()->manifestDigest(),
                            $this->contract->identity(),
                            "data/books.json",
                            "books",
                            $bookId,
                            "reflection",
                            $evidenceHash,
                            PreservedSourceEvidencePrivacy::RestrictedSource
                        )
                    );
                    $records[] = $preservation;
                    $plannedIdentities[] = $this->identity($preservation);
                }
                $findings[] = $this->finding(
                    "v1.reflection",
                    $identity,
                    $valid
                        ? MigrationDisposition::PreservedDeferred
                        : MigrationDisposition::Quarantined,
                    $valid
                        ? CurrentV1AssessmentMappingReason::ReflectionTargetNotAvailable
                        : CurrentV1AssessmentMappingReason::InvalidReflection,
                    plannedIdentities: $plannedIdentities,
                    evidenceHash: $evidenceHash
                );
            } elseif ($reflection !== "") {
                $findings[] = $this->finding(
                    "v1.reflection",
                    CurrentV1CatalogSourceIds::reflection($bookId),
                    MigrationDisposition::Quarantined,
                    CurrentV1AssessmentMappingReason::InvalidReflection
                );
            }
        }

        $this->finalizeCandidates(
            $ratingCandidates,
            HistoricalRatingMigrationParticipant::SOURCE_TYPE,
            "v1.rating",
            CurrentV1AssessmentMappingReason::RatingPlanned,
            CurrentV1AssessmentMappingReason::ConvergedRatingConflict,
            $records,
            $findings
        );
        $this->finalizeCandidates(
            $reviewCandidates,
            HistoricalWrittenReviewMigrationParticipant::SOURCE_TYPE,
            "v1.review",
            CurrentV1AssessmentMappingReason::WrittenReviewPlanned,
            CurrentV1AssessmentMappingReason::ConvergedReviewConflict,
            $records,
            $findings
        );
        $findings[] = new MigrationSourceMappingFinding(
            "v1.assessment_auxiliary",
            "mapping_contract:" . $this->contract->identity(),
            MigrationDisposition::Mapped,
            CurrentV1AssessmentMappingReason::MappingContractApplied->value
        );
        return new MigrationSourceMappingResult($records, $findings);
    }

    /**
     * @param list<array{book_id:string,representative:string,source_id:string,plan:TypedMigrationPlan}> $candidates
     * @param list<MigrationSourceRecord> $records
     * @param list<MigrationSourceMappingFinding> $findings
     */
    private function finalizeCandidates(
        array $candidates,
        string $sourceType,
        string $findingSourceType,
        CurrentV1AssessmentMappingReason $plannedReason,
        CurrentV1AssessmentMappingReason $conflictReason,
        array &$records,
        array &$findings
    ): void {
        $groups = [];
        foreach ($candidates as $candidate) {
            $groups[$candidate["representative"]][] = $candidate;
        }
        foreach ($groups as $group) {
            if (count($group) > 1) {
                foreach ($group as $candidate) {
                    $findings[] = $this->finding(
                        $findingSourceType,
                        $candidate["source_id"],
                        MigrationDisposition::Quarantined,
                        $conflictReason
                    );
                }
                continue;
            }
            $candidate = $group[0];
            $record = MigrationSourceRecord::typed(
                $sourceType,
                $candidate["source_id"],
                $candidate["plan"],
                [CatalogWorkMigrationParticipant::SOURCE_TYPE . ":"
                    . CurrentV1CatalogSourceIds::work($candidate["book_id"])]
            );
            $records[] = $record;
            $findings[] = $this->finding(
                $findingSourceType,
                $candidate["source_id"],
                MigrationDisposition::Mapped,
                $plannedReason,
                [$this->identity($record)]
            );
        }
    }

    private function reviewContent(mixed $raw): ?ReviewContent
    {
        if (!is_string($raw)) {
            return null;
        }
        try {
            return ReviewContent::fromString($raw);
        } catch (ValidationException) {
            return null;
        }
    }

    private function timestamp(mixed $raw): ?DateTimeImmutable
    {
        if (
            !is_string($raw)
            || preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}Z$/D',
                $raw
            ) !== 1
        ) {
            return null;
        }
        $instant = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.v\Z',
            $raw,
            new DateTimeZone("UTC")
        );
        $errors = DateTimeImmutable::getLastErrors();
        return $instant instanceof DateTimeImmutable
            && ($errors === false
                || ($errors["warning_count"] === 0 && $errors["error_count"] === 0))
            && $instant->format('Y-m-d\TH:i:s.v\Z') === $raw
                ? $instant
                : null;
    }

    /** @param list<array{source_type:string,source_id:string}> $plannedIdentities */
    private function finding(
        string $sourceType,
        string $sourceId,
        MigrationDisposition $disposition,
        CurrentV1AssessmentMappingReason $reason,
        array $plannedIdentities = [],
        ?string $evidenceHash = null
    ): MigrationSourceMappingFinding {
        return new MigrationSourceMappingFinding(
            $sourceType,
            $sourceId,
            $disposition,
            $reason->value,
            $plannedIdentities,
            evidenceHash: $evidenceHash
        );
    }

    /** @return array{source_type:string,source_id:string} */
    private function identity(MigrationSourceRecord $record): array
    {
        return [
            "source_type" => $record->sourceType(),
            "source_id" => $record->sourceId(),
        ];
    }
}
