<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Audit\ActivityActorSnapshot;
use Biblio\Core\Audit\ActivityChange;
use Biblio\Core\Audit\ActivityEntityIdentity;
use Biblio\Core\Audit\ActivityEntitySnapshot;
use Biblio\Core\Audit\ActivityEvent;
use Biblio\Core\Audit\ActivityEventAppender;
use Biblio\Core\Audit\ActivityEventId;
use Biblio\Core\Audit\ActivityEventKey;
use Biblio\Core\Audit\ActivityEventSource;
use Biblio\Core\Audit\ActivityLabel;
use Biblio\Core\Audit\ActivityPayload;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbActivityEventAppender;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;
use JsonException;
use ReflectionClass;

final class ActivityEventPersistenceTest extends PersistenceIntegrationTestCase
{
    /** @throws JsonException */
    public function testAppenderPreservesImmutableHistoricalSnapshotsAndJson(): void
    {
        $libraryId = $this->addLibrary("library-a");
        $event = $this->event($libraryId);
        $this->appender()->append($event);

        $row = $this->database->get_row($this->database->prepare(
            "SELECT * FROM `{$this->tableNames->libraryActivityEvents()}` "
            . "WHERE event_id = %s",
            $event->eventId()->value()
        ));

        self::assertNotNull($row);
        self::assertSame("library-a", $row->library_id);
        self::assertSame("2026-08-20 08:15:00.123456", $row->occurred_at);
        self::assertSame("42", $row->actor_user_id);
        self::assertSame("Historische Naam", $row->actor_display_name);
        self::assertSame("core.classification", $row->event_source);
        self::assertSame("library_catalog_context.updated", $row->event_key);
        self::assertSame("LibraryCatalogContext", $row->primary_entity_type);
        self::assertSame("library-a:work-a", $row->primary_entity_id);

        $related = json_decode(
            (string) $row->related_entities_json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $changes = json_decode(
            (string) $row->changes_json,
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        self::assertSame("Work", $related[0]["identity"]["entity_type"]);
        self::assertSame("work-a", $related[0]["identity"]["entity_id"]);
        self::assertSame(
            "Historische werktitel",
            $related[0]["display_label"]
        );
        self::assertSame(
            ["work_id" => "work-a", "confidence" => 1.0],
            $related[0]["attributes"]
        );
        self::assertSame("book_type", $changes[0]["field"]);
        self::assertSame(
            ["id" => "book-a", "label" => "Leesboek"],
            $changes[0]["old_value"]
        );
        self::assertSame(
            ["id" => "book-b", "label" => "Kennisboek"],
            $changes[0]["new_value"]
        );
        self::assertSame(1, (int) $this->database->get_var(
            "SELECT JSON_VALID(related_entities_json) "
            . "AND JSON_VALID(changes_json) FROM "
            . "`{$this->tableNames->libraryActivityEvents()}`"
        ));
    }

    public function testActorSnapshotSurvivesWithoutResolvableUserForeignKey(): void
    {
        $libraryId = $this->addLibrary("library-a");
        $event = $this->event(
            $libraryId,
            new ActivityActorSnapshot(
                null,
                new ActivityLabel("Verwijderde gebruiker")
            ),
            "event-without-user"
        );
        $this->appender()->append($event);

        $row = $this->database->get_row(
            "SELECT actor_user_id, actor_display_name FROM "
            . "`{$this->tableNames->libraryActivityEvents()}`"
        );

        self::assertNotNull($row);
        self::assertNull($row->actor_user_id);
        self::assertSame("Verwijderde gebruiker", $row->actor_display_name);
    }

    public function testPortIsAppendOnlyAndDuplicateEventCannotOverwriteHistory(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(ActivityEventAppender::class))->getMethods()
        );
        self::assertSame(["append"], $methods);

        $libraryId = $this->addLibrary("library-a");
        $event = $this->event($libraryId);
        $this->appender()->append($event);

        try {
            $this->appender()->append($event);
            self::fail("Duplicate Activity Event overwrote history.");
        } catch (PersistenceException) {
            self::assertSame(1, (int) $this->database->get_var(
                "SELECT COUNT(*) FROM "
                . "`{$this->tableNames->libraryActivityEvents()}`"
            ));
            self::assertSame(
                "Historische Naam",
                $this->database->get_var(
                    "SELECT actor_display_name FROM "
                    . "`{$this->tableNames->libraryActivityEvents()}`"
                )
            );
        }
    }

    private function addLibrary(string $value): LibraryId
    {
        $id = new LibraryId($value);
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add(
            Library::privateLibrary($id)
        );

        return $id;
    }

    private function event(
        LibraryId $libraryId,
        ?ActivityActorSnapshot $actor = null,
        string $eventId = "event-a"
    ): ActivityEvent {
        return new ActivityEvent(
            new ActivityEventId($eventId),
            $libraryId,
            new DateTimeImmutable("2026-08-20T10:15:00.123456+02:00"),
            $actor ?? new ActivityActorSnapshot(
                new UserId("42"),
                new ActivityLabel("Historische Naam")
            ),
            new ActivityEventSource("core.classification"),
            new ActivityEventKey("library_catalog_context.updated"),
            new ActivityEntityIdentity(
                "LibraryCatalogContext",
                "library-a:work-a"
            ),
            [
                new ActivityEntitySnapshot(
                    new ActivityEntityIdentity("Work", "work-a"),
                    new ActivityLabel("Historische werktitel"),
                    new ActivityPayload([
                        "work_id" => "work-a",
                        "confidence" => 1.0,
                    ])
                ),
            ],
            [
                new ActivityChange(
                    "book_type",
                    new ActivityPayload([
                        "id" => "book-a",
                        "label" => "Leesboek",
                    ]),
                    new ActivityPayload([
                        "id" => "book-b",
                        "label" => "Kennisboek",
                    ])
                ),
            ]
        );
    }

    private function appender(): WpdbActivityEventAppender
    {
        return new WpdbActivityEventAppender(
            $this->database,
            $this->tableNames
        );
    }
}
