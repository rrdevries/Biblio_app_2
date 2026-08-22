<?php

declare(strict_types=1);

namespace Biblio\Core\Reading;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;

final readonly class ReadingRound
{
    public function __construct(
        private ReadingRoundId $id,
        private UserId $userId,
        private WorkId $workId,
        private ?ReadingSource $source,
        private ?ReadingRoundOutcome $outcome,
        private ReadingRoundProvenance $provenance,
        private ReadingPeriod $period,
        private ?DateTimeImmutable $legacyStartedAt,
        private ?DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $updatedAt,
        private ?DateTimeImmutable $endedAt,
        private ReadingRoundVersion $version
    ) {
        foreach (
            [$this->legacyStartedAt, $this->createdAt, $this->updatedAt, $this->endedAt]
            as $instant
        ) {
            if ($instant !== null) {
                PersistedDateTimeConstraints::assertSupported(
                    $instant,
                    "Reading Round technical or legacy date"
                );
            }
        }

        $this->assertState();
    }

    public static function active(
        ReadingRoundId $id,
        UserId $userId,
        WorkId $workId,
        ReadingSource $source,
        ReadingDate|DateTimeImmutable $startedOn,
        ?DateTimeImmutable $createdAt = null
    ): self {
        if ($startedOn instanceof DateTimeImmutable) {
            $createdAt ??= $startedOn;
            $startedOn = ReadingDate::exact(
                (int) $startedOn->format("Y"),
                (int) $startedOn->format("n"),
                (int) $startedOn->format("j")
            );
        }

        $createdAt ??= new DateTimeImmutable("now");

        if (!$startedOn->isExact()) {
            throw new ValidationException(
                "A new active Reading Round requires an exact start date."
            );
        }

        return new self(
            $id,
            $userId,
            $workId,
            $source,
            null,
            ReadingRoundProvenance::SourceStarted,
            ReadingPeriod::active($startedOn),
            null,
            $createdAt,
            $createdAt,
            null,
            ReadingRoundVersion::initial()
        );
    }

    public static function legacyActive(
        ReadingRoundId $id,
        UserId $userId,
        WorkId $workId,
        ?ReadingSource $source,
        DateTimeImmutable $legacyStartedAt,
        ?DateTimeImmutable $updatedAt = null
    ): self {
        return new self(
            $id,
            $userId,
            $workId,
            $source,
            null,
            ReadingRoundProvenance::LegacySourceStarted,
            new ReadingPeriod(null, null),
            $legacyStartedAt,
            null,
            $updatedAt,
            null,
            ReadingRoundVersion::initial()
        );
    }

    public static function historical(
        ReadingRoundId $id,
        UserId $userId,
        WorkId $workId,
        ReadingPeriod $period,
        DateTimeImmutable $createdAt
    ): self {
        return new self(
            $id,
            $userId,
            $workId,
            null,
            ReadingRoundOutcome::Completed,
            ReadingRoundProvenance::HistoricalManual,
            $period,
            null,
            $createdAt,
            $createdAt,
            $createdAt,
            ReadingRoundVersion::initial()
        );
    }

    public function end(
        ReadingRoundOutcome $outcome,
        ReadingDate $finishedOn,
        DateTimeImmutable $endedAt
    ): self {
        if ($this->lifecycle() !== ReadingRoundLifecycle::Active) {
            throw new ValidationException("Only an active Reading Round can end.");
        }

        if (!$finishedOn->isExact()) {
            throw new ValidationException(
                "Finishing or stopping requires an exact end date."
            );
        }

        return $this->replacement(
            $this->source,
            $outcome,
            ReadingPeriod::ended($this->period->startedOn(), $finishedOn),
            $endedAt,
            $endedAt
        );
    }

    public function correctEnded(
        ReadingRoundOutcome $outcome,
        ReadingPeriod $period,
        DateTimeImmutable $updatedAt
    ): self {
        if ($this->lifecycle() !== ReadingRoundLifecycle::Ended) {
            throw new ValidationException(
                "Only an ended Reading Round can receive content correction."
            );
        }

        return $this->replacement(
            $this->source,
            $outcome,
            $period,
            $updatedAt,
            $this->endedAt
        );
    }

    public function correctSource(
        ?ReadingSource $source,
        DateTimeImmutable $updatedAt
    ): self {
        return $this->replacement(
            $source,
            $this->outcome,
            $this->period,
            $updatedAt,
            $this->endedAt
        );
    }

    public function hasEndedContent(
        ReadingRoundOutcome $outcome,
        ReadingPeriod $period
    ): bool {
        return $this->outcome === $outcome && $this->period->equals($period);
    }

    public function id(): ReadingRoundId { return $this->id; }
    public function userId(): UserId { return $this->userId; }
    public function workId(): WorkId { return $this->workId; }
    public function source(): ?ReadingSource { return $this->source; }
    public function outcome(): ?ReadingRoundOutcome { return $this->outcome; }
    public function provenance(): ReadingRoundProvenance { return $this->provenance; }
    public function period(): ReadingPeriod { return $this->period; }
    public function legacyStartedAt(): ?DateTimeImmutable { return $this->legacyStartedAt; }
    public function createdAt(): ?DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): ?DateTimeImmutable { return $this->updatedAt; }
    public function endedAt(): ?DateTimeImmutable { return $this->endedAt; }
    public function version(): ReadingRoundVersion { return $this->version; }

    public function lifecycle(): ReadingRoundLifecycle
    {
        return $this->outcome === null
            ? ReadingRoundLifecycle::Active
            : ReadingRoundLifecycle::Ended;
    }

    private function replacement(
        ?ReadingSource $source,
        ?ReadingRoundOutcome $outcome,
        ReadingPeriod $period,
        DateTimeImmutable $updatedAt,
        ?DateTimeImmutable $endedAt
    ): self {
        return new self(
            $this->id,
            $this->userId,
            $this->workId,
            $source,
            $outcome,
            $this->provenance,
            $period,
            $this->legacyStartedAt,
            $this->createdAt,
            $updatedAt,
            $endedAt,
            $this->version->next()
        );
    }

    private function assertState(): void
    {
        $ended = $this->outcome !== null;

        if (($this->period->finishedOn() !== null) !== $ended) {
            throw new ValidationException(
                "Reading Round outcome and finish date must agree."
            );
        }

        if (($this->endedAt !== null) !== $ended) {
            throw new ValidationException(
                "Reading Round lifecycle and technical end time must agree."
            );
        }

        if ($this->provenance === ReadingRoundProvenance::LegacySourceStarted) {
            if ($this->legacyStartedAt === null || $this->period->startedOn() !== null) {
                throw new ValidationException(
                    "Legacy Reading Rounds require only a legacy start instant."
                );
            }

            return;
        }

        if ($this->legacyStartedAt !== null) {
            throw new ValidationException(
                "New Reading Rounds cannot contain a legacy start instant."
            );
        }

        if ($this->createdAt === null || $this->updatedAt === null) {
            throw new ValidationException(
                "New Reading Rounds require technical creation and update times."
            );
        }

        if ($this->updatedAt < $this->createdAt) {
            throw new ValidationException(
                "Reading Round update time cannot precede creation time."
            );
        }

        if ($this->provenance === ReadingRoundProvenance::SourceStarted) {
            if ($this->period->startedOn()?->isExact() !== true) {
                throw new ValidationException(
                    "A source-started Reading Round requires an exact start date."
                );
            }
        } elseif (!$ended) {
            throw new ValidationException(
                "A manually historical Reading Round must already be ended."
            );
        }
    }
}
