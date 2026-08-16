<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Library\LibraryId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LibraryIdTest extends TestCase
{
    public function testValidLibraryIdRetainsItsValue(): void
    {
        $libraryId = new LibraryId("library-a");

        self::assertSame("library-a", $libraryId->value());
    }

    public function testEmptyLibraryIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LibraryId("");
    }

    public function testWhitespaceOnlyLibraryIdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LibraryId(" \t\n");
    }
}
