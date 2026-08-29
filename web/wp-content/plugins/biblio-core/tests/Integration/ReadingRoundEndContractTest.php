<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Application\Reading\FinishReadingRoundService;
use Biblio\Core\Application\Reading\ReadingRoundEnd;
use Biblio\Core\Application\Reading\StopReadingRoundService;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingRoundRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundClock;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundLifecycle;
use Biblio\Core\Reading\ReadingRoundOutcome;
use Biblio\Core\Reading\ReadingRoundStale;
use Biblio\Core\Reading\ReadingSource;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;

final readonly class EndContractFixedClock implements ReadingRoundClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            "2026-08-29 12:00:00.123456",
            new DateTimeZone("UTC")
        );
    }
}

final class ReadingRoundEndContractTest extends PersistenceIntegrationTestCase
{
    private UserId $user;
    private WorkId $work;
    private LibraryId $library;
    private WpdbReadingRoundRepository $rounds;
    private WpdbTransactionManager $transactions;
    private FinishReadingRoundService $finish;
    private StopReadingRoundService $stop;
    private int $nextRound = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new UserId("end-contract-reader");
        $this->work = new WorkId("end-contract-work");
        $this->library = new LibraryId("end-contract-library");
        $this->rounds = new WpdbReadingRoundRepository(
            $this->database,
            $this->tableNames
        );
        $this->transactions = new WpdbTransactionManager($this->database);

        (new CreateLibraryService(
            new WpdbLibraryRepository($this->database, $this->tableNames),
            new WpdbLibraryMembershipRepository(
                $this->database,
                $this->tableNames
            ),
            $this->classificationSeedEvolution(),
            $this->transactions
        ))->create(Library::privateLibrary($this->library), $this->user);
        (new WpdbWorkRepository(
            $this->database,
            $this->tableNames
        ))->add(new Work($this->work, "Reading Round end contract"));
        (new WpdbEditionRepository(
            $this->database,
            $this->tableNames
        ))->add(new Edition(new EditionId("end-contract-edition"), $this->work));

        $end = new ReadingRoundEnd(
            new ControllableAuthenticatedUser($this->user),
            $this->rounds,
            new EndContractFixedClock(),
            $this->transactions
        );
        $this->finish = new FinishReadingRoundService($end);
        $this->stop = new StopReadingRoundService($end);
    }

    public function testNormalFinishAndStopMutateOnce(): void
    {
        $completedRound = $this->activeRound();
        $stoppedRound = $this->activeRound();
        $completedOn = ReadingDate::exact(2026, 8, 20);
        $stoppedOn = ReadingDate::exact(2026, 8, 21);

        $completed = $this->finish->finish(
            $completedRound->id(),
            $completedRound->version(),
            $completedOn
        );
        $stopped = $this->stop->stop(
            $stoppedRound->id(),
            $stoppedRound->version(),
            $stoppedOn
        );

        $this->assertEndedTruth($completed, ReadingRoundOutcome::Completed, $completedOn);
        $this->assertEndedTruth($stopped, ReadingRoundOutcome::Stopped, $stoppedOn);
        self::assertSame(2, $completed->version()->value());
        self::assertSame(2, $stopped->version()->value());
    }

    public function testIdenticalStaleFinishAndStopAreNoOps(): void
    {
        $completedRound = $this->activeRound();
        $stoppedRound = $this->activeRound();
        $completedOn = ReadingDate::exact(2026, 8, 20);
        $stoppedOn = ReadingDate::exact(2026, 8, 21);
        $completed = $this->finish->finish(
            $completedRound->id(),
            $completedRound->version(),
            $completedOn
        );
        $stopped = $this->stop->stop(
            $stoppedRound->id(),
            $stoppedRound->version(),
            $stoppedOn
        );

        $completedRetry = $this->finish->finish(
            $completedRound->id(),
            $completedRound->version(),
            ReadingDate::exact(2026, 8, 20)
        );
        $stoppedRetry = $this->stop->stop(
            $stoppedRound->id(),
            $stoppedRound->version(),
            ReadingDate::exact(2026, 8, 21)
        );

        self::assertSame(2, $completedRetry->version()->value());
        self::assertSame(2, $stoppedRetry->version()->value());
        self::assertEquals($completed->updatedAt(), $completedRetry->updatedAt());
        self::assertEquals($stopped->updatedAt(), $stoppedRetry->updatedAt());
        $this->assertPersistedTruth($completed, ReadingRoundOutcome::Completed, $completedOn);
        $this->assertPersistedTruth($stopped, ReadingRoundOutcome::Stopped, $stoppedOn);
    }

    /** @return iterable<string, array{ReadingRoundOutcome, ReadingRoundOutcome, ReadingDate, ReadingDate}> */
    public static function divergentStaleIntentions(): iterable
    {
        yield "completed to stopped" => [
            ReadingRoundOutcome::Completed,
            ReadingRoundOutcome::Stopped,
            ReadingDate::exact(2026, 8, 20),
            ReadingDate::exact(2026, 8, 20),
        ];
        yield "stopped to completed" => [
            ReadingRoundOutcome::Stopped,
            ReadingRoundOutcome::Completed,
            ReadingDate::exact(2026, 8, 20),
            ReadingDate::exact(2026, 8, 20),
        ];
        yield "completed with another date" => [
            ReadingRoundOutcome::Completed,
            ReadingRoundOutcome::Completed,
            ReadingDate::exact(2026, 8, 20),
            ReadingDate::exact(2026, 8, 21),
        ];
        yield "stopped with another date" => [
            ReadingRoundOutcome::Stopped,
            ReadingRoundOutcome::Stopped,
            ReadingDate::exact(2026, 8, 20),
            ReadingDate::exact(2026, 8, 21),
        ];
    }

    #[DataProvider("divergentStaleIntentions")]
    public function testDivergentStaleEndIntentThrowsTypedStaleAndPreservesTruth(
        ReadingRoundOutcome $currentOutcome,
        ReadingRoundOutcome $requestedOutcome,
        ReadingDate $currentFinishedOn,
        ReadingDate $requestedFinishedOn
    ): void {
        $active = $this->activeRound();
        $ended = $this->end(
            $active,
            $currentOutcome,
            $currentFinishedOn
        );

        try {
            $this->end(
                $active,
                $requestedOutcome,
                $requestedFinishedOn
            );
            self::fail("A divergent stale end intention was accepted.");
        } catch (ReadingRoundStale $stale) {
            self::assertSame(2, $stale->current()->version()->value());
            $this->assertEndedTruth(
                $stale->current(),
                $currentOutcome,
                $currentFinishedOn
            );
        }

        $this->assertPersistedTruth(
            $ended,
            $currentOutcome,
            $currentFinishedOn
        );
    }

    /** @return iterable<string, array{ReadingRoundOutcome, ReadingRoundOutcome}> */
    public static function currentVersionLifecycleChanges(): iterable
    {
        yield "completed to stopped" => [
            ReadingRoundOutcome::Completed,
            ReadingRoundOutcome::Stopped,
        ];
        yield "stopped to completed" => [
            ReadingRoundOutcome::Stopped,
            ReadingRoundOutcome::Completed,
        ];
    }

    #[DataProvider("currentVersionLifecycleChanges")]
    public function testCurrentVersionOnEndedHistoryUsesLifecycleValidation(
        ReadingRoundOutcome $currentOutcome,
        ReadingRoundOutcome $requestedOutcome
    ): void {
        $active = $this->activeRound();
        $finishedOn = ReadingDate::exact(2026, 8, 20);
        $ended = $this->end($active, $currentOutcome, $finishedOn);

        try {
            $this->end(
                $ended,
                $requestedOutcome,
                $finishedOn
            );
            self::fail("An ended Reading Round changed outside correction.");
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        $this->assertPersistedTruth($ended, $currentOutcome, $finishedOn);
    }

    private function activeRound(): ReadingRound
    {
        $this->nextRound++;
        $suffix = (string) $this->nextRound;
        $item = Item::active(
            new ItemId("end-contract-item-{$suffix}"),
            $this->library,
            new EditionId("end-contract-edition")
        );
        (new WpdbItemRepository(
            $this->database,
            $this->tableNames
        ))->add($item);
        $round = ReadingRound::active(
            new ReadingRoundId("end-contract-round-{$suffix}"),
            $this->user,
            $this->work,
            ReadingSource::libraryItem($item->id()),
            ReadingDate::exact(2026, 8, 1),
            (new EndContractFixedClock())->now()
        );
        $this->transactions->run(function () use ($round): void {
            $this->rounds->addForUser($this->user, $round);
        });

        return $round;
    }

    private function end(
        ReadingRound $round,
        ReadingRoundOutcome $outcome,
        ReadingDate $finishedOn
    ): ReadingRound {
        return $outcome === ReadingRoundOutcome::Completed
            ? $this->finish->finish($round->id(), $round->version(), $finishedOn)
            : $this->stop->stop($round->id(), $round->version(), $finishedOn);
    }

    private function assertPersistedTruth(
        ReadingRound $expected,
        ReadingRoundOutcome $outcome,
        ReadingDate $finishedOn
    ): void {
        $persisted = $this->rounds->findForUser($expected->id(), $this->user);
        self::assertNotNull($persisted);
        self::assertSame($expected->version()->value(), $persisted->version()->value());
        $this->assertEndedTruth($persisted, $outcome, $finishedOn);
    }

    private function assertEndedTruth(
        ReadingRound $round,
        ReadingRoundOutcome $outcome,
        ReadingDate $finishedOn
    ): void {
        $actualFinishedOn = $round->period()->finishedOn();
        self::assertSame(ReadingRoundLifecycle::Ended, $round->lifecycle());
        self::assertSame($outcome, $round->outcome());
        self::assertNotNull($actualFinishedOn);
        self::assertTrue($finishedOn->equals($actualFinishedOn));
    }
}
