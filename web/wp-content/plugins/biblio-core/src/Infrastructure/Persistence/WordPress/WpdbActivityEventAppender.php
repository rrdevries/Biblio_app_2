<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Audit\ActivityChange;
use Biblio\Core\Audit\ActivityEntitySnapshot;
use Biblio\Core\Audit\ActivityEvent;
use Biblio\Core\Audit\ActivityEventAppender;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use DateTimeZone;
use JsonException;
use wpdb;

final readonly class WpdbActivityEventAppender implements ActivityEventAppender
{
    private const DATABASE_DATE_FORMAT = "Y-m-d H:i:s.u";
    private const JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION;

    public function __construct(
        private wpdb $database,
        private CoreTableNames $tableNames
    ) {
    }

    public function append(ActivityEvent $event): void
    {
        try {
            $relatedEntities = json_encode(
                array_map(
                    self::relatedEntity(...),
                    $event->relatedEntities()
                ),
                self::JSON_FLAGS
            );
            $changes = json_encode(
                array_map(self::change(...), $event->changes()),
                self::JSON_FLAGS
            );
        } catch (JsonException $exception) {
            throw new PersistenceException(
                "Could not serialize Library Activity Event snapshots.",
                previous: $exception
            );
        }

        $actor = $event->actor();
        $previousSuppression = $this->database->suppress_errors(true);

        try {
            $result = $this->database->insert(
                $this->tableNames->libraryActivityEvents(),
                [
                    "event_id" => $event->eventId()->value(),
                    "library_id" => $event->libraryId()->value(),
                    "occurred_at" => $event->occurredAt()
                        ->setTimezone(new DateTimeZone("UTC"))
                        ->format(self::DATABASE_DATE_FORMAT),
                    "actor_user_id" => $actor?->userId()?->value(),
                    "actor_display_name" => $actor?->displayName()->value(),
                    "event_source" => $event->source()->value(),
                    "event_key" => $event->eventKey()->value(),
                    "primary_entity_type" => $event
                        ->primaryEntity()->entityType(),
                    "primary_entity_id" => $event
                        ->primaryEntity()->entityId(),
                    "related_entities_json" => $relatedEntities,
                    "changes_json" => $changes,
                ],
                [
                    "%s",
                    "%s",
                    "%s",
                    "%s",
                    "%s",
                    "%s",
                    "%s",
                    "%s",
                    "%s",
                    "%s",
                    "%s",
                ]
            );
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }

        if ($result !== 1) {
            throw WpdbErrorTranslator::writeFailure(
                "Could not append Library Activity Event.",
                $this->database->last_error
            );
        }
    }

    /** @return array<string, mixed> */
    private static function relatedEntity(
        ActivityEntitySnapshot $snapshot
    ): array {
        return [
            "identity" => [
                "entity_type" => $snapshot->identity()->entityType(),
                "entity_id" => $snapshot->identity()->entityId(),
            ],
            "display_label" => $snapshot->displayLabel()?->value(),
            "attributes" => (object) $snapshot->attributes()->values(),
        ];
    }

    /** @return array<string, mixed> */
    private static function change(ActivityChange $change): array
    {
        return [
            "field" => $change->field(),
            "old_value" => (object) $change->oldValue()->values(),
            "new_value" => (object) $change->newValue()->values(),
        ];
    }
}
