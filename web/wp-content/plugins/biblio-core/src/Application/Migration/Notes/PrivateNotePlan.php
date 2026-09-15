<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Notes;

use Biblio\Core\Application\Migration\Runner\TypedMigrationPlan;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\IdentifierConstraints;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Notes\PrivateNoteContent;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;
use DateTimeZone;

final readonly class PrivateNotePlan implements TypedMigrationPlan
{
    public function __construct(
        private UserId $targetUserId,
        private string $workSourceId,
        private PrivateNoteContent $content,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private ?string $readingRoundSourceId = null
    ) {
        IdentifierConstraints::assertValid(
            $this->workSourceId,
            "Private Note Work source ID"
        );
        if ($this->readingRoundSourceId !== null) {
            IdentifierConstraints::assertValid(
                $this->readingRoundSourceId,
                "Private Note Reading Round source ID"
            );
        }

        try {
            PersistedDateTimeConstraints::assertSupported(
                $this->createdAt,
                "Private Note creation time"
            );
            PersistedDateTimeConstraints::assertSupported(
                $this->updatedAt,
                "Private Note update time"
            );
            if ($this->updatedAt < $this->createdAt) {
                throw new ValidationException(
                    "Private Note update time cannot precede creation time."
                );
            }
        } catch (ValidationException $failure) {
            throw new PrivateNoteMigrationFailure(
                PrivateNoteMigrationReason::InvalidHistoricalTimestamp,
                "Private Note plan has invalid approved timestamps.",
                $failure
            );
        }
    }

    public function targetUserId(): UserId { return $this->targetUserId; }
    public function workSourceId(): string { return $this->workSourceId; }
    public function content(): PrivateNoteContent { return $this->content; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function readingRoundSourceId(): ?string
    {
        return $this->readingRoundSourceId;
    }

    public function canonicalPayload(): array
    {
        return [
            "target_kind" => "private_note",
            "target_user_id" => $this->targetUserId->value(),
            "work_source_id" => $this->workSourceId,
            "reading_round_source_id" => $this->readingRoundSourceId,
            "content" => $this->content->value(),
            "created_at" => self::instant($this->createdAt),
            "updated_at" => self::instant($this->updatedAt),
            "version" => 1,
            "visibility" => "private",
        ];
    }

    private static function instant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone("UTC"))
            ->format("Y-m-d\\TH:i:s.u\\Z");
    }
}
