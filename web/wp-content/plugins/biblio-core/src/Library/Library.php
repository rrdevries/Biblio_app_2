<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

final readonly class Library
{
    public function __construct(
        private LibraryId $id,
        private LibraryType $type,
        private LibraryStatus $status
    ) {
    }

    public static function privateLibrary(LibraryId $id): self
    {
        return new self(
            $id,
            LibraryType::PrivateLibrary,
            LibraryStatus::Active
        );
    }

    public function id(): LibraryId
    {
        return $this->id;
    }

    public function type(): LibraryType
    {
        return $this->type;
    }

    public function status(): LibraryStatus
    {
        return $this->status;
    }
}
