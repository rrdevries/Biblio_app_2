<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Notes;

use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\Reading\ReadingRoundMigrationParticipant;
use Biblio\Core\Application\Migration\{
    MigrationDisposition,
    MigrationRecordOutcome,
    SourceObservation
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationParticipant,
    MigrationPlanningTarget,
    MigrationSourceRecord,
    PlannedMigrationRecord
};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Notes\PrivateNoteContentPolicy;

final readonly class PrivateNoteMigrationParticipant implements MigrationParticipant
{
    public const SOURCE_TYPE = "private_note";

    public function __construct(
        private PrivateNoteMigrationWriter $writer,
        private PrivateNoteContentPolicy $contentPolicy
    ) {
    }

    public function sourceType(): string { return self::SOURCE_TYPE; }

    public function plan(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target
    ): PlannedMigrationRecord {
        $typed = PrivateNoteMigrationPlanGuard::require($record);
        $this->assertTarget($typed, $target);
        $this->assertContent($typed);

        $dependencies = [[
            "source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE,
            "source_id" => $typed->workSourceId(),
        ]];
        if ($typed->readingRoundSourceId() !== null) {
            $dependencies[] = [
                "source_type" => ReadingRoundMigrationParticipant::SOURCE_TYPE,
                "source_id" => $typed->readingRoundSourceId(),
            ];
        }

        return new PlannedMigrationRecord(
            MigrationDisposition::Mapped,
            [["operation" => "create_or_reuse_private_note"]],
            dependencies: $dependencies,
            typedPlan: $typed
        );
    }

    public function apply(
        MigrationSourceRecord $record,
        SourceObservation $observation,
        PlannedMigrationRecord $plan,
        MigrationPlanningTarget $target
    ): MigrationRecordOutcome {
        $typed = PrivateNoteMigrationPlanGuard::require(
            $record,
            $plan,
            $observation
        );
        $this->assertTarget($typed, $target);
        $this->assertContent($typed);

        return $this->writer->apply(
            $observation,
            $typed,
            new LibraryId($target->libraryId())
        );
    }

    private function assertTarget(
        PrivateNotePlan $plan,
        MigrationPlanningTarget $target
    ): void {
        if ($plan->targetUserId()->value() !== $target->userId()) {
            throw new PrivateNoteMigrationFailure(
                PrivateNoteMigrationReason::OwnerMismatch,
                "Private Note plan targets another user."
            );
        }
    }

    private function assertContent(PrivateNotePlan $plan): void
    {
        try {
            $validated = $this->contentPolicy->sanitize(
                $plan->content()->value()
            );
        } catch (ValidationException $failure) {
            throw new PrivateNoteMigrationFailure(
                PrivateNoteMigrationReason::InvalidNoteContent,
                "Private Note plan has invalid approved content.",
                $failure
            );
        }

        if (!$validated->equals($plan->content())) {
            throw new PrivateNoteMigrationFailure(
                PrivateNoteMigrationReason::InvalidNoteContent,
                "Private Note plan content is not in canonical storage form."
            );
        }
    }
}
