<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryStatus;
use Biblio\Core\Library\LibraryType;
use PHPUnit\Framework\TestCase;

final class LibraryTest extends TestCase
{
    public function testPrivateLibraryHasCanonicalV2001State(): void
    {
        $library = Library::privateLibrary(new LibraryId("library-a"));

        self::assertSame("library-a", $library->id()->value());
        self::assertSame(LibraryType::PrivateLibrary, $library->type());
        self::assertSame(LibraryStatus::Active, $library->status());
    }
}
