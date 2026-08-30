<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Reading\History\GetMyReadingHistoryForWorkService;
use Biblio\Core\Application\Reading\History\ReadingHistoryCursor;
use Biblio\Core\Application\Reading\History\ReadingHistoryEntry;
use Biblio\Core\Application\Reading\History\ReadingHistoryPage;
use Biblio\Core\Application\Reading\History\ReadingHistoryPageSize;
use Biblio\Core\Application\Reading\History\ReadingHistoryReadRepository;
use Biblio\Core\Application\Reading\History\ReadingHistorySourceType;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\AuthenticationException;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundOutcome;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ReadingHistoryReadModelTest extends TestCase
{
    public function testPageSizeUsesTheFixedDefaultAndMaximum(): void
    {
        self::assertSame(10, (new ReadingHistoryPageSize())->value());
        self::assertSame(50, (new ReadingHistoryPageSize(50))->value());

        foreach ([0, 51] as $invalid) {
            try {
                new ReadingHistoryPageSize($invalid);
                self::fail("Invalid Reading history page size was accepted.");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /** @return iterable<string, array{ReadingDate, int, int}> */
    public static function cursorDateCases(): iterable
    {
        yield "exact" => [ReadingDate::exact(2025, 3, 12), 20250312, 20250312];
        yield "month" => [ReadingDate::month(2025, 3), 20250301, 20250331];
        yield "year" => [ReadingDate::year(2024), 20240101, 20241231];
    }

    #[DataProvider("cursorDateCases")]
    public function testCursorPreservesFinishIntervalKeys(
        ReadingDate $date,
        int $earliest,
        int $latest
    ): void {
        $cursor = ReadingHistoryCursor::after(
            $date,
            new ReadingRoundId("round-cursor")
        );

        self::assertSame($earliest, $cursor->finishedEarliest());
        self::assertSame($latest, $cursor->finishedLatest());
        self::assertSame("round-cursor", $cursor->tieBreaker()->value());
    }

    /** @return iterable<string, array{int, int}> */
    public static function malformedCursorCases(): iterable
    {
        yield "invalid date" => [20250230, 20250230];
        yield "reverse interval" => [20250331, 20250301];
        yield "invented interval" => [20250302, 20250331];
        yield "cross year" => [20250101, 20261231];
        yield "out of range" => [9991231, 9991231];
    }

    #[DataProvider("malformedCursorCases")]
    public function testMalformedCursorIsTypedValidationFailure(
        int $earliest,
        int $latest
    ): void {
        $this->expectException(ValidationException::class);

        new ReadingHistoryCursor(
            $earliest,
            $latest,
            new ReadingRoundId("round-invalid-cursor")
        );
    }

    public function testServiceResolvesActorAndUsesDefaultPageSize(): void
    {
        $actor = new UserId("history-actor");
        $repository = new RecordingReadingHistoryRepository();
        $service = new GetMyReadingHistoryForWorkService(
            new ControllableAuthenticatedUser($actor),
            $repository
        );

        $page = $service->forWork(new WorkId("history-work"));

        self::assertSame([], $page->entries());
        self::assertSame("history-actor", $repository->userId?->value());
        self::assertSame("history-work", $repository->workId?->value());
        self::assertSame(10, $repository->pageSize?->value());
        self::assertNull($repository->cursor);
        self::assertSame(1, $repository->calls);
    }

    public function testUnauthenticatedServiceDoesNotQueryRepository(): void
    {
        $repository = new RecordingReadingHistoryRepository();
        $service = new GetMyReadingHistoryForWorkService(
            new ControllableAuthenticatedUser(),
            $repository
        );

        try {
            $service->forWork(new WorkId("history-work"));
            self::fail("Anonymous Reading history request was accepted.");
        } catch (AuthenticationException) {
            self::assertSame(0, $repository->calls);
        }
    }

    public function testEntryContractContainsOnlyAllowlistedHistoryValues(): void
    {
        $entry = new ReadingHistoryEntry(
            ReadingRoundOutcome::Stopped,
            ReadingDate::month(2025, 2),
            ReadingDate::year(2025),
            ReadingHistorySourceType::ExternalLoan,
            true
        );

        self::assertSame(ReadingRoundOutcome::Stopped, $entry->outcome());
        self::assertSame(2, $entry->startedOn()?->monthValue());
        self::assertNull($entry->finishedOn()->monthValue());
        self::assertSame(
            ReadingHistorySourceType::ExternalLoan,
            $entry->sourceType()
        );
        self::assertTrue($entry->historicalRegistration());

        $methods = array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(ReadingHistoryEntry::class))->getMethods(
                ReflectionMethod::IS_PUBLIC
            )
        );
        sort($methods);

        self::assertSame([
            "__construct",
            "finishedOn",
            "historicalRegistration",
            "outcome",
            "sourceType",
            "startedOn",
        ], $methods);
    }
}

final class RecordingReadingHistoryRepository implements
    ReadingHistoryReadRepository
{
    public ?UserId $userId = null;
    public ?WorkId $workId = null;
    public ?ReadingHistoryPageSize $pageSize = null;
    public ?ReadingHistoryCursor $cursor = null;
    public int $calls = 0;

    public function forUserAndWork(
        UserId $userId,
        WorkId $workId,
        ReadingHistoryPageSize $pageSize,
        ?ReadingHistoryCursor $cursor
    ): ReadingHistoryPage {
        ++$this->calls;
        $this->userId = $userId;
        $this->workId = $workId;
        $this->pageSize = $pageSize;
        $this->cursor = $cursor;

        return new ReadingHistoryPage([], null);
    }
}
