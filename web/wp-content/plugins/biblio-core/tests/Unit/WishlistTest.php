<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Catalog\{EditionId,WorkId};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Wishlist\{WishlistEntry,WishlistEntryId,WishlistTargetType,WishlistWriteResult};
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class WishlistTest extends TestCase
{
    public function testWorkOnlyEntryCanBeRefinedWithoutChangingItsIdentity(): void
    {
        $createdAt = new DateTimeImmutable("2026-09-10T08:00:00.000000Z");
        $updatedAt = new DateTimeImmutable("2026-09-10T09:00:00.000000Z");
        $entry = WishlistEntry::workOnly(
            new WishlistEntryId("wish-1"),
            new UserId("user-1"),
            new WorkId("work-1"),
            $createdAt
        );

        $refined = $entry->refineToEdition(
            new EditionId("edition-1"),
            $updatedAt
        );

        self::assertSame(WishlistTargetType::WorkOnly, $entry->targetType());
        self::assertSame(WishlistTargetType::EditionSpecific, $refined->targetType());
        self::assertTrue($entry->id()->equals($refined->id()));
        self::assertSame($createdAt, $refined->createdAt());
        self::assertSame($updatedAt, $refined->updatedAt());
        self::assertTrue(WishlistWriteResult::refined($refined)->wasRefined());
    }

    public function testEditionSpecificEntryCannotBeCollapsedToAnotherIntent(): void
    {
        $entry = WishlistEntry::editionSpecific(
            new WishlistEntryId("wish-1"),
            new UserId("user-1"),
            new WorkId("work-1"),
            new EditionId("edition-1"),
            new DateTimeImmutable("2026-09-10T08:00:00.000000Z")
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("Only a Work-only Wishlist Entry");

        $entry->refineToEdition(
            new EditionId("edition-2"),
            new DateTimeImmutable("2026-09-10T09:00:00.000000Z")
        );
    }

    public function testUpdatedAtCannotPrecedeCreatedAt(): void
    {
        $this->expectException(ValidationException::class);

        new WishlistEntry(
            new WishlistEntryId("wish-1"),
            new UserId("user-1"),
            new WorkId("work-1"),
            null,
            new DateTimeImmutable("2026-09-10T09:00:00.000000Z"),
            new DateTimeImmutable("2026-09-10T08:00:00.000000Z")
        );
    }
}
