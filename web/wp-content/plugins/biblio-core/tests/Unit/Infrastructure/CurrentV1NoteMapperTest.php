<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Identity\{
    PersonalMigrationTarget,
    PersonalMigrationTargetReadiness
};
use Biblio\Core\Application\Migration\Notes\{
    PrivateNoteMigrationParticipant,
    PrivateNotePlan
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationPlanningTarget,
    MigrationRunnerFailure,
    MigrationSourceInspection,
    MigrationSourcePackage,
    MigrationSourceProfile,
    MigrationSourceRecord
};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\{
    CurrentV1CatalogMapper,
    CurrentV1CatalogMappingReason,
    CurrentV1CatalogSourceIds,
    CurrentV1NoteMapper,
    CurrentV1NoteMappingReason,
    CurrentV1ReviewedNoteContract,
    CurrentV1SourceAdapter
};
use Biblio\Core\Library\{LibraryId,LibraryName};
use PHPUnit\Framework\TestCase;

final class CurrentV1NoteMapperTest extends TestCase
{
    private const DIGEST =
        "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";

    public function testValidPlaintextMapsToDistinctWorkOnlyPrivateNotePlans(): void
    {
        $firstId = "1700000000000_aaaaaa";
        $secondId = "1700000000001_bbbbbb";
        $text = "A < B & \"quoted\" 'once'";
        $first = $this->note(
            $firstId,
            $text,
            "2024-01-02T03:04:05.123Z",
            "2024-01-02T03:04:05.123Z"
        );
        $second = $this->note(
            $secondId,
            $text,
            "2024-01-02T03:04:05.123Z",
            "2024-01-02T03:04:05.123Z"
        );
        $result = $this->map([
            $this->book("book-a", [$first, $second], readingRounds: [[
                "id" => "plausible-round",
                "startedAt" => "2024-01-02T03:04:05.123Z",
            ]]),
        ]);
        $plans = $this->plans($result->records());

        self::assertSame([$firstId, $secondId], array_keys($plans));
        foreach ($plans as $plan) {
            self::assertSame("target-user", $plan->targetUserId()->value());
            self::assertSame(
                CurrentV1CatalogSourceIds::work("book-a"),
                $plan->workSourceId()
            );
            self::assertNull($plan->readingRoundSourceId());
            self::assertSame(
                "<p>A &lt; B &amp; &quot;quoted&quot; &apos;once&apos;</p>",
                $plan->content()->value()
            );
            self::assertSame(
                "2024-01-02T03:04:05.123000Z",
                $plan->canonicalPayload()["created_at"]
            );
            self::assertSame(
                "2024-01-02T03:04:05.123000Z",
                $plan->canonicalPayload()["updated_at"]
            );
        }
        self::assertSame(2, $this->reasonCount(
            $result->findings(),
            CurrentV1NoteMappingReason::PrivateNotePlanned
        ));
        self::assertSame(1, $this->reasonCount(
            $result->findings(),
            CurrentV1NoteMappingReason::MappingContractApplied
        ));

        $records = array_values(array_filter(
            $result->records(),
            static fn (MigrationSourceRecord $record): bool =>
                $record->sourceType() === PrivateNoteMigrationParticipant::SOURCE_TYPE
        ));
        self::assertSame([
            "catalog_work:" . CurrentV1CatalogSourceIds::work("book-a"),
        ], $records[0]->references());
        $reordered = array_values(array_filter(
            $this->map([
            $this->book("book-a", [$second, $first], readingRounds: [[
                "id" => "plausible-round",
                "startedAt" => "2024-01-02T03:04:05.123Z",
            ]]),
            ])->records(),
            static fn (MigrationSourceRecord $record): bool =>
                $record->sourceType() === PrivateNoteMigrationParticipant::SOURCE_TYPE
        ));
        self::assertSame($records[0]->payloadHash(), $reordered[0]->payloadHash());
        self::assertSame($records[1]->payloadHash(), $reordered[1]->payloadHash());
    }

    public function testInvalidContentTimestampsAndBlockedWorkAreExplicitlyAccounted(): void
    {
        $empty = $this->note(
            "1700000000002_cccccc",
            "   ",
            "2024-01-02T03:04:05.123Z",
            "2024-01-02T03:04:05.123Z"
        );
        $multiline = $this->note(
            "1700000000003_dddddd",
            "line one\nline two",
            "2024-01-02T03:04:05.123Z",
            "2024-01-02T03:04:05.123Z"
        );
        $chronology = $this->note(
            "1700000000004_eeeeee",
            "valid text",
            "2024-01-03T03:04:05.123Z",
            "2024-01-02T03:04:05.123Z"
        );
        $blocked = $this->note(
            "1700000000005_ffffff",
            "valid text",
            "2024-01-02T03:04:05.123Z",
            "2024-01-02T03:04:05.123Z"
        );
        $oversized = $this->note(
            "1700000000008_iiiiii",
            str_repeat("x", 65_535),
            "2024-01-02T03:04:05.123Z",
            "2024-01-02T03:04:05.123Z"
        );
        $invalidIdentity = $this->note(
            "note-invalid",
            "valid text",
            "2024-01-02T03:04:05.123Z",
            "2024-01-02T03:04:05.123Z"
        );
        $malformed = $this->note(
            "1700000000009_jjjjjj",
            "valid text",
            "2024-01-02T03:04:05.123Z",
            "2024-01-02T03:04:05.123Z"
        );
        $malformed["unexpected"] = true;
        $unmatched = $this->note(
            "1700000000010_kkkkkk",
            "valid text",
            "2024-01-02T03:04:05.123Z",
            "2024-01-02T03:04:05.123Z"
        );
        $result = $this->map([
            $this->book("book-content", [
                $empty,
                $multiline,
                $chronology,
                $oversized,
                $invalidIdentity,
                $malformed,
            ]),
            $this->book("book-blocked", [$blocked], "9780306406158"),
        ], extraRecords: [new MigrationSourceRecord(
            CurrentV1SourceAdapter::NOTE,
            $unmatched["id"],
            ["book_id" => "missing-book", "record" => $unmatched],
            [CurrentV1SourceAdapter::BOOK . ":missing-book"]
        )]);

        self::assertSame([], $this->plans($result->records()));
        self::assertSame(1, $this->reasonCount(
            $result->findings(),
            CurrentV1NoteMappingReason::InvalidContent
        ));
        self::assertSame(2, $this->reasonCount(
            $result->findings(),
            CurrentV1NoteMappingReason::UnsupportedContent
        ));
        self::assertSame(1, $this->reasonCount(
            $result->findings(),
            CurrentV1NoteMappingReason::InvalidTimestamp
        ));
        self::assertSame(1, $this->reasonCount(
            $result->findings(),
            CurrentV1NoteMappingReason::UnresolvedWorkIdentity
        ));
        self::assertSame(1, $this->reasonCount(
            $result->findings(),
            CurrentV1NoteMappingReason::InvalidSourceIdentity
        ));
        self::assertSame(1, $this->reasonCount(
            $result->findings(),
            CurrentV1NoteMappingReason::InvalidSourceStructure
        ));
        self::assertSame(1, $this->reasonCount(
            $result->findings(),
            CurrentV1NoteMappingReason::UnmatchedParentBook
        ));
    }

    public function testDistinctNotesSurviveDuplicateIsbnAliasConvergence(): void
    {
        $first = $this->note(
            "1700000000011_llllll",
            "same private text",
            "2024-01-02T03:04:05.123Z",
            "2024-01-02T03:04:05.123Z"
        );
        $second = $this->note(
            "1700000000012_mmmmmm",
            "same private text",
            "2024-01-02T03:04:05.123Z",
            "2024-01-02T03:04:05.123Z"
        );
        $result = $this->map([
            $this->book("book-alias-a", [$first], "9780306406157"),
            $this->book("book-alias-b", [$second], "9780306406157"),
        ]);
        $plans = $this->plans($result->records());

        self::assertSame([$first["id"], $second["id"]], array_keys($plans));
        self::assertSame(
            CurrentV1CatalogSourceIds::work("book-alias-a"),
            $plans[$first["id"]]->workSourceId()
        );
        self::assertSame(
            CurrentV1CatalogSourceIds::work("book-alias-b"),
            $plans[$second["id"]]->workSourceId()
        );
        self::assertNotSame(
            $plans[$first["id"]]->canonicalPayload(),
            $plans[$second["id"]]->canonicalPayload()
        );
        self::assertSame(2, $this->reasonCount(
            $result->findings(),
            CurrentV1NoteMappingReason::PrivateNotePlanned
        ));
        self::assertContains(
            CurrentV1CatalogMappingReason::DuplicateIsbnAlias->value,
            array_map(
                static fn ($finding): string => $finding->reasonCode(),
                $result->findings()
            )
        );
    }

    public function testOnlyStableBookNotesEnterTheMapper(): void
    {
        $note = $this->note(
            "1700000000006_gggggg",
            "ordinary note",
            "2024-01-02T03:04:05.123Z",
            "2024-01-02T03:04:05.123Z"
        );
        $book = $this->book("book-boundary", [$note]);
        $payload = $book->payload();
        $payload["rating"] = 5;
        $payload["reviews"] = [["date" => "2024-01-02", "text" => "review"]];
        $payload["reflection"] = "reflection";
        $result = $this->map([
            new MigrationSourceRecord(CurrentV1SourceAdapter::BOOK, "book-boundary", $payload),
        ], [["id" => "copy-a", "bookId" => "book-boundary", "notes" => "copy note"]]);

        self::assertCount(1, $this->plans($result->records()));
        self::assertSame(1, $this->reasonCount(
            $result->findings(),
            CurrentV1NoteMappingReason::PrivateNotePlanned
        ));
    }

    public function testManifestDriftFailsClosedBeforeMapping(): void
    {
        $note = $this->note(
            "1700000000007_hhhhhh",
            "ordinary note",
            "2024-01-02T03:04:05.123Z",
            "2024-01-02T03:04:05.123Z"
        );

        $this->expectException(MigrationRunnerFailure::class);
        $this->expectExceptionMessage("Note contract does not match");
        $this->map(
            [$this->book("book-a", [$note])],
            contractDigest: str_repeat("b", 64)
        );
    }

    /**
     * @param list<MigrationSourceRecord> $books
     * @param list<array<string,mixed>> $copies
     * @param list<MigrationSourceRecord> $extraRecords
     */
    private function map(
        array $books,
        array $copies = [],
        string $contractDigest = self::DIGEST,
        array $extraRecords = []
    ): \Biblio\Core\Application\Migration\Runner\MigrationSourceMappingResult {
        $records = $books;
        foreach ($books as $book) {
            foreach ($book->payload()["notes"] as $note) {
                $records[] = new MigrationSourceRecord(
                    CurrentV1SourceAdapter::NOTE,
                    $note["id"],
                    ["book_id" => $book->sourceId(), "record" => $note],
                    [CurrentV1SourceAdapter::BOOK . ":" . $book->sourceId()]
                );
            }
        }
        foreach ($copies as $copy) {
            $records[] = new MigrationSourceRecord(
                CurrentV1SourceAdapter::COPY,
                $copy["id"],
                $copy
            );
        }
        array_push($records, ...$extraRecords);
        $inspection = new MigrationSourceInspection(
            new MigrationSourcePackage("/tmp", [], self::DIGEST),
            new CurrentV1SourceAdapter(),
            new MigrationSourceProfile(CurrentV1SourceAdapter::SOURCE_VERSION, []),
            $records,
            []
        );

        return (new CurrentV1CatalogMapper(
            noteMapper: new CurrentV1NoteMapper(
                new CurrentV1ReviewedNoteContract($contractDigest)
            )
        ))->map($inspection, $this->target());
    }

    private function target(): MigrationPlanningTarget
    {
        return new MigrationPlanningTarget(new PersonalMigrationTarget(
            new UserId("target-user"),
            new LibraryId("target-library"),
            LibraryName::personalDefault(),
            new PersonalMigrationTargetReadiness([])
        ));
    }

    /** @param list<array<string,mixed>> $notes */
    private function book(
        string $id,
        array $notes,
        string $isbn = "",
        array $readingRounds = []
    ): MigrationSourceRecord {
        return new MigrationSourceRecord(CurrentV1SourceAdapter::BOOK, $id, [
            "id" => $id,
            "title" => "Synthetic Note Work",
            "isbn" => $isbn,
            "isbn10" => "",
            "isbn13" => "",
            "variantOfBookId" => "",
            "containedWorks" => [],
            "notes" => $notes,
            "readingRounds" => $readingRounds,
        ]);
    }

    /** @return array{id:string,text:string,createdAt:string,updatedAt:string} */
    private function note(
        string $id,
        string $text,
        string $createdAt,
        string $updatedAt
    ): array {
        return [
            "createdAt" => $createdAt,
            "id" => $id,
            "text" => $text,
            "updatedAt" => $updatedAt,
        ];
    }

    /** @return array<string,PrivateNotePlan> */
    private function plans(array $records): array
    {
        $plans = [];
        foreach ($records as $record) {
            if ($record->sourceType() === PrivateNoteMigrationParticipant::SOURCE_TYPE) {
                $plan = $record->typedPlan();
                self::assertInstanceOf(PrivateNotePlan::class, $plan);
                $plans[$record->sourceId()] = $plan;
            }
        }
        ksort($plans, SORT_STRING);
        return $plans;
    }

    private function reasonCount(
        array $findings,
        CurrentV1NoteMappingReason $reason
    ): int {
        $count = 0;
        foreach ($findings as $finding) {
            if ($finding->reasonCode() === $reason->value) {
                $count += $finding->toArray()["occurrence_count"];
            }
        }
        return $count;
    }
}
