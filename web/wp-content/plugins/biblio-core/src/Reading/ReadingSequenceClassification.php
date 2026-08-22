<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

enum ReadingSequenceClassification: string
{
    case FirstRead = "first_read";
    case Reread = "reread";
    case ChronologyIndeterminate = "chronology_indeterminate";
}
