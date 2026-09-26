<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Migration\Cutover\{ItemRepairVerifier, RehearsalFailure};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ItemRepairVerifierTest extends TestCase
{
    public function testEveryEligibleCopyRequiresItsOwnItemEvenOnOneWork(): void
    {
        $links = (new ItemRepairVerifier())->verify(
            ['copy:one' => 'work:book', 'copy:two' => 'work:book'],
            [['source_id' => 'copy:one', 'item_id' => 'item:one'], ['source_id' => 'copy:two', 'item_id' => 'item:two']],
            [], [], ['item:one' => 'work:book', 'item:two' => 'work:book']
        );
        self::assertSame(['copy:one' => 'item:one', 'copy:two' => 'item:two'], $links);
    }

    public function testMixedBookWithEligibleAndErroneousCopyIsVisibleThroughOnlyEligibleCopy(): void
    {
        // Irish-hearts regression shape: one Book, two different terminal Copy dispositions.
        // The existing planner admits only the legitimate Copy of this Book.
        $book = ['work_id' => 'work:mixed-book', 'eligible_copy' => 'copy:legitimate', 'erroneous_copy' => 'copy:erroneous'];
        $catalog = ['item:legitimate' => $book['work_id']];
        $links = (new ItemRepairVerifier())->verify(
            [$book['eligible_copy'] => $book['work_id']],
            [['source_id' => $book['eligible_copy'], 'item_id' => 'item:legitimate']],
            [$book['erroneous_copy']], [], $catalog
        );
        self::assertSame([$book['eligible_copy'] => 'item:legitimate'], $links);
        self::assertArrayNotHasKey($book['erroneous_copy'], $links);
        self::assertSame($book['work_id'], $catalog[$links[$book['eligible_copy']]]);
    }

    public function testMissingEligibleCopyFailsEvenWhenBookAlreadyVisibleThroughAnotherItem(): void
    {
        $this->expectExceptionMessage('repair_eligible_copy_without_item');
        (new ItemRepairVerifier())->verify(['copy:eligible' => 'work:book'], [], ['copy:excluded'],
            ['copy:previous' => 'item:previous'], ['item:previous' => 'work:book']);
    }

    public function testTwoItemsForOneCopyFail(): void
    {
        $this->expectExceptionMessage('repair_copy_multiple_items');
        (new ItemRepairVerifier())->verify(['copy:eligible' => 'work:book'],
            [['source_id' => 'copy:eligible', 'item_id' => 'item:one'], ['source_id' => 'copy:eligible', 'item_id' => 'item:two']],
            [], [], ['item:one' => 'work:book', 'item:two' => 'work:book']);
    }

    public function testTwoCopiesCannotShareOneItem(): void
    {
        $this->expectExceptionMessage('repair_item_identity_reused');
        (new ItemRepairVerifier())->verify(['copy:one' => 'work:book', 'copy:two' => 'work:book'],
            [['source_id' => 'copy:one', 'item_id' => 'item:one'], ['source_id' => 'copy:two', 'item_id' => 'item:one']],
            [], [], ['item:one' => 'work:book']);
    }

    public function testExcludedCopyCannotAppearInRepairMappings(): void
    {
        $this->expectExceptionMessage('repair_excluded_copy_materialized');
        (new ItemRepairVerifier())->verify(['copy:eligible' => 'work:book'],
            [['source_id' => 'copy:excluded', 'item_id' => 'item:bad']], ['copy:excluded'], [], ['item:bad' => 'work:book']);
    }

    public function testExcludedCopyCannotHavePriorItemEither(): void
    {
        $this->expectExceptionMessage('repair_excluded_copy_materialized');
        (new ItemRepairVerifier())->verify(['copy:eligible' => 'work:book'], [], ['copy:excluded'],
            ['copy:excluded' => 'item:bad'], ['item:bad' => 'work:book']);
    }

    public function testExcludedCopyCannotEnterRequiredPopulation(): void
    {
        $this->expectExceptionMessage('repair_excluded_copy_approved');
        (new ItemRepairVerifier())->verify(['copy:excluded' => 'work:book'], [], ['copy:excluded'], [], []);
    }

    #[DataProvider('otherTerminalDispositions')]
    public function testOtherNonItemDispositionsStayOutsideRepair(string $sourceId): void
    {
        $eligible = ['copy:eligible' => 'work:book'];
        $mapping = [['source_id' => 'copy:eligible', 'item_id' => 'item:good']];
        $visible = ['item:good' => 'work:book', 'item:bad' => 'work:other'];
        self::assertSame(['copy:eligible' => 'item:good'], (new ItemRepairVerifier())->verify($eligible, $mapping, [], [], $visible));
        $mapping[] = ['source_id' => $sourceId, 'item_id' => 'item:bad'];
        $this->expectExceptionMessage('repair_unexpected_copy_materialized');
        (new ItemRepairVerifier())->verify($eligible, $mapping, [], [], $visible);
    }

    public static function otherTerminalDispositions(): iterable
    {
        foreach (['borrowed', 'archived', 'cat-quarantined', 'ambiguous-circulation', 'wishlist-only', 'other-unapproved'] as $case) {
            yield $case => ['copy:' . $case];
        }
    }

    #[DataProvider('invisibleOrWrongWork')]
    public function testEligibleItemMustBeVisibleOnExpectedWork(array $catalog): void
    {
        $this->expectExceptionMessage('repair_copy_item_not_visible_on_expected_work');
        (new ItemRepairVerifier())->verify(['copy:eligible' => 'work:book'],
            [['source_id' => 'copy:eligible', 'item_id' => 'item:eligible']], [], [], $catalog);
    }

    public static function invisibleOrWrongWork(): iterable
    {
        yield 'invisible' => [[]];
        yield 'wrong Work' => [['item:eligible' => 'work:other']];
    }

    public function testExistingItemCannotBeReusedForNewCopy(): void
    {
        $this->expectException(RehearsalFailure::class);
        $this->expectExceptionMessage('repair_item_identity_reused');
        (new ItemRepairVerifier())->verify(['copy:eligible' => 'work:book'],
            [['source_id' => 'copy:eligible', 'item_id' => 'item:prior']], [],
            ['copy:prior' => 'item:prior'], ['item:prior' => 'work:book']);
    }
}
