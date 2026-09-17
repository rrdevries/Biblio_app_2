<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

enum CurrentV1NoteMappingReason: string
{
    case MappingContractApplied = "note_mapping_contract_applied";
    case PrivateNotePlanned = "private_note_planned";
    case InvalidSourceIdentity = "invalid_note_source_identity";
    case InvalidSourceStructure = "invalid_note_source_structure";
    case UnmatchedParentBook = "unmatched_note_parent_book";
    case UnresolvedWorkIdentity = "unresolved_note_work_identity";
    case InvalidContent = "invalid_note_content";
    case UnsupportedContent = "unsupported_note_content";
    case InvalidTimestamp = "invalid_note_timestamp";
}
