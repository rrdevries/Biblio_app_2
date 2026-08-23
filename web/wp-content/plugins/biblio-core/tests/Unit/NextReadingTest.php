<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\{ItemId,WorkId};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\NextReading\{NextReadingEntry,NextReadingEntryId,NextReadingList,NextReadingListVersion,NextReadingPosition,NextReadingTarget,NextReadingTargetDuplicate};
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NextReadingTest extends TestCase
{
    public function testClosedTargetsKeepTheirImmutableSnapshots(): void
    {
        $work = NextReadingTarget::forWork(new WorkId("work-1"));
        $item = NextReadingTarget::forLibraryItem(
            new WorkId("work-1"),
            new ItemId("item-1"),
            new LibraryId("library-1"),
            false
        );
        $loan = NextReadingTarget::forExternalLoan(
            new WorkId("work-1"),
            new ExternalLoanId("loan-1"),
            false
        );

        self::assertNull($work->itemIdSnapshot());
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

    public function testListAppendsReordersNoOpsAndCompacts(): void
    {
        $owner = new UserId("user-1");
        $list = NextReadingList::empty($owner);
        $first = $this->entry("entry-1", $owner, NextReadingTarget::forWork(new WorkId("work-1")), 1);
        $second = $this->entry("entry-2", $owner, NextReadingTarget::forWork(new WorkId("work-2")), 2);
        $list = $list->append($first)->append($second);

        self::assertSame(3, $list->version()->value());
        self::assertSame([1, 2], array_map(fn ($entry) => $entry->position()->value(), $list->entries()));
        self::assertSame($list, $list->reordered([$first->id(), $second->id()]));

        $reordered = $list->reordered([$second->id(), $first->id()]);
        self::assertSame(4, $reordered->version()->value());
        self::assertSame(["entry-2", "entry-1"], array_map(fn ($entry) => $entry->id()->value(), $reordered->entries()));

        $remaining = $reordered->without($second->id());
        self::assertSame(1, $remaining->entries()[0]->position()->value());
        self::assertSame(5, $remaining->version()->value());
    }

    public function testDuplicateTargetAndInvalidOwnerOrOrderingAreRejected(): void
    {
        $owner = new UserId("user-1");
        $target = NextReadingTarget::forWork(new WorkId("work-1"));
        $first = $this->entry("entry-1", $owner, $target, 1);

        $this->expectException(NextReadingTargetDuplicate::class);
        new NextReadingList($owner, NextReadingListVersion::initial(), [
            $first,
            $this->entry("entry-2", $owner, $target, 2),
        ]);
    }

    #[DataProvider("invalidPositiveValues")]
    public function testVersionAndPositionMustBePositive(int $value): void
    {
        $this->expectException(ValidationException::class);
        $value === 0
            ? new NextReadingPosition($value)
            : new NextReadingListVersion($value);
    }

    public static function invalidPositiveValues(): array
    {
        return [[0], [-1]];
    }

    private function entry(
        string $id,
        UserId $owner,
        NextReadingTarget $target,
        int $position
    ): NextReadingEntry {
        return new NextReadingEntry(
            new NextReadingEntryId($id),
            $owner,
            $target,
            new NextReadingPosition($position),
            new DateTimeImmutable("2026-08-23T10:00:00+00:00")
        );
    }
}
