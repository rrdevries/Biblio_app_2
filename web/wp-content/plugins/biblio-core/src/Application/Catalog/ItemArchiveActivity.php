<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog;

use Biblio\Core\Audit\{ActivityChange,ActivityEntityIdentity,ActivityEvent,ActivityEventFactory,ActivityEventKey,ActivityPayload};
use Biblio\Core\Catalog\{Item,ItemArchiveReason,ItemStatus};
use Biblio\Core\Identity\UserId;

final readonly class ItemArchiveActivity
{
    public function __construct(private ActivityEventFactory $factory) {}

    public function archived(UserId $actorId, Item $item, ItemArchiveReason $reason): ActivityEvent
    {
        return $this->event($actorId, $item, "item.archived", ItemStatus::Active, ItemStatus::Archived, $reason);
    }

    public function restored(UserId $actorId, Item $item): ActivityEvent
    {
        return $this->event($actorId, $item, "item.restored", ItemStatus::Archived, ItemStatus::Active, null);
    }

    private function event(UserId $actorId, Item $item, string $key, ItemStatus $old, ItemStatus $new, ?ItemArchiveReason $reason): ActivityEvent
    {
        $after = ["status" => $new->value, "version" => $item->version()->value()];
        if ($reason !== null) { $after["archive_reason"] = $reason->value; }

        return $this->factory->create(
            $actorId,
            $item->libraryId(),
            new ActivityEventKey($key),
            new ActivityEntityIdentity("Item", $item->id()->value()),
            [],
            [new ActivityChange(
                "lifecycle",
                new ActivityPayload(["status" => $old->value, "version" => $item->version()->value() - 1]),
                new ActivityPayload($after)
            )]
        );
    }
}
