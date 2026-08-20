<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Audit;

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
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ActivityEventFoundationTest extends TestCase
{
    public function testEventCapturesImmutableLibraryScopedAuditSnapshots(): void
    {
        $event = $this->event();

        self::assertTrue((new ReflectionClass($event))->isReadOnly());
        self::assertSame("event-1", $event->eventId()->value());
        self::assertSame("library-1", $event->libraryId()->value());
        self::assertSame("2026-08-20T10:15:00+00:00", $event->occurredAt()->format(DATE_ATOM));
        self::assertSame("user-1", $event->actor()?->userId()?->value());
        self::assertSame("Historische Naam", $event->actor()?->displayName()->value());
        self::assertSame("core.classification", $event->source()->value());
        self::assertSame("library_catalog_context.updated", $event->eventKey()->value());
        self::assertSame("LibraryCatalogContext", $event->primaryEntity()->entityType());
        self::assertSame("library-1:work-1", $event->primaryEntity()->entityId());
        self::assertSame("Work", $event->relatedEntities()[0]->identity()->entityType());
        self::assertSame("Historische werktitel", $event->relatedEntities()[0]->displayLabel()?->value());
        self::assertSame("book_type", $event->changes()[0]->field());
        self::assertSame(
            ["id" => "book-type-2", "label" => "Kennisboek"],
            $event->changes()[0]->newValue()->values()
        );

        $copy = $event->changes()[0]->newValue()->values();
        $copy["label"] = "Gewijzigd buiten het event";

        self::assertSame(
            "Kennisboek",
            $event->changes()[0]->newValue()->values()["label"]
        );
    }

    public function testActorCanRetainDisplaySnapshotWithoutResolvableUser(): void
    {
        $actor = new ActivityActorSnapshot(
            null,
            new ActivityLabel("Verwijderde gebruiker")
        );

        self::assertNull($actor->userId());
        self::assertSame("Verwijderde gebruiker", $actor->displayName()->value());
    }

    public function testPayloadAcceptsNestedStructuredHistoricalValues(): void
    {
        $payload = new ActivityPayload([
            "terms" => [
                ["id" => "genre-1", "label" => "Fantasy"],
                ["id" => "genre-2", "label" => "Avontuur"],
            ],
            "confirmed" => true,
            "confidence" => 1.0,
            "note" => null,
        ]);

        self::assertSame("Fantasy", $payload->values()["terms"][0]["label"]);
    }

    public function testPayloadRejectsNonJsonCompatibleData(): void
    {
        $this->expectException(ValidationException::class);

        new ActivityPayload(["object" => new \stdClass()]);
    }

    public function testPayloadRejectsInvalidUtf8SnapshotData(): void
    {
        $this->expectException(ValidationException::class);

        new ActivityPayload(["label" => "invalid-\xFF"]);
    }

    public function testEventRejectsDuplicateRelatedIdentities(): void
    {
        $identity = new ActivityEntityIdentity("Work", "work-1");
        $snapshot = new ActivityEntitySnapshot(
            $identity,
            null,
            new ActivityPayload([])
        );

        $this->expectException(ValidationException::class);

        $this->event([$snapshot, $snapshot]);
    }

    public function testEventRejectsDuplicateChangeFields(): void
    {
        $change = new ActivityChange(
            "genres",
            new ActivityPayload(["terms" => []]),
            new ActivityPayload(["terms" => []])
        );

        $this->expectException(ValidationException::class);

        $this->event(null, [$change, $change]);
    }

    public function testEventAppenderDefinesAppendOnlyPort(): void
    {
        $appender = new class () implements ActivityEventAppender {
            /** @var list<ActivityEvent> */
            public array $events = [];

            public function append(ActivityEvent $event): void
            {
                $this->events[] = $event;
            }
        };

        $event = $this->event();
        $appender->append($event);

        self::assertSame([$event], $appender->events);
    }

    /**
     * @param null|list<ActivityEntitySnapshot> $relatedEntities
     * @param null|list<ActivityChange> $changes
     */
    private function event(
        ?array $relatedEntities = null,
        ?array $changes = null
    ): ActivityEvent {
        return new ActivityEvent(
            new ActivityEventId("event-1"),
            new LibraryId("library-1"),
            new DateTimeImmutable("2026-08-20T10:15:00+00:00"),
            new ActivityActorSnapshot(
                new UserId("user-1"),
                new ActivityLabel("Historische Naam")
            ),
            new ActivityEventSource("core.classification"),
            new ActivityEventKey("library_catalog_context.updated"),
            new ActivityEntityIdentity(
                "LibraryCatalogContext",
                "library-1:work-1"
            ),
            $relatedEntities ?? [
                new ActivityEntitySnapshot(
                    new ActivityEntityIdentity("Work", "work-1"),
                    new ActivityLabel("Historische werktitel"),
                    new ActivityPayload(["work_id" => "work-1"])
                ),
            ],
            $changes ?? [
                new ActivityChange(
                    "book_type",
                    new ActivityPayload([
                        "id" => "book-type-1",
                        "label" => "Leesboek",
                    ]),
                    new ActivityPayload([
                        "id" => "book-type-2",
                        "label" => "Kennisboek",
                    ])
                ),
            ]
        );
    }
}
