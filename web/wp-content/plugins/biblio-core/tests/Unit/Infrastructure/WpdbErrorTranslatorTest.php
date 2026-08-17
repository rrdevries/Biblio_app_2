<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\Persistence\DatabaseConflictType;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbErrorTranslator;
use PHPUnit\Framework\TestCase;

final class WpdbErrorTranslatorTest extends TestCase
{
    public function testDuplicateKeyBecomesTechnicalConflict(): void
    {
        $conflict = WpdbErrorTranslator::conflict(
            "Duplicate entry 'user|item' for key "
                . "'wp_biblio_reading_rounds.one_active_item_round_per_user'"
        );

        self::assertNotNull($conflict);
        self::assertSame(
            DatabaseConflictType::UniqueConstraint,
            $conflict->type()
        );
        self::assertSame(
            "one_active_item_round_per_user",
            $conflict->constraintName()
        );
    }

    public function testUnrecognizedDatabaseErrorIsNotAConflict(): void
    {
        self::assertNull(WpdbErrorTranslator::conflict(
            "Cannot add or update a child row: a foreign key constraint fails"
        ));
    }

    public function testPersistenceContractDoesNotPublishRawDatabaseText(): void
    {
        $raw = "sensitive MariaDB diagnostic";
        $exception = WpdbErrorTranslator::writeFailure(
            "Could not persist record.",
            $raw
        );

        self::assertSame(
            FailureReason::PersistenceWriteFailed,
            $exception->reason()
        );
        self::assertStringNotContainsString($raw, $exception->getMessage());
        self::assertStringContainsString(
            $raw,
            $exception->getPrevious()?->getMessage() ?? ""
        );
    }
}
