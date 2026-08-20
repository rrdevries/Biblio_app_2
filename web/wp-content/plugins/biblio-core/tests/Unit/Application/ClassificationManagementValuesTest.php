<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Catalog\Classification\LibraryCatalogSelectionSnapshot;
use Biblio\Core\Catalog\Classification\ClassificationTermStatus;
use Biblio\Core\Catalog\Classification\LibraryBookTypeId;
use Biblio\Core\Catalog\Classification\LibraryCatalogContext;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\Classification\LibraryGenreId;
use Biblio\Core\Catalog\Classification\LibrarySubjectId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;
use PHPUnit\Framework\TestCase;

final class ClassificationManagementValuesTest extends TestCase
{
    public function testReclassificationIncrementsExactlyOnce(): void
    {
        $current = LibraryCatalogContext::create(
            new LibraryId("library-a"),
            new WorkId("work-a"),
            new LibraryCatalogSelection(new LibraryBookTypeId("book-a"))
        );
        $replacement = $current->reclassify(new LibraryCatalogSelection(
            new LibraryBookTypeId("book-b"),
            [new LibraryGenreId("genre-a")],
            [new LibrarySubjectId("subject-a")]
        ));

        self::assertSame(1, $current->version()->value());
        self::assertSame(2, $replacement->version()->value());
        self::assertSame("book-b", $replacement
            ->classification()->bookTypeId()->value());
    }

    public function testRetainedInactiveTermsRemainValidButNewOnesDoNot(): void
    {
        $current = new LibraryCatalogSelectionSnapshot(
            $this->term("book-a", ClassificationTermStatus::Inactive),
            [$this->term("genre-a", ClassificationTermStatus::Inactive)],
            [$this->term("subject-a", ClassificationTermStatus::Inactive)]
        );
        $retained = new LibraryCatalogSelectionSnapshot(
            $this->term("book-a", ClassificationTermStatus::Inactive),
            [$this->term("genre-a", ClassificationTermStatus::Inactive)],
            [$this->term("subject-a", ClassificationTermStatus::Inactive)]
        );

        $retained->assertNewSelectionsAreActive($current);
        self::addToAssertionCount(1);

        $newInactive = new LibraryCatalogSelectionSnapshot(
            $this->term("book-a", ClassificationTermStatus::Inactive),
            [
                $this->term("genre-a", ClassificationTermStatus::Inactive),
                $this->term("genre-b", ClassificationTermStatus::Inactive),
            ],
            [$this->term("subject-a", ClassificationTermStatus::Inactive)]
        );

        $this->expectException(ValidationException::class);
        $newInactive->assertNewSelectionsAreActive($current);
    }

    /** @return array{id: string, label: string, status: ClassificationTermStatus} */
    private function term(
        string $id,
        ClassificationTermStatus $status
    ): array {
        return ["id" => $id, "label" => $id, "status" => $status];
    }
}
