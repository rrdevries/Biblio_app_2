<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class Edition
{
    public const MAX_TITLE_LENGTH = 512;

    private EditionIsbnMetadata $isbnMetadata;

    public function __construct(
        private EditionId $id,
        private WorkId $workId,
        private string $title,
        ?EditionIsbnMetadata $isbnMetadata = null
    ) {
        $titleLength = preg_match_all('/./us', $this->title);

        if ($titleLength === false) {
            throw new ValidationException("Edition title must be valid UTF-8.");
        }

        if (trim($this->title) === "") {
            throw new ValidationException("Edition title must not be empty.");
        }

        if ($titleLength > self::MAX_TITLE_LENGTH) {
            throw new ValidationException(
                "Edition title must not exceed "
                . self::MAX_TITLE_LENGTH . " characters."
            );
        }

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

    public function title(): string
    {
        return $this->title;
    }

    public function isbnMetadata(): EditionIsbnMetadata
    {
        return $this->isbnMetadata;
    }
}
