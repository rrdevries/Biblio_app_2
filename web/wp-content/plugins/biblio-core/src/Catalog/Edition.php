<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

final readonly class Edition
{
    private EditionIsbnMetadata $isbnMetadata;

    public function __construct(
        private EditionId $id,
        private WorkId $workId,
        ?EditionIsbnMetadata $isbnMetadata = null
    ) {
        $this->isbnMetadata = $isbnMetadata
            ?? EditionIsbnMetadata::unknown();
    }

    public function id(): EditionId
    {
        return $this->id;
    }

    public function workId(): WorkId
    {
        return $this->workId;
    }

    public function isbnMetadata(): EditionIsbnMetadata
    {
        return $this->isbnMetadata;
    }
}
