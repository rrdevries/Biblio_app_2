<?php

declare(strict_types=1);

namespace Biblio\Core\Notes;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;

final readonly class PrivateNote
{
    public function __construct(
        private PrivateNoteId $id,
        private UserId $userId,
        private WorkId $workId,
        private ?ReadingRoundId $readingRoundId,
        private PrivateNoteContent $content,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private PrivateNoteVersion $version
    ) {
        PersistedDateTimeConstraints::assertSupported($createdAt, "Private Note creation time");
        PersistedDateTimeConstraints::assertSupported($updatedAt, "Private Note update time");

        if ($updatedAt < $createdAt) {
            throw new ValidationException(
                "Private Note update time cannot precede creation time."
            );
        }
    }

    public static function create(
        PrivateNoteId $id,
        UserId $userId,
        WorkId $workId,
        ?ReadingRoundId $readingRoundId,
        PrivateNoteContent $content,
        DateTimeImmutable $createdAt
    ): self {
        return new self(
            $id,
            $userId,
            $workId,
            $readingRoundId,
            $content,
            $createdAt,
            $createdAt,
            PrivateNoteVersion::initial()
        );
    }

    public function replaceContent(
        PrivateNoteContent $content,
        DateTimeImmutable $updatedAt
    ): self {
        return $this->replacement($this->readingRoundId, $content, $updatedAt);
    }

    public function correctReadingRound(
        ?ReadingRoundId $readingRoundId,
        DateTimeImmutable $updatedAt
    ): self {
        return $this->replacement($readingRoundId, $this->content, $updatedAt);
    }

    public function id(): PrivateNoteId { return $this->id; }
    public function userId(): UserId { return $this->userId; }
    public function workId(): WorkId { return $this->workId; }
    public function readingRoundId(): ?ReadingRoundId { return $this->readingRoundId; }
    public function content(): PrivateNoteContent { return $this->content; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function version(): PrivateNoteVersion { return $this->version; }

    public function hasReadingRound(?ReadingRoundId $id): bool
    {
        return $this->readingRoundId === null
            ? $id === null
            : $id !== null && $this->readingRoundId->equals($id);
    }

    private function replacement(
        ?ReadingRoundId $readingRoundId,
        PrivateNoteContent $content,
        DateTimeImmutable $updatedAt
    ): self {
        return new self(
            $this->id,
            $this->userId,
            $this->workId,
            $readingRoundId,
            $content,
            $this->createdAt,
            $updatedAt,
            $this->version->next()
        );
    }
}
