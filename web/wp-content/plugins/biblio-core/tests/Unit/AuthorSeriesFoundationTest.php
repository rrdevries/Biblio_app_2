<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Catalog\{Author,AuthorId,ContributorPosition,ContributorRole,Series,SeriesId,SeriesPosition,WorkContributor,WorkContributors,WorkId,WorkSeriesMembership,WorkSeriesMemberships};
use Biblio\Core\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ValueError;

final class AuthorSeriesFoundationTest extends TestCase
{
    public function testAuthorAndSeriesKeepStableIdentityAndUnicodeName(): void
    {
        $author = new Author(new AuthorId("author-1"), "Gabriel García Márquez");
        $series = new Series(new SeriesId("series-1"), "À la recherche");

        self::assertSame("author-1", $author->id()->value());
        self::assertSame("Gabriel García Márquez", $author->displayName());
        self::assertSame("series-1", $series->id()->value());
        self::assertSame("À la recherche", $series->displayName());
    }

    #[DataProvider("invalidNameProvider")]
    public function testAuthorAndSeriesRejectInvalidNames(string $name): void
    {
        $this->expectException(ValidationException::class);
        new Author(new AuthorId("author-1"), $name);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNameProvider(): iterable
    {
        yield "empty" => ["  "];
        yield "invalid UTF-8" => ["\xC3\x28"];
        yield "too long" => [str_repeat("é", Author::MAX_NAME_LENGTH + 1)];
    }

    public function testSeriesRejectsInvalidNames(): void
    {
        foreach ([
            "",
            "\xC3\x28",
            str_repeat("s", Series::MAX_NAME_LENGTH + 1),
        ] as $name) {
            try {
                new Series(new SeriesId("series-1"), $name);
                self::fail("Invalid Series name was accepted.");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testContributorRolesAndDeterministicExplicitOrder(): void
    {
        $work = new WorkId("work-1");
        $first = new WorkContributor($work, new AuthorId("author-1"), ContributorRole::Author, new ContributorPosition(1));
        $second = new WorkContributor($work, new AuthorId("author-2"), ContributorRole::CoAuthor, new ContributorPosition(2));
        $contributors = new WorkContributors([$second, $first]);

        self::assertSame(["author-1", "author-2"], array_map(
            static fn (WorkContributor $value): string => $value->authorId()->value(),
            $contributors->values()
        ));
        self::assertSame(ContributorRole::Author, $contributors->values()[0]->role());
        self::assertSame(ContributorRole::CoAuthor, $contributors->values()[1]->role());
        $this->expectException(ValueError::class);
        ContributorRole::from("illustrator");
    }

    public function testContributorPositionAndDuplicatesAreRejected(): void
    {
        try {
            new ContributorPosition(0);
            self::fail("Zero contributor position was accepted.");
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }

        $work = new WorkId("work-1");
        $relation = new WorkContributor($work, new AuthorId("author-1"), ContributorRole::Author, new ContributorPosition(1));
        $this->expectException(ValidationException::class);
        new WorkContributors([$relation, $relation]);
    }

    public function testContributorPositionsAreUniqueWithinAWork(): void
    {
        $work = new WorkId("work-1");

        $this->expectException(ValidationException::class);
        new WorkContributors([
            new WorkContributor(
                $work,
                new AuthorId("author-1"),
                ContributorRole::Author,
                new ContributorPosition(1)
            ),
            new WorkContributor(
                $work,
                new AuthorId("author-2"),
                ContributorRole::CoAuthor,
                new ContributorPosition(1)
            ),
        ]);
    }

    public function testRelationshipAggregatesRejectMixedWorks(): void
    {
        try {
            new WorkContributors([
                new WorkContributor(
                    new WorkId("work-1"),
                    new AuthorId("author-1"),
                    ContributorRole::Author,
                    new ContributorPosition(1)
                ),
                new WorkContributor(
                    new WorkId("work-2"),
                    new AuthorId("author-2"),
                    ContributorRole::CoAuthor,
                    new ContributorPosition(2)
                ),
            ]);
            self::fail("Mixed-Work contributors were accepted.");
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }

        $this->expectException(ValidationException::class);
        new WorkSeriesMemberships([
            new WorkSeriesMembership(
                new WorkId("work-1"),
                new SeriesId("series-1"),
                SeriesPosition::unknown()
            ),
            new WorkSeriesMembership(
                new WorkId("work-2"),
                new SeriesId("series-2"),
                SeriesPosition::known("2")
            ),
        ]);
    }

    public function testKnownAndUnknownSeriesPositionsPreserveMeaning(): void
    {
        $unknown = SeriesPosition::unknown();
        $integer = SeriesPosition::known("12");
        $fractional = SeriesPosition::known("1.500000");

        self::assertFalse($unknown->isKnown());
        self::assertNull($unknown->value());
        self::assertSame("12", $integer->value());
        self::assertSame("1.5", $fractional->value());
    }

    #[DataProvider("invalidPositionProvider")]
    public function testInvalidSeriesPositionIsRejected(string $position): void
    {
        $this->expectException(ValidationException::class);
        SeriesPosition::known($position);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPositionProvider(): iterable
    {
        yield "negative" => ["-1"];
        yield "leading zero" => ["01"];
        yield "too precise" => ["1.0000001"];
        yield "not numeric" => ["first"];
    }

    public function testDuplicateWorkSeriesMembershipIsRejectedCoreSide(): void
    {
        $membership = new WorkSeriesMembership(
            new WorkId("work-1"),
            new SeriesId("series-1"),
            SeriesPosition::unknown()
        );
        $this->expectException(ValidationException::class);
        new WorkSeriesMemberships([$membership, $membership]);
    }
}
