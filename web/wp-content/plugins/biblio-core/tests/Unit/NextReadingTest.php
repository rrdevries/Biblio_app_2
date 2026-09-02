<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\{ItemId,WorkId};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\NextReading\{NextReadingEntry,NextReadingEntryId,NextReadingList,NextReadingListVersion,NextReadingPosition,NextReadingUndoToken,NextReadingUndoUnavailable,PreferredReadingSource};
use Biblio\Core\Reading\ReadingSource;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NextReadingTest extends TestCase
{
    public function testPreferredSourcesKeepSnapshotsWhenTheirLiveReferenceIsGone(): void
    {
        $item = PreferredReadingSource::libraryItem(new ItemId("item-1"), new LibraryId("library-1"), false);
        $loan = PreferredReadingSource::externalLoan(new ExternalLoanId("loan-1"), false);

        self::assertSame("item-1", $item->itemIdSnapshot()?->value());
        self::assertSame("library-1", $item->libraryIdSnapshot()?->value());
        self::assertNull($item->liveItemId());
        self::assertSame("loan-1", $loan->externalLoanIdSnapshot()?->value());
        self::assertNull($loan->liveExternalLoanId());
    }

    public function testEntryIdUsesThePersistentIdentifierBoundary(): void
    {
        self::assertSame(str_repeat("é", 191), (new NextReadingEntryId(str_repeat("é", 191)))->value());
        foreach (["", str_repeat("x", 192), "bad\xC3\x28"] as $invalid) {
            try {
                new NextReadingEntryId($invalid);
                self::fail("Invalid Next Reading Entry ID was accepted.");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDuplicatesAreAllowedAndOrderingUsesEntryIdentity(): void
    {
        $owner = new UserId("user-1");
        $list = NextReadingList::empty($owner);
        $first = $this->entry("entry-1", $owner, "work-1", null, 1);
        $second = $this->entry("entry-2", $owner, "work-1", null, 2);
        $list = $list->append($first)->append($second);

        self::assertSame(3, $list->version()->value());
        self::assertSame(["entry-1", "entry-2"], array_map(fn ($entry) => $entry->id()->value(), $list->entries()));
        self::assertSame($list, $list->reordered([$first->id(), $second->id()]));

        $reordered = $list->reordered([$second->id(), $first->id()]);
        self::assertSame(4, $reordered->version()->value());
        self::assertSame(["entry-2", "entry-1"], array_map(fn ($entry) => $entry->id()->value(), $reordered->entries()));

        $remaining = $reordered->without($second->id());
        self::assertSame(1, $remaining->entries()[0]->position()->value());
        self::assertSame(5, $remaining->version()->value());
    }

    public function testAutomaticMatchPrefersExactLiveSourceThenFirstGeneralWorkEntry(): void
    {
        $owner = new UserId("user-1");
        $work = new WorkId("work-1");
        $general = $this->entry("general", $owner, "work-1", null, 1);
        $unavailable = $this->entry("unavailable", $owner, "work-1", PreferredReadingSource::libraryItem(new ItemId("item-1"), new LibraryId("library-1"), false), 2);
        $exact = $this->entry("exact", $owner, "work-1", PreferredReadingSource::libraryItem(new ItemId("item-1"), new LibraryId("library-1")), 3);
        $list = new NextReadingList($owner, NextReadingListVersion::initial(), [$general, $unavailable, $exact]);

        self::assertSame("exact", $list->matchingEntryId($work, ReadingSource::libraryItem(new ItemId("item-1")))?->value());
        self::assertSame("general", $list->matchingEntryId($work, ReadingSource::externalLoan(new ExternalLoanId("loan-1")))?->value());
    }

    public function testAutomaticMatchUsesLowestExactExternalLoanAndNeverMatchesSnapshotsOrAnotherWork(): void
    {
        $owner = new UserId("user-1");
        $work = new WorkId("work-1");
        $snapshotOnly = $this->entry("snapshot", $owner, "work-1", PreferredReadingSource::externalLoan(new ExternalLoanId("loan-1"), false), 1);
        $firstExact = $this->entry("exact-first", $owner, "work-1", PreferredReadingSource::externalLoan(new ExternalLoanId("loan-1")), 2);
        $secondExact = $this->entry("exact-second", $owner, "work-1", PreferredReadingSource::externalLoan(new ExternalLoanId("loan-1")), 3);
        $general = $this->entry("general", $owner, "work-1", null, 4);
        $list = new NextReadingList($owner, NextReadingListVersion::initial(), [$snapshotOnly, $firstExact, $secondExact, $general]);

        self::assertSame("exact-first", $list->matchingEntryId($work, ReadingSource::externalLoan(new ExternalLoanId("loan-1")))?->value());
        self::assertSame("general", $list->matchingEntryId($work, ReadingSource::externalLoan(new ExternalLoanId("loan-2")))?->value());
        self::assertNull($list->matchingEntryId(new WorkId("work-2"), ReadingSource::externalLoan(new ExternalLoanId("loan-1"))));
    }

    public function testPreferredSourceCanBeChangedAndCleared(): void
    {
        $owner = new UserId("user-1");
        $entry = $this->entry("entry-1", $owner, "work-1", null, 1);
        $list = new NextReadingList($owner, NextReadingListVersion::initial(), [$entry]);
        $withSource = $list->withPreferredSource($entry->id(), PreferredReadingSource::externalLoan(new ExternalLoanId("loan-1")));
        $cleared = $withSource->withPreferredSource($entry->id(), null);

        self::assertSame("loan-1", $withSource->entries()[0]->preferredSource()?->externalLoanIdSnapshot()?->value());
        self::assertNull($cleared->entries()[0]->preferredSource());
        self::assertSame(3, $cleared->version()->value());
    }

    public function testUndoRestoresSameIdentityBetweenSurvivingAnchors(): void
    {
        $owner = new UserId("user-1");
        $first = $this->entry("entry-1", $owner, "work-1", null, 1);
        $removed = $this->entry("entry-2", $owner, "work-2", PreferredReadingSource::externalLoan(new ExternalLoanId("loan-1"), false), 2);
        $last = $this->entry("entry-3", $owner, "work-3", null, 3);
        $list = new NextReadingList($owner, NextReadingListVersion::initial(), [$first, $last->atPosition(new NextReadingPosition(2))]);

        $restored = $list->restored($removed, $first->id(), $last->id(), 2);

        self::assertSame(["entry-1", "entry-2", "entry-3"], array_map(fn ($entry) => $entry->id()->value(), $restored->entries()));
        self::assertSame("work-2", $restored->entries()[1]->workId()->value());
        self::assertSame("loan-1", $restored->entries()[1]->preferredSource()?->externalLoanIdSnapshot()?->value());
        self::assertNull($restored->entries()[1]->preferredSource()?->liveExternalLoanId());
        self::assertSame("2026-08-23T10:00:00+00:00", $restored->entries()[1]->createdAt()->format("c"));
        self::assertSame(2, $restored->entries()[1]->position()->value());
    }

    public function testUndoUsesBoundedOriginalPositionWhenAnchorsAreGoneAndRejectsDuplicateIdentity(): void
    {
        $owner = new UserId("user-1");
        $first = $this->entry("entry-1", $owner, "work-1", null, 1);
        $removed = $this->entry("entry-2", $owner, "work-2", null, 2);
        $list = new NextReadingList($owner, NextReadingListVersion::initial(), [$first]);

        $restored = $list->restored(
            $removed,
            new NextReadingEntryId("missing-before"),
            new NextReadingEntryId("missing-after"),
            99
        );
        self::assertSame(["entry-1", "entry-2"], array_map(fn ($entry) => $entry->id()->value(), $restored->entries()));

        $this->expectException(NextReadingUndoUnavailable::class);
        $restored->restored($removed, null, null, 2);
    }

    public function testUndoTokenUsesIdentifierValidation(): void
    {
        foreach (["", str_repeat("x", 192), "bad\xC3\x28"] as $invalid) {
            try {
                new NextReadingUndoToken($invalid);
                self::fail("Invalid Undo token was accepted.");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[DataProvider("invalidPositiveValues")]
    public function testVersionAndPositionMustBePositive(int $value): void
    {
        $this->expectException(ValidationException::class);
        $value === 0 ? new NextReadingPosition($value) : new NextReadingListVersion($value);
    }

    public static function invalidPositiveValues(): array
    {
        return [[0], [-1]];
    }

    private function entry(string $id, UserId $owner, string $workId, ?PreferredReadingSource $preferredSource, int $position): NextReadingEntry
    {
        return new NextReadingEntry(
            new NextReadingEntryId($id),
            $owner,
            new WorkId($workId),
            $preferredSource,
            new NextReadingPosition($position),
            new DateTimeImmutable("2026-08-23T10:00:00+00:00")
        );
    }
}
