<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

enum CurrentV1ReadingMappingReason: string
{
    case MappingContractApplied = "reading_mapping_contract_applied";
    case StableReadingRoundPlanned = "stable_reading_round_planned";
    case RegistrationReadingRoundPlanned = "registration_reading_round_planned";
    case PersonalReadingTruthPlanned = "personal_reading_truth_planned";
    case DerivedBookReadingSummary = "derived_book_reading_summary";
    case DerivedReadingHistoryEvidence = "derived_read_history_evidence";
    case DuplicateReadingTruthEvidence = "duplicate_reading_truth_evidence";
    case ActiveRoundMissingConcreteSource = "active_round_missing_concrete_source";
    case HistoricalPausedAuditEvidence = "historical_paused_audit_evidence";
    case AmbiguousDuplicateOrReread = "ambiguous_duplicate_or_reread";
    case ConflictingBookReadStatus = "conflicting_book_read_status";
    case UnresolvedWorkIdentity = "unresolved_work_identity";
    case ReadingTruthConflict = "reading_truth_conflict";
}
