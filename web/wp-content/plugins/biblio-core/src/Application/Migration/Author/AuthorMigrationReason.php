<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Author;

enum AuthorMigrationReason: string
{
    case InvalidTypedPlan = "invalid_typed_author_plan";
    case MissingTargetReference = "missing_required_target_reference";
    case UnsafeAuthorMapping = "unsafe_author_mapping";
    case ContributorIdentityConflict = "author_contributor_identity_conflict";
    case ContributorPositionConflict = "author_contributor_position_conflict";
    case DivergentReplay = "stale_divergent_replay";
}
