<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Catalog\{AlternateWorkTitle,ContainmentPosition,Edition,EditionId,EditionIsbnMetadata,InventoryNumber,Isbn10,Isbn13,WorkAlternateTitles,WorkContainment,WorkContainments,WorkId};
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SearchMetadataFoundationTest extends TestCase
{
    public function testAlternateTitlesPreserveTextAndHaveDeterministicIdentity(): void
    {
        $workId = new WorkId("work-1");
        $title = new AlternateWorkTitle($workId, "  De   Titel  ");
        $collection = new WorkAlternateTitles([
            new AlternateWorkTitle($workId, "Zulu"),
            $title,
        ]);

        self::assertSame("  De   Titel  ", $title->value());
        self::assertSame("de titel", $title->normalizedKey());
        self::assertSame(
            ["  De   Titel  ", "Zulu"],
            array_map(
                static fn (AlternateWorkTitle $value): string => $value->value(),
                $collection->values()
            )
        );
    }

    public function testAlternateTitlesRejectInvalidValuesAndDuplicates(): void
    {
        $workId = new WorkId("work-1");
        foreach (["   ", "invalid-\xFF", str_repeat("é", 513)] as $invalid) {
            try {
                new AlternateWorkTitle($workId, $invalid);
                self::fail("Invalid alternate title was accepted.");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $this->expectException(InvalidArgumentException::class);
        new WorkAlternateTitles([
            new AlternateWorkTitle($workId, "The  Title"),
            new AlternateWorkTitle($workId, " the title "),
        ]);
    }

    public function testIsbnValuesNormalizeAndValidateChecksums(): void
    {
        self::assertSame("0306406152", (new Isbn10("0-306-40615-2"))->value());
        self::assertSame("9780306406157", (new Isbn13("978-0-306-40615-7"))->value());

        foreach ([
            static fn () => new Isbn10("0306406153"),
            static fn () => new Isbn13("9780306406158"),
        ] as $invalid) {
            try {
                $invalid();
                self::fail("Invalid ISBN was accepted.");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEditionDistinguishesUnknownNoIsbnAndKnownIsbn(): void
    {
        $unknown = new Edition(new EditionId("edition-1"), new WorkId("work-1"));
        $without = new Edition(
            new EditionId("edition-2"),
            new WorkId("work-1"),
            EditionIsbnMetadata::withoutIsbn()
        );
        $known = new Edition(
            new EditionId("edition-3"),
            new WorkId("work-1"),
            EditionIsbnMetadata::identified(
                new Isbn10("0306406152"),
                new Isbn13("9780306406157")
            )
        );

        self::assertNull($unknown->isbnMetadata()->isbn10());
        self::assertFalse($unknown->isbnMetadata()->isExplicitlyWithoutIsbn());
        self::assertTrue($without->isbnMetadata()->isExplicitlyWithoutIsbn());
        self::assertSame("0306406152", $known->isbnMetadata()->isbn10()?->value());
        self::assertSame("9780306406157", $known->isbnMetadata()->isbn13()?->value());
    }

    public function testInventoryNumberIsOptionalMetadataWithPersistableBounds(): void
    {
        self::assertSame("INV-001", (new InventoryNumber(" INV-001 "))->value());

        foreach ([" ", "invalid-\xFF", str_repeat("x", 192)] as $invalid) {
            try {
                new InventoryNumber($invalid);
                self::fail("Invalid inventory number was accepted.");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testContainmentEnforcesIdentityOrderAndAcyclicGraph(): void
    {
        $first = new WorkContainment(
            new WorkId("bundle"),
            new WorkId("part-1"),
            new ContainmentPosition(1)
        );
        $second = new WorkContainment(
            new WorkId("bundle"),
            new WorkId("part-2"),
            new ContainmentPosition(2)
        );
        self::assertSame([$first, $second], (new WorkContainments([$second, $first]))->values());

        foreach ([
            static fn () => new WorkContainment(new WorkId("same"), new WorkId("same"), new ContainmentPosition(1)),
            static fn () => new ContainmentPosition(0),
            static fn () => new WorkContainments([$first, new WorkContainment(new WorkId("bundle"), new WorkId("part-3"), new ContainmentPosition(1))]),
            static fn () => new WorkContainments([
                new WorkContainment(new WorkId("a"), new WorkId("b"), new ContainmentPosition(1)),
                new WorkContainment(new WorkId("b"), new WorkId("a"), new ContainmentPosition(1)),
            ]),
        ] as $invalid) {
            try {
                $invalid();
                self::fail("Invalid containment was accepted.");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
