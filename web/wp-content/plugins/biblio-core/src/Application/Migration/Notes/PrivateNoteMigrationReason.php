<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Notes;

enum PrivateNoteMigrationReason: string
{
    case InvalidTypedPlan = "invalid_typed_note_plan";
    case MissingWorkMapping = "missing_work_mapping";
    case MissingReadingRoundMapping = "missing_reading_round_mapping";
    case WrongMappingTargetType = "wrong_mapping_target_type";
    case OwnerMismatch = "owner_mismatch";
    case WorkRoundMismatch = "work_round_mismatch";
    case InvalidNoteContent = "invalid_note_content";
    case InvalidHistoricalTimestamp = "invalid_historical_timestamp";
    case ConflictingNoteMapping = "conflicting_note_mapping";
    case DivergentReplay = "stale_divergent_replay";
}
