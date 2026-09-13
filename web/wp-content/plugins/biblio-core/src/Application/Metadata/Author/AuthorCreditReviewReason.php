<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Author;

enum AuthorCreditReviewReason: string
{
    case IdentityConflict = "identity_conflict";
    case AmbiguousMatch = "ambiguous_match";
    case StructuralAmbiguity = "structural_ambiguity";
    case PossibleDuplicate = "possible_duplicate";
}
