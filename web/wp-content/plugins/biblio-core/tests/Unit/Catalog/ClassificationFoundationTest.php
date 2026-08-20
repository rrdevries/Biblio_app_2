<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Catalog;

use Biblio\Core\Catalog\Classification\ClassificationNameNormalizer;
use Biblio\Core\Catalog\Classification\ClassificationSeedKey;
use Biblio\Core\Catalog\Classification\ClassificationTermName;
use Biblio\Core\Catalog\Classification\ClassificationTermStatus;
use Biblio\Core\Catalog\Classification\DefaultClassificationSeeds;
use Biblio\Core\Catalog\Classification\LibraryBookTypeId;
use Biblio\Core\Catalog\Classification\LibraryCatalogContext;
use Biblio\Core\Catalog\Classification\LibraryCatalogContextVersion;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\Classification\LibraryGenreId;
use Biblio\Core\Catalog\Classification\LibrarySubjectId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClassificationFoundationTest extends TestCase
{
    #[DataProvider("identifierClasses")]
    public function testClassificationIdentifiersUsePersistentIdContract(
        string $class
    ): void {
        $identifier = new $class("classification-id");

        self::assertSame("classification-id", $identifier->value());

        $this->expectException(ValidationException::class);
        new $class("");
    }

    public static function identifierClasses(): iterable
    {
        yield [LibraryBookTypeId::class];
        yield [LibraryGenreId::class];
        yield [LibrarySubjectId::class];
    }

    public function testTermNamePreservesDisplayValueAndRejectsInvalidInput(): void
    {
        self::assertSame(
            "  Magisch realisme  ",
            (new ClassificationTermName("  Magisch realisme  "))->value()
        );

        foreach (["   ", "invalid-\xFF"] as $invalid) {
            try {
                new ClassificationTermName($invalid);
                self::fail("Invalid classification term name was accepted.");
            } catch (ValidationException) {
            }
        }
    }

    #[DataProvider("normalizationCases")]
    public function testNormalizerFollowsConservativeUnicodeContract(
        string $input,
        string $expected
    ): void {
        self::assertSame(
            $expected,
            ClassificationNameNormalizer::create()
                ->normalize(new ClassificationTermName($input))
                ->value()
        );
    }

    public static function normalizationCases(): iterable
    {
        yield "case fold" => ["FANTASY", "fantasy"];
        yield "accented case fold" => ["CAFÉ", "café"];
        yield "unicode whitespace" => ["  Magisch\u{00A0}\u{2003}realisme  ", "magisch realisme"];
        yield "ASCII dash" => ["Sci-Fi", "sci fi"];
        yield "Unicode dash" => ["Sci—Fi", "sci fi"];
        yield "punctuation retained" => ["Detective / Mystery", "detective / mystery"];
    }

    #[DataProvider("nonEquivalentNames")]
    public function testNormalizerDoesNotCreateSemanticEquivalence(
        string $left,
        string $right
    ): void {
        $normalizer = ClassificationNameNormalizer::create();

        self::assertFalse(
            $normalizer->normalize(new ClassificationTermName($left))->equals(
                $normalizer->normalize(new ClassificationTermName($right))
            )
        );
    }

    public static function nonEquivalentNames(): iterable
    {
        yield "accent" => ["café", "cafe"];
        yield "synonym" => ["Sci Fi", "Sciencefiction"];
        yield "abbreviation" => ["WO II", "Tweede Wereldoorlog"];
        yield "punctuation" => ["Detective / Mystery", "Detective Mystery"];
    }

    public function testDefaultSeedRegistryIsExactAndSubjectSetIsEmpty(): void
    {
        self::assertSame([
            "book_type.reading_book" => "Leesboek",
            "book_type.cookbook" => "Kookboek",
            "book_type.study_book" => "Studieboek",
            "book_type.knowledge_book" => "Kennisboek",
            "book_type.comic_book" => "Stripboek",
            "book_type.picture_book" => "Prentenboek",
            "book_type.travel_guide" => "Reisgids",
            "book_type.dictionary" => "Woordenboek",
            "book_type.photo_book" => "Fotoboek",
        ], $this->seedValues(DefaultClassificationSeeds::bookTypes()));
        self::assertSame([
            "genre.adventure" => "Avontuur",
            "genre.fantasy" => "Fantasy",
            "genre.science_fiction" => "Sciencefiction",
            "genre.thriller" => "Thriller",
            "genre.detective_mystery" => "Detective / Mystery",
            "genre.horror" => "Horror",
            "genre.romance" => "Romance",
            "genre.historical" => "Historisch",
            "genre.literature" => "Literatuur",
            "genre.humor_satire" => "Humor / Satire",
            "genre.dystopia" => "Dystopie",
            "genre.magical_realism" => "Magisch realisme",
        ], $this->seedValues(DefaultClassificationSeeds::genres()));
        self::assertSame([], DefaultClassificationSeeds::subjects());
    }

    public function testSeedKeyIsImmutableAndUsesTechnicalFormat(): void
    {
        $key = new ClassificationSeedKey("genre.fantasy");

        self::assertSame("genre.fantasy", $key->value());

        $this->expectException(ValidationException::class);
        new ClassificationSeedKey("Genre Fantasy");
    }

    public function testSelectionIsOrderIndependentAndCanonical(): void
    {
        $left = new LibraryCatalogSelection(
            new LibraryBookTypeId("book-type"),
            [new LibraryGenreId("genre-b"), new LibraryGenreId("genre-a")],
            [new LibrarySubjectId("subject-b"), new LibrarySubjectId("subject-a")]
        );
        $right = new LibraryCatalogSelection(
            new LibraryBookTypeId("book-type"),
            [new LibraryGenreId("genre-a"), new LibraryGenreId("genre-b")],
            [new LibrarySubjectId("subject-a"), new LibrarySubjectId("subject-b")]
        );

        self::assertTrue($left->equals($right));
        self::assertSame(
            ["genre-a", "genre-b"],
            array_map(static fn (LibraryGenreId $id): string => $id->value(), $left->genreIds())
        );
        self::assertSame(
            ["subject-a", "subject-b"],
            array_map(static fn (LibrarySubjectId $id): string => $id->value(), $left->subjectIds())
        );
    }

    public function testSelectionRejectsDuplicateGenreAndSubjectIds(): void
    {
        $rejectedSelections = 0;

        foreach ([
            [[new LibraryGenreId("same"), new LibraryGenreId("same")], []],
            [[], [new LibrarySubjectId("same"), new LibrarySubjectId("same")]],
        ] as [$genres, $subjects]) {
            try {
                new LibraryCatalogSelection(
                    new LibraryBookTypeId("book-type"),
                    $genres,
                    $subjects
                );
                self::fail("Duplicate classification selection was accepted.");
            } catch (ValidationException) {
                $rejectedSelections++;
            }
        }

        self::assertSame(2, $rejectedSelections);
    }

    public function testContextIdentityAndInitialVersionAreExplicit(): void
    {
        $classification = new LibraryCatalogSelection(
            new LibraryBookTypeId("book-type")
        );
        $context = LibraryCatalogContext::create(
            new LibraryId("library-a"),
            new WorkId("work-a"),
            $classification
        );

        self::assertSame("library-a", $context->libraryId()->value());
        self::assertSame("work-a", $context->workId()->value());
        self::assertSame(1, $context->version()->value());
        self::assertTrue($context->hasSameClassification($classification));
        self::assertSame("active", ClassificationTermStatus::Active->value);
        self::assertSame("inactive", ClassificationTermStatus::Inactive->value);
    }

    public function testContextVersionRejectsNonPositiveValues(): void
    {
        $this->expectException(ValidationException::class);
        new LibraryCatalogContextVersion(0);
    }

    /**
     * @param list<\Biblio\Core\Catalog\Classification\ClassificationSeed> $seeds
     * @return array<string, string>
     */
    private function seedValues(array $seeds): array
    {
        $values = [];

        foreach ($seeds as $seed) {
            $values[$seed->key()->value()] = $seed->defaultName()->value();
        }

        return $values;
    }
}
