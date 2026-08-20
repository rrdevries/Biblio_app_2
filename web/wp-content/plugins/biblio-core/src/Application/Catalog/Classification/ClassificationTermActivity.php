<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Classification;

use Biblio\Core\Audit\ActivityChange;
use Biblio\Core\Audit\ActivityEntityIdentity;
use Biblio\Core\Audit\ActivityEvent;
use Biblio\Core\Audit\ActivityEventFactory;
use Biblio\Core\Audit\ActivityEventKey;
use Biblio\Core\Audit\ActivityPayload;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;

final readonly class ClassificationTermActivity
{
    public function __construct(private ActivityEventFactory $factory)
    {
    }

    public function created(
        UserId $actorId,
        LibraryId $libraryId,
        string $entityType,
        string $entityId,
        string $eventKey,
        string $label
    ): ActivityEvent {
        return $this->event(
            $actorId,
            $libraryId,
            $entityType,
            $entityId,
            $eventKey,
            new ActivityChange(
                "term",
                new ActivityPayload([]),
                new ActivityPayload([
                    "id" => $entityId,
                    "label" => $label,
                    "status" => "active",
                ])
            )
        );
    }

    public function renamed(
        UserId $actorId,
        LibraryId $libraryId,
        string $entityType,
        string $entityId,
        string $eventKey,
        string $oldLabel,
        string $newLabel
    ): ActivityEvent {
        return $this->event(
            $actorId,
            $libraryId,
            $entityType,
            $entityId,
            $eventKey,
            new ActivityChange(
                "name",
                new ActivityPayload([
                    "id" => $entityId,
                    "label" => $oldLabel,
                ]),
                new ActivityPayload([
                    "id" => $entityId,
                    "label" => $newLabel,
                ])
            )
        );
    }

    public function statusChanged(
        UserId $actorId,
        LibraryId $libraryId,
        string $entityType,
        string $entityId,
        string $eventKey,
        string $label,
        string $oldStatus,
        string $newStatus
    ): ActivityEvent {
        return $this->event(
            $actorId,
            $libraryId,
            $entityType,
            $entityId,
            $eventKey,
            new ActivityChange(
                "status",
                new ActivityPayload([
                    "id" => $entityId,
                    "label" => $label,
                    "status" => $oldStatus,
                ]),
                new ActivityPayload([
                    "id" => $entityId,
                    "label" => $label,
                    "status" => $newStatus,
                ])
            )
        );
    }

    private function event(
        UserId $actorId,
        LibraryId $libraryId,
        string $entityType,
        string $entityId,
        string $eventKey,
        ActivityChange $change
    ): ActivityEvent {
        return $this->factory->create(
            $actorId,
            $libraryId,
            new ActivityEventKey($eventKey),
            new ActivityEntityIdentity($entityType, $entityId),
            [],
            [$change]
        );
    }
}
