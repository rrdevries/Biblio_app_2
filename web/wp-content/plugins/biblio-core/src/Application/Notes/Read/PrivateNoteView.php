<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Notes\Read;

use Biblio\Core\Application\Notes\RenderPrivateNoteContentService;
use Biblio\Core\Notes\PrivateNote;
use Biblio\Core\Notes\PrivateNoteId;
use Biblio\Core\Notes\PrivateNoteVersion;

final readonly class PrivateNoteView
{
    private function __construct(
        private PrivateNoteId $id,
        private string $contentHtml,
        private PrivateNoteVersion $version
    ) {
    }

    public static function fromPrivateNote(
        PrivateNote $note,
        RenderPrivateNoteContentService $rendering
    ): self {
        return new self(
            $note->id(),
            $rendering->render($note),
            $note->version()
        );
    }

    public function id(): PrivateNoteId
    {
        return $this->id;
    }

    public function contentHtml(): string
    {
        return $this->contentHtml;
    }

    public function version(): PrivateNoteVersion
    {
        return $this->version;
    }
}
