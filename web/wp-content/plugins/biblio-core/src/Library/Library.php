<?php

declare(strict_types=1);

namespace Biblio\Core\Library;

final readonly class Library
{
    public function __construct(
        private LibraryId $id,
        private LibraryName $name,
        private LibraryType $type,
        private LibraryStatus $status
    ) {
    }

    public static function privateLibrary(
        LibraryId $id,
        ?LibraryName $name = null
    ): self
    {
        return new self(
            $id,
            $name ?? LibraryName::personalDefault(),
            LibraryType::PrivateLibrary,
            LibraryStatus::Active
        );
    }

    public static function personalPrivateLibrary(LibraryId $id): self
    {
        return self::privateLibrary($id, LibraryName::personalDefault());
    }

    public function id(): LibraryId
    {
        return $this->id;
    }

    public function name(): LibraryName
    {
        return $this->name;
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
