<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRoundLifecycle;
use Biblio\Core\Reading\ReadingSource;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ReadingRoundTest extends TestCase
{
    public function testActiveRoundHasOneUserWorkAndConcreteItemSource(): void
    {
        $source = ReadingSource::libraryItem(new ItemId("item-a"));
        $round = ReadingRound::active(
            new ReadingRoundId("round-a"),
            new UserId("user-x"),
            new WorkId("work-w"),
            $source,
            ReadingDate::exact(2026, 8, 16),
            new DateTimeImmutable("2026-08-16T10:00:00+00:00")
        );

        self::assertSame(ReadingRoundLifecycle::Active, $round->lifecycle());
        self::assertSame("user-x", $round->userId()->value());
        self::assertSame("work-w", $round->workId()->value());
        self::assertSame($source, $round->source());
        self::assertSame("item-a", $round->source()->itemId()?->value());
        self::assertNull($round->source()->externalLoanId());
    }

    public function testExternalLoanSourceCannotAlsoContainAnItem(): void
    {
        $source = ReadingSource::externalLoan(new ExternalLoanId("loan-l"));

        self::assertNull($source->itemId());
        self::assertSame("loan-l", $source->externalLoanId()?->value());
        self::assertTrue($source->equals(
            ReadingSource::externalLoan(new ExternalLoanId("loan-l"))
        ));
        self::assertFalse($source->equals(
            ReadingSource::libraryItem(new ItemId("loan-l"))
        ));
    }

    public function testReadingRoundIdMustNotBeEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ReadingRoundId("  ");
    }

    public function testStartDateOutsideUtcPersistenceRangeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReadingRound::active(
            new ReadingRoundId("round-a"),
            new UserId("user-x"),
            new WorkId("work-w"),
            ReadingSource::libraryItem(new ItemId("item-a")),
            ReadingDate::exact(1000, 1, 1),
            new DateTimeImmutable("1000-01-01T00:00:00+02:00")
        );
    }
}
