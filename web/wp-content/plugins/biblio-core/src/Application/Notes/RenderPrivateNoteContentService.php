<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Notes;

use Biblio\Core\Notes\PrivateNote;
use Biblio\Core\Notes\PrivateNoteContentPolicy;

final readonly class RenderPrivateNoteContentService
{
    public function __construct(private PrivateNoteContentPolicy $contentPolicy) {}

    public function render(PrivateNote $note): string
    {
        return $this->contentPolicy->sanitize($note->content()->value())->value();
    }
}
