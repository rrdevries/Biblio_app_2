<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

use Biblio\Core\Library\LibraryId;

final readonly class LibraryGenre
{
    public function __construct(
        private LibraryId $libraryId,
        private LibraryGenreId $id,
        private ClassificationTermName $name,
        private ClassificationNormalizedName $normalizedName,
        private ClassificationTermStatus $status,
        private ?ClassificationSeedKey $seedKey = null
    ) {
    }

    public function libraryId(): LibraryId
    {
        return $this->libraryId;
    }

    public function id(): LibraryGenreId
    {
        return $this->id;
    }

    public function name(): ClassificationTermName
    {
        return $this->name;
    }

    public function normalizedName(): ClassificationNormalizedName
    {
        return $this->normalizedName;
    }

    public function status(): ClassificationTermStatus
    {
        return $this->status;
    }

    public function seedKey(): ?ClassificationSeedKey
    {
        return $this->seedKey;
    }
}
