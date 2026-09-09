<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration;

use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;

final readonly class MigrationRun
{
    public function __construct(
        private string $id,
        private string $sourceFamily,
        private string $sourceSnapshot,
        private string $sourceFingerprint,
        private ?string $sourceVersion,
        private string $migratorVersion,
        private UserId $targetUserId,
        private LibraryId $targetLibraryId,
        private MigrationMode $mode,
        private MigrationRunStatus $status,
        private string $summaryStatus,
        private DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $startedAt,
        private ?DateTimeImmutable $finishedAt
    ) {
        self::text($this->id, 191, "Migration run ID");
        self::token($this->sourceFamily, "Source family");
        self::text($this->sourceSnapshot, 191, "Source snapshot");
        self::hash($this->sourceFingerprint, "Source fingerprint");
        if ($this->sourceVersion !== null) {
            self::text($this->sourceVersion, 64, "Source version");
        }
        self::token($this->migratorVersion, "Migrator version");
        if (!in_array($this->summaryStatus, ["pending", "reconciled", "failed"], true)) {
            throw new ValidationException("Invalid migration summary status.");
        }
        PersistedDateTimeConstraints::assertSupported($this->createdAt, "Migration run creation time");
        if ($this->startedAt !== null) {
            PersistedDateTimeConstraints::assertSupported($this->startedAt, "Migration run start time");
        }
        if ($this->finishedAt !== null) {
            PersistedDateTimeConstraints::assertSupported($this->finishedAt, "Migration run finish time");
        }
        if ($this->startedAt !== null && $this->startedAt < $this->createdAt) {
            throw new ValidationException("Migration run start precedes creation.");
        }
        if ($this->finishedAt !== null && ($this->startedAt === null || $this->finishedAt < $this->startedAt)) {
            throw new ValidationException("Migration run finish time is invalid.");
        }
        if ($this->status === MigrationRunStatus::Completed && ($this->finishedAt === null || $this->summaryStatus !== "reconciled")) {
            throw new ValidationException("Completed migration run must be reconciled and finished.");
        }
    }

    public static function start(
        string $id,
        string $sourceFamily,
        string $sourceSnapshot,
        string $sourceFingerprint,
        ?string $sourceVersion,
        string $migratorVersion,
        UserId $targetUserId,
        LibraryId $targetLibraryId,
        MigrationMode $mode,
        DateTimeImmutable $at
    ): self {
        return new self(
            $id,
            $sourceFamily,
            $sourceSnapshot,
            $sourceFingerprint,
            $sourceVersion,
            $migratorVersion,
            $targetUserId,
            $targetLibraryId,
            $mode,
            MigrationRunStatus::Running,
            "pending",
            $at,
            $at,
            null
        );
    }

    public function withStatus(MigrationRunStatus $status, DateTimeImmutable $at): self
    {
        $summary = match ($status) {
            MigrationRunStatus::Completed => "reconciled",
            MigrationRunStatus::Failed => "failed",
            default => "pending",
        };

        return new self(
            $this->id,
            $this->sourceFamily,
            $this->sourceSnapshot,
            $this->sourceFingerprint,
            $this->sourceVersion,
            $this->migratorVersion,
            $this->targetUserId,
            $this->targetLibraryId,
            $this->mode,
            $status,
            $summary,
            $this->createdAt,
            $this->startedAt,
            in_array($status, [MigrationRunStatus::Completed, MigrationRunStatus::Failed], true) ? $at : null
        );
    }

    public function id(): string { return $this->id; }
    public function sourceFamily(): string { return $this->sourceFamily; }
    public function sourceSnapshot(): string { return $this->sourceSnapshot; }
    public function sourceFingerprint(): string { return $this->sourceFingerprint; }
    public function sourceVersion(): ?string { return $this->sourceVersion; }
    public function migratorVersion(): string { return $this->migratorVersion; }
    public function targetUserId(): UserId { return $this->targetUserId; }
    public function targetLibraryId(): LibraryId { return $this->targetLibraryId; }
    public function mode(): MigrationMode { return $this->mode; }
    public function status(): MigrationRunStatus { return $this->status; }
    public function summaryStatus(): string { return $this->summaryStatus; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function startedAt(): ?DateTimeImmutable { return $this->startedAt; }
    public function finishedAt(): ?DateTimeImmutable { return $this->finishedAt; }

    private static function text(string $value, int $maxLength, string $label): void
    {
        if ($value === "" || trim($value) === "" || preg_match('//u', $value) !== 1 || mb_strlen($value) > $maxLength) {
            throw new ValidationException("{$label} is invalid.");
        }
    }

    private static function token(string $value, string $label): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/', $value) !== 1) {
            throw new ValidationException("{$label} is invalid.");
        }
    }

    private static function hash(string $value, string $label): void
    {
        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new ValidationException("{$label} is invalid.");
        }
    }
}
