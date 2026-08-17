<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class Work
{
    public const MAX_TITLE_LENGTH = 512;

    public function __construct(
        private WorkId $id,
        private string $title
    ) {
        $titleLength = preg_match_all('/./us', $this->title);

        if ($titleLength === false) {
            throw new ValidationException("Work title must be valid UTF-8.");
        }

        if (trim($this->title) === "") {
            throw new ValidationException("Work title must not be empty.");
        }

        if ($titleLength > self::MAX_TITLE_LENGTH) {
            throw new ValidationException(
                "Work title must not exceed "
                . self::MAX_TITLE_LENGTH . " characters."
            );
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
