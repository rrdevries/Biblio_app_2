<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

enum FinalSourceApprovalState: string
{
    case MechanicallyCompatible = "mechanically_compatible";
    case ReviewRequired = "review_required";
    case ApprovedForRehearsal = "approved_for_rehearsal";
}
