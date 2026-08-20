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
    case PersonalLibraryAlreadyProvisioned =
        "personal_library_already_provisioned";
    case PersonalLibraryDesignationConflict =
        "personal_library_designation_conflict";
    case CatalogRecordAlreadyExists = "catalog_record_already_exists";
    case ClassificationTermConflict = "classification_term_conflict";
    case LibraryCatalogContextAlreadyExists =
        "library_catalog_context_already_exists";
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
