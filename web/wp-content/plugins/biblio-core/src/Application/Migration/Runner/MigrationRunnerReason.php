<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Runner;

enum MigrationRunnerReason: string
{
    case SourceMissing = "source_missing";
    case SourceUnreadable = "source_unreadable";
    case SourceUnsafe = "source_unsafe";
    case SourceChanged = "source_changed";
    case SourceDuplicate = "source_duplicate";
    case UnsupportedAdapter = "unsupported_source_adapter";
    case UnsupportedVersion = "unsupported_source_version";
    case UnsupportedStructure = "unsupported_source_structure";
    case InvalidTarget = "invalid_target";
    case UnhealthySchema = "unhealthy_schema";
    case DuplicateParticipant = "duplicate_participant";
    case DuplicateMapper = "duplicate_source_mapper";
    case ArtifactWriteFailed = "artifact_write_failed";
}
