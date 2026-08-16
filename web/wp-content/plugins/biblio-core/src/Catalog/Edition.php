<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

final readonly class Edition
{
    public function __construct(
        private EditionId $id,
        private WorkId $workId
    ) {
    }

    public function id(): EditionId
    {
        return $this->id;
    }

    public function workId(): WorkId
    {
        return $this->workId;
    }
}
