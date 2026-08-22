<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

enum ReadingRoundProvenance: string
{
    case LegacySourceStarted = "legacy_source_started";
    case SourceStarted = "source_started";
    case HistoricalManual = "historical_manual";
}
