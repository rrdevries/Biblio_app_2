<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class Work
{
    public function __construct(
        private WorkId $id,
        private string $title
    ) {
        if (trim($this->title) === "") {
            throw new ValidationException("Work title must not be empty.");
        }
    }

    public function id(): WorkId
    {
        return $this->id;
    }

    public function title(): string
    {
        return $this->title;
    }
}
