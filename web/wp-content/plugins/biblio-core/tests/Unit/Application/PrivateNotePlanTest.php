<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Migration\Notes\{
    PrivateNoteMigrationFailure,
    PrivateNoteMigrationReason,
    PrivateNotePlan
};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Notes\StrictPrivateNoteContentPolicy;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class PrivateNotePlanTest extends TestCase
{
    public function testCanonicalPayloadContainsOnlyTypedPrivateNoteMeaning(): void
    {
        $content = (new StrictPrivateNoteContentPolicy())->sanitize(
            "<p>Een <strong>privé</strong> notitie</p>"
        );
        $plan = new PrivateNotePlan(
            new UserId("target-user"),
            "work/source",
            $content,
            new DateTimeImmutable("2020-01-02T03:04:05.123456+01:00"),
            new DateTimeImmutable("2020-02-03T04:05:06.654321+01:00"),
            "round/source"
        );

        self::assertSame([
            "target_kind" => "private_note",
            "target_user_id" => "target-user",
            "work_source_id" => "work/source",
            "reading_round_source_id" => "round/source",
            "content" => "<p>Een <strong>privé</strong> notitie</p>",
            "created_at" => "2020-01-02T02:04:05.123456Z",
            "updated_at" => "2020-02-03T03:05:06.654321Z",
            "version" => 1,
            "visibility" => "private",
        ], $plan->canonicalPayload());
    }

    public function testWorkOnlyPlanKeepsReadingRoundAbsent(): void
    {
        $plan = new PrivateNotePlan(
            new UserId("target-user"),
            "work/source",
            (new StrictPrivateNoteContentPolicy())->sanitize("<p>Los</p>"),
            new DateTimeImmutable("2020-01-01T00:00:00+00:00"),
            new DateTimeImmutable("2020-01-01T00:00:00+00:00")
        );

        self::assertNull($plan->readingRoundSourceId());
        self::assertNull($plan->canonicalPayload()["reading_round_source_id"]);
    }

    public function testUpdateBeforeCreationFailsWithTypedTimestampReason(): void
    {
        try {
            new PrivateNotePlan(
                new UserId("target-user"),
                "work/source",
                (new StrictPrivateNoteContentPolicy())->sanitize("<p>Los</p>"),
                new DateTimeImmutable("2020-01-02T00:00:00+00:00"),
                new DateTimeImmutable("2020-01-01T00:00:00+00:00")
            );
            self::fail("Invalid Private Note timestamps were accepted.");
        } catch (PrivateNoteMigrationFailure $failure) {
            self::assertSame(
                PrivateNoteMigrationReason::InvalidHistoricalTimestamp,
                $failure->reason()
            );
        }
    }
}
