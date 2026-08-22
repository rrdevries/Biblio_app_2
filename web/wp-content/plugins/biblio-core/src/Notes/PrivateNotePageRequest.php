<?php

declare(strict_types=1);

namespace Biblio\Core\Notes;

use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;

final readonly class PrivateNotePageRequest
{
    public function __construct(
        private int $limit = 50,
        private ?DateTimeImmutable $beforeUpdatedAt = null,
        private ?PrivateNoteId $beforeId = null
    ) {
        if ($limit < 1 || $limit > 100) {
            throw new ValidationException("Private Note page limit must be 1-100.");
        }

        if (($beforeUpdatedAt === null) !== ($beforeId === null)) {
            throw new ValidationException(
                "Private Note cursor requires both update time and Note ID."
            );
        }

        if ($beforeUpdatedAt !== null) {
            PersistedDateTimeConstraints::assertSupported(
                $beforeUpdatedAt,
                "Private Note cursor time"
            );
        }
    }

    public function limit(): int { return $this->limit; }
    public function beforeUpdatedAt(): ?DateTimeImmutable { return $this->beforeUpdatedAt; }
    public function beforeId(): ?PrivateNoteId { return $this->beforeId; }
}
