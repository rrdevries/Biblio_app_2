<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Borrowing\ExternalLoan;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Borrowing\ExternalLoanStatus;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ExternalLoanTest extends TestCase
{
    public function testActiveExternalLoanCarriesUserOwnedState(): void
    {
        $borrowedAt = $this->utc("2026-08-10 09:30:00.123456");
        $dueAt = $this->utc("2026-08-24 17:00:00.000000");
        $loan = ExternalLoan::active(
            new ExternalLoanId("loan-x"),
            new UserId("user-x"),
            new WorkId("work-w"),
            $borrowedAt,
            $dueAt
        );

        self::assertSame("loan-x", $loan->id()->value());
        self::assertSame("user-x", $loan->userId()->value());
        self::assertSame("work-w", $loan->workId()->value());
        self::assertSame(ExternalLoanStatus::Active, $loan->status());
        self::assertSame($borrowedAt, $loan->borrowedAt());
        self::assertSame($dueAt, $loan->dueAt());
    }

    public function testDueDateMayBeAbsent(): void
    {
        $loan = ExternalLoan::active(
            new ExternalLoanId("loan-x"),
            new UserId("user-x"),
            new WorkId("work-w"),
            $this->utc("2026-08-10 09:30:00.000000")
        );

        self::assertNull($loan->dueAt());
    }

    public function testExternalLoanIdRejectsEmptyValue(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ExternalLoanId("  ");
    }

    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone("UTC"));
    }
}
