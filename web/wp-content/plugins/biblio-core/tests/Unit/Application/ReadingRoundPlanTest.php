<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Migration\Reading\ReadingRoundPlan;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\{
    ReadingDate,
    ReadingPeriod,
    ReadingRoundOutcome
};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReadingRoundPlanTest extends TestCase
{
    public function testCanonicalPayloadContainsOnlyTypedV2Meaning(): void
    {
        $plan = new ReadingRoundPlan(
            new UserId("target-user"),
            "work/source",
            ReadingRoundOutcome::Stopped,
            ReadingPeriod::ended(
                ReadingDate::year(2020),
                ReadingDate::month(2021, 3)
            )
        );

        self::assertSame([
            "target_kind" => "reading_round",
            "target_user_id" => "target-user",
            "work_source_id" => "work/source",
            "lifecycle" => "ended",
            "outcome" => "stopped",
            "period" => [
                "started_on" => ["year" => 2020, "month" => null, "day" => null],
                "finished_on" => ["year" => 2021, "month" => 3, "day" => null],
            ],
            "source" => null,
            "provenance" => "migration_imported",
        ], $plan->canonicalPayload());
    }

    #[DataProvider("invalidPlans")]
    public function testInvalidLifecycleDateAndSourceShapesFailClosed(
        ?ReadingRoundOutcome $outcome,
        ReadingPeriod $period,
        ?string $itemSourceId
    ): void {
        $this->expectException(ValidationException::class);
        new ReadingRoundPlan(
            new UserId("target-user"),
            "work/source",
            $outcome,
            $period,
            $itemSourceId
        );
    }

    /** @return iterable<string,array{?ReadingRoundOutcome,ReadingPeriod,?string}> */
    public static function invalidPlans(): iterable
    {
        yield "active without source" => [
            null,
            ReadingPeriod::active(ReadingDate::exact(2020, 1, 2)),
            null,
        ];
        yield "active with partial date" => [
            null,
            ReadingPeriod::active(ReadingDate::month(2020, 1)),
            "item/source",
        ];
        yield "ended without finish" => [
            ReadingRoundOutcome::Completed,
            new ReadingPeriod(null, null),
            null,
        ];
        yield "active shape carrying finish" => [
            null,
            ReadingPeriod::ended(null, ReadingDate::year(2020)),
            "item/source",
        ];
    }

    public function testPausedIsNotACanonicalOutcome(): void
    {
        self::assertNotContains(
            "paused",
            array_map(
                static fn (ReadingRoundOutcome $outcome): string =>
                    $outcome->value,
                ReadingRoundOutcome::cases()
            )
        );
    }

    public function testInvalidCalendarDateIsRejectedBeforePlanning(): void
    {
        $this->expectException(ValidationException::class);
        new ReadingDate(2021, 2, 29);
    }

    public function testImpossiblePeriodIsRejectedBeforePlanning(): void
    {
        $this->expectException(ValidationException::class);
        ReadingPeriod::ended(
            ReadingDate::exact(2021, 1, 1),
            ReadingDate::exact(2020, 12, 31)
        );
    }
}
