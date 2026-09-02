<?php

declare(strict_types=1);

namespace Biblio\Core\NextReading;

interface NextReadingUndoTokenGenerator
{
    public function next(): NextReadingUndoToken;
}
