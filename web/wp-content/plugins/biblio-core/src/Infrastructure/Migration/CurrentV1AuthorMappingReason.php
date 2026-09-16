<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\MigrationDisposition;

enum CurrentV1AuthorMappingReason: string
{
    case StableAuthorPlanned = "stable_author_planned";
    case OccurrenceAuthorPlanned = "occurrence_author_planned";
    case ContributorOccurrencePlanned = "contributor_occurrence_planned";
    case AuthorSourceEvidenceRetained = "author_source_evidence_retained";
    case UnsupportedAuthorEntityKind = "unsupported_author_entity_kind";
    case CatalogWorkUnavailable = "catalog_work_unavailable";
    case ContainedWorkAuthorPreserved = "contained_work_author_preserved";
    case InvalidAuthorPlaceholder = "invalid_author_placeholder";
    case CompoundAuthorScalar = "compound_author_scalar";
    case MalformedAuthorScalar = "malformed_author_scalar";
    case InvalidAuthorDisplayName = "invalid_author_display_name";
    case InvalidAuthorReference = "invalid_author_reference";
    case DuplicateAuthorEdgeConvergence = "duplicate_author_edge_convergence";
    case AliasContributorConflict = "alias_contributor_conflict";
    case MappingContractApplied = "author_mapping_contract_applied";

    public function disposition(): MigrationDisposition
    {
        return match ($this) {
            self::StableAuthorPlanned,
            self::OccurrenceAuthorPlanned,
            self::ContributorOccurrencePlanned,
            self::MappingContractApplied => MigrationDisposition::Mapped,
            self::DuplicateAuthorEdgeConvergence => MigrationDisposition::Transformed,
            self::AuthorSourceEvidenceRetained,
            self::UnsupportedAuthorEntityKind,
            self::CatalogWorkUnavailable,
            self::ContainedWorkAuthorPreserved => MigrationDisposition::PreservedDeferred,
            self::InvalidAuthorPlaceholder,
            self::CompoundAuthorScalar,
            self::MalformedAuthorScalar,
            self::InvalidAuthorDisplayName,
            self::InvalidAuthorReference,
            self::AliasContributorConflict => MigrationDisposition::Quarantined,
        };
    }
}
