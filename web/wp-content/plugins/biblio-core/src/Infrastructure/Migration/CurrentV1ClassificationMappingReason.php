<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

enum CurrentV1ClassificationMappingReason: string
{
    case BookTypeLeesboekMapped = "book_type_leesboek_mapped";
    case BookTypeKennisboekMapped = "book_type_kennisboek_mapped";
    case BookTypeKookboekMapped = "book_type_kookboek_mapped";
    case BookTypeStripboekMapped = "book_type_stripboek_mapped";
    case BookTypeStudieboekMapped = "book_type_studieboek_mapped";
    case BookTypeJeugdboekReviewedMapping = "book_type_jeugdboek_reviewed_mapping";
    case BookTypeKinderboekReviewedMapping = "book_type_kinderboek_reviewed_mapping";
    case GenreFantasyMapped = "genre_fantasy_mapped";
    case GenreSciencefictionMapped = "genre_sciencefiction_mapped";
    case GenreThrillerMapped = "genre_thriller_mapped";
    case CategoryAssignmentPreserved = "category_assignment_preserved";
    case GenreAssignmentPreserved = "genre_assignment_preserved";
    case ClassificationDefinitionExact = "classification_definition_exact_target";
    case ClassificationDefinitionReviewed = "classification_definition_reviewed_mapping";
    case ClassificationDefinitionPreserved = "classification_definition_preserved";
    case TaxonomyReviewQueuePreserved = "taxonomy_review_queue_preserved";
    case TaxonomyAliasRulesPreserved = "taxonomy_alias_rules_preserved";
    case MappingContractApplied = "classification_mapping_contract_applied";
    case SourceReviewBlocked = "classification_source_review_blocked";
    case UnknownBookTypeBlocked = "classification_unknown_book_type_blocked";
    case ConvergedConflict = "converged_classification_conflict";
    case ConvergedConflictMember = "converged_classification_conflict_member";
    case ClassificationReady = "classification_ready";
}
