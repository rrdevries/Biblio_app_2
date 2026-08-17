<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit;

use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\IdentifierConstraints;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Reading\ReadingRoundId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PersistentIdentifierTest extends TestCase
{
    #[DataProvider("identifierClasses")]
    public function testMaximumPersistentLengthIsAccepted(string $class): void
    {
        $value = str_repeat("é", IdentifierConstraints::MAX_LENGTH);
        $identifier = new $class($value);

        self::assertSame($value, $identifier->value());
    }

    #[DataProvider("identifierClasses")]
    public function testOverlongPersistentIdentifierFailsValidation(
        string $class
    ): void {
        try {
            new $class(str_repeat("é", IdentifierConstraints::MAX_LENGTH + 1));
            self::fail("{$class} accepted an overlong persistent ID.");
        } catch (ValidationException $exception) {
            self::assertSame(
                FailureReason::ValidationFailed,
                $exception->reason()
            );
        }
    }

    #[DataProvider("identifierClasses")]
    public function testInvalidUtf8IdentifierFailsValidation(string $class): void
    {
        $this->expectException(ValidationException::class);

        new $class("invalid-\xFF");
    }

    public static function identifierClasses(): iterable
    {
        yield "UserId" => [UserId::class];
        yield "LibraryId" => [LibraryId::class];
        yield "WorkId" => [WorkId::class];
        yield "EditionId" => [EditionId::class];
        yield "ItemId" => [ItemId::class];
        yield "ExternalLoanId" => [ExternalLoanId::class];
        yield "ReadingRoundId" => [ReadingRoundId::class];
    }
}
