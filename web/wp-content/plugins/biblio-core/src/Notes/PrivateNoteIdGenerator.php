<?php

declare(strict_types=1);

namespace Biblio\Core\Notes;

interface PrivateNoteIdGenerator
{
    public function next(): PrivateNoteId;
}
