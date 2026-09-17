<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reading;

use Biblio\Core\Application\Migration\Runner\TypedMigrationPlan;
use Biblio\Core\Identity\IdentifierConstraints;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\PersonalReadingTruthState;

final readonly class ReadingTruthPlan implements TypedMigrationPlan
{
    public function __construct(
        private UserId $targetUserId,
        private string $workSourceId,
        private PersonalReadingTruthState $state
    ) {
        IdentifierConstraints::assertValid(
            $this->workSourceId,
            "Reading Truth Work source ID"
        );
    }

    public function targetUserId(): UserId { return $this->targetUserId; }
    public function workSourceId(): string { return $this->workSourceId; }
    public function state(): PersonalReadingTruthState { return $this->state; }

    public function canonicalPayload(): array
    {
        return [
            "target_kind" => "personal_reading_truth",
            "target_user_id" => $this->targetUserId->value(),
            "work_source_id" => $this->workSourceId,
            "truth_state" => $this->state->value,
        ];
    }
}
