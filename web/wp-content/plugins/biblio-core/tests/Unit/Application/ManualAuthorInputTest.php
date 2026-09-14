<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitialization;
use Biblio\Core\Application\Metadata\{
    AddBookCommitRequest,
    AddBookCommitSelection,
    AddBookObservedMetadata,
    ManualAuthorInput
};
use Biblio\Core\Catalog\Classification\{
    LibraryBookTypeId,
    LibraryCatalogSelection
};
use Biblio\Core\Exception\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ManualAuthorInputTest extends TestCase
{
    public function testNormalizesOnlyUnicodeWhitespaceAndPreservesNameText(): void
    {
        $author = ManualAuthorInput::fromDisplayName(
            " \tGeorge\u{00A0}C.  Clark Jr.\n"
        );

        self::assertNotNull($author);
        self::assertSame("George C. Clark Jr.", $author->displayName());
        self::assertSame(
            "Émilie d’Arcy-Smith",
            ManualAuthorInput::fromDisplayName("Émilie d’Arcy-Smith")?->displayName()
        );
        self::assertNull(ManualAuthorInput::fromDisplayName(" \u{2003}\t "));
    }

    public function testAccepts512UnicodeCharactersAndRejects513(): void
    {
        self::assertSame(
            512,
            mb_strlen(ManualAuthorInput::fromDisplayName(
                str_repeat("é", 512)
            )?->displayName() ?? "")
        );

        $this->expectException(ValidationException::class);
        ManualAuthorInput::fromDisplayName(str_repeat("é", 513));
    }

    public function testRequestAcceptsAtMost32TypedAuthors(): void
    {
        $author = ManualAuthorInput::fromDisplayName("Author");
        self::assertNotNull($author);
        $request = new AddBookCommitRequest(
            null,
            AddBookCommitSelection::manual(),
            new AddBookObservedMetadata([]),
            $this->classification(),
            authors: array_fill(0, 32, $author)
        );
        self::assertCount(32, $request->authors());

        $this->expectException(InvalidArgumentException::class);
        new AddBookCommitRequest(
            null,
            AddBookCommitSelection::manual(),
            new AddBookObservedMetadata([]),
            $this->classification(),
            authors: array_fill(0, 33, $author)
        );
    }

    private function classification(): LibraryCatalogContextInitialization
    {
        return new LibraryCatalogContextInitialization(
            new LibraryCatalogSelection(new LibraryBookTypeId("book-a"))
        );
    }
}
