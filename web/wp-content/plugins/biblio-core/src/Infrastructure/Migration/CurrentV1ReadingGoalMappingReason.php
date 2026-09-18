<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

enum CurrentV1ReadingGoalMappingReason: string
{
    case MappingContractApplied = "reading_goal_mapping_contract_applied";
    case NotCarriedForward = "reading_goal_not_carried_forward_v2";
    case InvalidSourceIdentity = "invalid_reading_goal_source_identity";
    case InvalidSourceStructure = "invalid_reading_goal_source_structure";
}
