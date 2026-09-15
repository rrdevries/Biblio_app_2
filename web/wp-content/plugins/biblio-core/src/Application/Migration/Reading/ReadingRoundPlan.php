<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reading;

use Biblio\Core\Application\Migration\Runner\TypedMigrationPlan;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\IdentifierConstraints;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\{ReadingDate,ReadingPeriod,ReadingRoundOutcome};

final readonly class ReadingRoundPlan implements TypedMigrationPlan
{
    public function __construct(
        private UserId $targetUserId,
        private string $workSourceId,
        private ?ReadingRoundOutcome $outcome,
        private ReadingPeriod $period,
        private ?string $itemSourceId = null
    ) {
        IdentifierConstraints::assertValid(
            $this->workSourceId,
            "Reading Round Work source ID"
        );
        if ($this->itemSourceId !== null) {
            IdentifierConstraints::assertValid(
                $this->itemSourceId,
                "Reading Round Item source ID"
            );
        }

        $ended = $this->outcome !== null;
        if (($this->period->finishedOn() !== null) !== $ended) {
            throw new ValidationException(
                "Reading Round plan outcome and finish date must agree."
            );
        }
        if (
            !$ended
            && (
                $this->itemSourceId === null
                || $this->period->startedOn()?->isExact() !== true
            )
        ) {
            throw new ValidationException(
                "Active Reading Round plans require an explicit Item source and exact start date."
            );
        }
    }

    public function targetUserId(): UserId { return $this->targetUserId; }
    public function workSourceId(): string { return $this->workSourceId; }
    public function outcome(): ?ReadingRoundOutcome { return $this->outcome; }
    public function period(): ReadingPeriod { return $this->period; }
    public function itemSourceId(): ?string { return $this->itemSourceId; }

    public function canonicalPayload(): array
    {
        return [
            "target_kind" => "reading_round",
            "target_user_id" => $this->targetUserId->value(),
            "work_source_id" => $this->workSourceId,
            "lifecycle" => $this->outcome === null ? "active" : "ended",
            "outcome" => $this->outcome?->value,
            "period" => [
                "started_on" => self::datePayload($this->period->startedOn()),
                "finished_on" => self::datePayload($this->period->finishedOn()),
            ],
            "source" => $this->itemSourceId === null ? null : [
                "kind" => "catalog_item",
                "source_id" => $this->itemSourceId,
            ],
            "provenance" => "migration_imported",
        ];
    }

    /** @return array{year:int,month:?int,day:?int}|null */
    private static function datePayload(?ReadingDate $date): ?array
    {
        return $date === null ? null : [
            "year" => $date->yearValue(),
            "month" => $date->monthValue(),
            "day" => $date->dayValue(),
        ];
    }
}
