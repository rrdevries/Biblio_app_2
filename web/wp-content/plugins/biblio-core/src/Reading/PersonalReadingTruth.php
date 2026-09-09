<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use DateTimeImmutable;
use DateTimeZone;

final readonly class PersonalReadingTruth
{
    public function __construct(
        private UserId $userId,
        private WorkId $workId,
        private PersonalReadingTruthState $state,
        private PersonalReadingTruthVersion $version,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt
    ) {
        $this->assertUtc($this->createdAt, "created at");
        $this->assertUtc($this->updatedAt, "updated at");

        if ($this->updatedAt < $this->createdAt) {
            throw new ValidationException(
                "Personal Reading Truth update time cannot precede creation."
            );
        }
    }

    public static function record(
        UserId $userId,
        WorkId $workId,
        PersonalReadingTruthState $state,
        DateTimeImmutable $at
    ): self {
        return new self(
            $userId,
            $workId,
            $state,
            new PersonalReadingTruthVersion(1),
            $at,
            $at
        );
    }

    public function replace(
        PersonalReadingTruthState $state,
        DateTimeImmutable $at
    ): self {
        if ($state === $this->state) {
            return $this;
        }

        return new self(
            $this->userId,
            $this->workId,
            $state,
            $this->version->next(),
            $this->createdAt,
            $at
        );
    }

    public function userId(): UserId { return $this->userId; }
    public function workId(): WorkId { return $this->workId; }
    public function state(): PersonalReadingTruthState { return $this->state; }
    public function version(): PersonalReadingTruthVersion { return $this->version; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }

    private function assertUtc(DateTimeImmutable $value, string $field): void
    {
        if ($value->getTimezone()->getName() !== (new DateTimeZone("UTC"))->getName()) {
            throw new ValidationException(
                "Personal Reading Truth {$field} must use UTC."
            );
        }
    }
}
