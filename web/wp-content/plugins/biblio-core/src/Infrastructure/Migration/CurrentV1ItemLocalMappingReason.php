<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

enum CurrentV1ItemLocalMappingReason: string
{
    case NonEmptyStateReady = "item_local_non_empty_state_ready";
    case ReviewedAbsence = "item_local_reviewed_absence";
    case ExternalBorrowedCopyPreserved = "external_borrowed_copy_preserved";
    case InvalidAcquisitionDate = "invalid_item_local_acquisition_date";
    case CopyAuxiliaryEvidencePreserved = "copy_auxiliary_evidence_preserved";
    case BookAcquisitionEvidencePreserved = "book_acquisition_evidence_preserved";
    case MappingContractApplied = "item_local_mapping_contract_applied";
}
