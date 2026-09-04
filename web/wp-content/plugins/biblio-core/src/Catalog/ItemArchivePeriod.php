<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;

final readonly class ItemArchivePeriod
{
    public function __construct(
        private LibraryId $libraryId,
        private ItemId $itemId,
        private ItemVersion $archiveVersion,
        private ItemArchiveReason $reason,
        private DateTimeImmutable $archivedAt,
        private ?ItemVersion $restoreVersion = null,
        private ?DateTimeImmutable $restoredAt = null
    ) {
        PersistedDateTimeConstraints::assertSupported($this->archivedAt, "Item archive timestamp");
        if ($this->archiveVersion->value() < 2) {
            throw new ValidationException("Item archive version must be at least 2.");
        }
        if (($this->restoreVersion === null) !== ($this->restoredAt === null)) {
            throw new ValidationException("Item archive restore version and timestamp must occur together.");
        }
        if ($this->restoreVersion !== null) {
            PersistedDateTimeConstraints::assertSupported($this->restoredAt, "Item restore timestamp");
            if ($this->restoreVersion->value() <= $this->archiveVersion->value()
                || $this->restoredAt < $this->archivedAt) {
                throw new ValidationException("Item restore must follow its archive transition.");
            }
        }
    }

    public function libraryId(): LibraryId { return $this->libraryId; }
    public function itemId(): ItemId { return $this->itemId; }
    public function archiveVersion(): ItemVersion { return $this->archiveVersion; }
    public function reason(): ItemArchiveReason { return $this->reason; }
    public function archivedAt(): DateTimeImmutable { return $this->archivedAt; }
    public function restoreVersion(): ?ItemVersion { return $this->restoreVersion; }
    public function restoredAt(): ?DateTimeImmutable { return $this->restoredAt; }
    public function isOpen(): bool { return $this->restoredAt === null; }
}
