<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Collections\{CollectionDescription,CollectionId,CollectionItemPosition,CollectionMembership,CollectionMembershipConflict,CollectionMembershipEndReason,CollectionMembershipId,CollectionMembershipStatus,CollectionName,CollectionNameNormalizer,CollectionPosition,CollectionStatus,CollectionTransitionUnavailable,CollectionVersion,LibraryCollection};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CollectionFoundationTest extends TestCase
{
    public function testCollectionIdentityValuesAndOrderingAreExplicit(): void
    {
        $created = new DateTimeImmutable("2026-09-04 10:00:00.123456+00:00");
        $name = new CollectionName("  Zomer Lezen  ");
        $collection = LibraryCollection::create(
            new CollectionId("collection-a"),
            new LibraryId("library-a"),
            $name,
            (new CollectionNameNormalizer())->normalize($name),
            new CollectionDescription("Keuze"),
            new CollectionPosition(2),
            $created
        );

        self::assertSame("collection-a", $collection->id()->value());
        self::assertSame("library-a", $collection->libraryId()->value());
        self::assertSame("zomer lezen", $collection->normalizedName()->value());
        self::assertSame(CollectionStatus::Active, $collection->status());
        self::assertSame(2, $collection->position()->value());
        self::assertSame(1, $collection->version()->value());
        self::assertSame("123456", $collection->createdAt()->format("u"));
    }

    public function testNameAndDescriptionBoundariesAreEnforced(): void
    {
        self::assertSame(80, mb_strlen((new CollectionName(str_repeat("a", 80)))->value()));
        self::assertSame(300, mb_strlen((new CollectionDescription(str_repeat("b", 300)))->value()));

        foreach ([static fn () => new CollectionName(" "), static fn () => new CollectionName(str_repeat("a", 81)), static fn () => new CollectionDescription(str_repeat("b", 301))] as $invalid) {
            try { $invalid(); self::fail("Invalid Collection value was accepted."); }
            catch (ValidationException) {}
        }
    }

    public function testCollectionArchiveRestorePreservesIdentityAndAdvancesVersion(): void
    {
        $collection = $this->collection();
        $archived = $collection->archive(new DateTimeImmutable("2026-09-04 11:00:00+00:00"));
        $restored = $archived->restore(new DateTimeImmutable("2026-09-04 12:00:00+00:00"));

        self::assertTrue($collection->id()->equals($restored->id()));
        self::assertSame(CollectionStatus::Active, $restored->status());
        self::assertSame(3, $restored->version()->value());
        self::assertSame($collection->position()->value(), $restored->position()->value());
    }

    public function testArchivedCollectionIsReadOnly(): void
    {
        $archived = $this->collection()->archive(new DateTimeImmutable("2026-09-04 11:00:00+00:00"));

        $this->expectException(CollectionTransitionUnavailable::class);
        $archived->contentChanged(new DateTimeImmutable("2026-09-04 12:00:00+00:00"));
    }

    public function testMembershipDeactivationPreservesIdentityPositionAndHistoryReason(): void
    {
        $added = new DateTimeImmutable("2026-09-04 10:00:00.111111+00:00");
        $ended = new DateTimeImmutable("2026-09-04 11:00:00.222222+00:00");
        $membership = CollectionMembership::active(
            new CollectionMembershipId("membership-a"),
            new LibraryId("library-a"),
            new CollectionId("collection-a"),
            new ItemId("item-a"),
            new CollectionItemPosition(4),
            $added
        )->deactivate(CollectionMembershipEndReason::ItemArchived, $ended);

        self::assertSame(CollectionMembershipStatus::Inactive, $membership->status());
        self::assertSame("membership-a", $membership->id()->value());
        self::assertSame(4, $membership->position()->value());
        self::assertSame(CollectionMembershipEndReason::ItemArchived, $membership->endReason());
        self::assertSame("222222", $membership->endedAt()?->format("u"));
    }

    public function testInactiveMembershipCannotBeRepositioned(): void
    {
        $membership = CollectionMembership::active(
            new CollectionMembershipId("membership-a"), new LibraryId("library-a"),
            new CollectionId("collection-a"), new ItemId("item-a"),
            new CollectionItemPosition(1), new DateTimeImmutable("2026-09-04 10:00:00+00:00")
        )->deactivate(CollectionMembershipEndReason::Removed, new DateTimeImmutable("2026-09-04 11:00:00+00:00"));

        $this->expectException(CollectionMembershipConflict::class);
        $membership->reposition(new CollectionItemPosition(2));
    }

    public function testPositionsAndVersionMustBePositive(): void
    {
        $rejected = 0;
        foreach ([static fn () => new CollectionPosition(0), static fn () => new CollectionItemPosition(0), static fn () => new CollectionVersion(0)] as $invalid) {
            try { $invalid(); self::fail("Non-positive Collection value was accepted."); }
            catch (ValidationException) { ++$rejected; }
        }
        self::assertSame(3, $rejected);
    }

    private function collection(): LibraryCollection
    {
        $name = new CollectionName("Favorieten");
        return LibraryCollection::create(new CollectionId("collection-a"), new LibraryId("library-a"), $name, (new CollectionNameNormalizer())->normalize($name), null, new CollectionPosition(1), new DateTimeImmutable("2026-09-04 10:00:00+00:00"));
    }
}
