<?php

declare(strict_types=1);

namespace Biblio\Core\Notes;

interface PrivateNoteContentPolicy
{
    public function sanitize(string $source): PrivateNoteContent;
}
