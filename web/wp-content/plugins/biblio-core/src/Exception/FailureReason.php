<?php

declare(strict_types=1);

namespace Biblio\Core\Exception;

enum FailureReason: string
{
    case ValidationFailed = "validation_failed";
    case AuthenticationRequired = "authentication_required";
    case AuthorizationDenied = "authorization_denied";
    case ReadingSourceUnavailable = "reading_source_unavailable";
    case ReadingRoundAlreadyActiveForSource =
        "reading_round_already_active_for_source";
    case ReadingRoundNotAvailable = "reading_round_not_available";
    case ReadingRoundStale = "reading_round_stale";
    case ReadingRoundSourceCorrectionUnavailable =
        "reading_round_source_correction_unavailable";
    case ReadingRoundDeletionNotAllowed =
        "reading_round_deletion_not_allowed";
    case ReadingRoundIdCollisionExhausted =
        "reading_round_id_collision_exhausted";
    case PrivateNoteNotAvailable = "private_note_not_available";
    case PrivateNoteStale = "private_note_stale";
    case PrivateNoteReadingRoundUnavailable =
        "private_note_reading_round_unavailable";
    case PrivateNoteIdCollisionExhausted =
        "private_note_id_collision_exhausted";
    case RatingNotAvailable = "rating_not_available";
    case ReviewNotAvailable = "review_not_available";
    case ContributionDuplicate = "contribution_duplicate";
    case AssessmentStale = "assessment_stale";
    case PublicationNotAvailable = "publication_not_available";
    case PublicationIneligible = "publication_ineligible";
    case PublicationAlreadyActive = "publication_already_active";
    case PublicationStale = "publication_stale";
    case ModerationForbidden = "moderation_forbidden";
    case AssessmentIdCollisionExhausted = "assessment_id_collision_exhausted";
    case NextReadingTargetUnavailable = "next_reading_target_unavailable";
    case NextReadingEntryNotAvailable = "next_reading_entry_not_available";
    case NextReadingTargetDuplicate = "next_reading_target_duplicate";
    case NextReadingListStale = "next_reading_list_stale";
    case NextReadingEntryIdCollisionExhausted =
        "next_reading_entry_id_collision_exhausted";
    case PersonalLibraryAlreadyProvisioned =
        "personal_library_already_provisioned";
    case PersonalLibraryDesignationConflict =
        "personal_library_designation_conflict";
    case CatalogRecordAlreadyExists = "catalog_record_already_exists";
    case CatalogItemNotAvailable = "catalog_item_not_available";
    case ClassificationTermConflict = "classification_term_conflict";
    case LibraryCatalogContextAlreadyExists =
        "library_catalog_context_already_exists";
    case LibraryCatalogContextStale =
        "library_catalog_context_stale";
    case PersistenceFailure = "persistence_failure";
    case PersistenceWriteFailed = "persistence_write_failed";
    case PersistenceReadFailed = "persistence_read_failed";
    case SchemaHealthFailed = "schema_health_failed";
    case SchemaMigrationFailed = "schema_migration_failed";
    case TransactionBeginFailed = "transaction_begin_failed";
    case TransactionCommitFailed = "transaction_commit_failed";
    case TransactionRollbackFailed = "transaction_rollback_failed";
    case NestedTransactionNotSupported =
        "nested_transaction_not_supported";
}
