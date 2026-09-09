<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\AuthenticationException;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingRoundRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Reading\PersonalReadingTruthContradiction;
use Biblio\Core\Reading\PersonalReadingTruthState;
use Biblio\Core\Reading\PersonalWorkReadingStatus;
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingPeriod;
use Biblio\Core\Reading\ReadingSequenceClassification;
use RuntimeException;

final class PersonalReadingTruthTest extends PersistenceIntegrationTestCase
{
    /** @var list<int> */
    private array $createdUsers = [];
    private int $previousUserId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousUserId = get_current_user_id();
        $this->seedWork('truth-work-a');
        $this->seedWork('truth-work-b');
    }

    protected function tearDown(): void
    {
        wp_set_current_user($this->previousUserId);
        foreach ($this->createdUsers as $userId) {
            $this->database->delete($this->database->usermeta, ['user_id' => $userId], ['%d']);
            $this->database->delete($this->database->users, ['ID' => $userId], ['%d']);
        }
        parent::tearDown();
    }

    public function testSourceNeutralStatesAreIdempotentVersionedAndOwnerScoped(): void
    {
        $userA = $this->createUser('truth-a');
        $userB = $this->createUser('truth-b');
        $work = new WorkId('truth-work-a');
        $application = (new ProductionComposition($this->database))->application();

        wp_set_current_user($userA);
        $initial = $application->personalReadingTruthRecording()->record(
            $work,
            PersonalReadingTruthState::ReadKnownDateUnknown
        );
        $same = $application->personalReadingTruthRecording()->record(
            $work,
            PersonalReadingTruthState::ReadKnownDateUnknown
        );
        self::assertSame(1, $initial->version()->value());
        self::assertSame(1, $same->version()->value());
        self::assertSame(1, $this->truthCount());
        self::assertSame(PersonalWorkReadingStatus::Read, $application->personalWorkReadingStatus()->get($work));
        self::assertFalse($application->personalWorkReadingStatus()->getDetails($work)->readDateKnown());

        $changed = $application->personalReadingTruthRecording()->record(
            $work,
            PersonalReadingTruthState::Unknown
        );
        self::assertSame(2, $changed->version()->value());
        self::assertSame(PersonalWorkReadingStatus::Unknown, $application->personalWorkReadingStatus()->get($work));

        wp_set_current_user($userB);
        self::assertSame(PersonalWorkReadingStatus::NotRead, $application->personalWorkReadingStatus()->get($work));
        $application->personalReadingTruthRecording()->record(
            $work,
            PersonalReadingTruthState::ExplicitNotRead
        );
        self::assertSame(2, $this->truthCount());

        wp_set_current_user($userA);
        self::assertSame(PersonalWorkReadingStatus::Unknown, $application->personalWorkReadingStatus()->get($work));
    }

    public function testMarkerSurvivesConcreteRoundsAndMakesThemRereadsWithoutCreatingRoundCount(): void
    {
        $user = $this->createUser('truth-sequence');
        $work = new WorkId('truth-work-a');
        $application = (new ProductionComposition($this->database))->application();
        wp_set_current_user($user);

        $application->personalReadingTruthRecording()->record(
            $work,
            PersonalReadingTruthState::ReadKnownDateUnknown
        );
        self::assertSame(0, $this->roundCount());
        self::assertSame([], $application->readingSequence()->forWork($work));

        $round = $application->historicalReadingRounds()->register(
            $work,
            ReadingPeriod::ended(
                ReadingDate::month(2026, 8),
                ReadingDate::exact(2026, 9, 1)
            )
        );
        self::assertSame(1, $this->roundCount());
        self::assertSame(1, $this->truthCount());
        self::assertTrue($application->personalWorkReadingStatus()->getDetails($work)->readDateKnown());
        self::assertSame(
            ReadingSequenceClassification::Reread,
            $application->readingSequence()->forWork($work)[0]->classification()
        );

        $application->historicalReadingRoundDeletion()->delete(
            $round->id(),
            $round->version()
        );
        self::assertSame(0, $this->roundCount());
        self::assertSame(1, $this->truthCount());
        self::assertSame(PersonalWorkReadingStatus::Read, $application->personalWorkReadingStatus()->get($work));
        self::assertFalse($application->personalWorkReadingStatus()->getDetails($work)->readDateKnown());
    }

    public function testExplicitNotReadContradictionFailsClosedWithoutOverwritingMarker(): void
    {
        $user = $this->createUser('truth-contradiction');
        $work = new WorkId('truth-work-a');
        $application = (new ProductionComposition($this->database))->application();
        wp_set_current_user($user);
        $application->personalReadingTruthRecording()->record(
            $work,
            PersonalReadingTruthState::ReadKnownDateUnknown
        );
        $application->historicalReadingRounds()->register(
            $work,
            ReadingPeriod::ended(null, ReadingDate::year(2025))
        );

        try {
            $application->personalReadingTruthRecording()->record(
                $work,
                PersonalReadingTruthState::ExplicitNotRead
            );
            self::fail('Contradictory explicit-not-read truth was accepted.');
        } catch (PersonalReadingTruthContradiction) {
            self::assertSame('read_known_date_unknown', $this->storedTruthState($user, $work));
            self::assertSame(1, $this->truthCount());
        }
    }

    public function testNormalWriteRequiresAuthenticationActiveUserAndExistingWork(): void
    {
        $user = $this->createUser('truth-validation');
        $application = (new ProductionComposition($this->database))->application();
        wp_set_current_user(0);
        try {
            $application->personalReadingTruthRecording()->record(
                new WorkId('truth-work-a'),
                PersonalReadingTruthState::Unknown
            );
            self::fail('Anonymous truth write was accepted.');
        } catch (AuthenticationException) {
            self::assertSame(0, $this->truthCount());
        }

        wp_set_current_user($user);
        $this->expectException(ValidationException::class);
        $application->personalReadingTruthRecording()->record(
            new WorkId('truth-missing-work'),
            PersonalReadingTruthState::Unknown
        );
    }

    public function testInactivePlatformUserFailsClosed(): void
    {
        $user = $this->createUser('truth-inactive');
        wp_set_current_user($user);
        self::assertSame(1, $this->database->update(
            $this->database->users,
            ['user_status' => 1],
            ['ID' => $user],
            ['%d'],
            ['%d']
        ));
        clean_user_cache($user);

        $this->expectException(ValidationException::class);
        (new ProductionComposition($this->database))
            ->application()
            ->personalReadingTruthRecording()
            ->record(
                new WorkId('truth-work-a'),
                PersonalReadingTruthState::Unknown
            );
    }

    public function testCompletedRoundEvidenceReadFailureFailsClosed(): void
    {
        $table = $this->tableNames->readingRounds();
        $unavailable = $table . "_read_failure";
        self::assertNotFalse($this->database->query(
            "RENAME TABLE `{$table}` TO `{$unavailable}`"
        ), $this->database->last_error);

        try {
            $repository = new WpdbReadingRoundRepository(
                $this->database,
                $this->tableNames
            );
            try {
                (new WpdbTransactionManager($this->database))->run(
                    fn (): bool => $repository
                        ->hasCompletedForUserAndWorkForUpdate(
                            new UserId("truth-read-failure-user"),
                            new WorkId("truth-work-a")
                        )
                );
                self::fail("Completed-round evidence read failure was accepted.");
            } catch (PersistenceException $failure) {
                self::assertSame(
                    FailureReason::PersistenceReadFailed,
                    $failure->reason()
                );
            }
        } finally {
            self::assertNotFalse($this->database->query(
                "RENAME TABLE `{$unavailable}` TO `{$table}`"
            ), $this->database->last_error);
        }
    }

    private function createUser(string $prefix): int
    {
        $suffix = bin2hex(random_bytes(4));
        $userId = wp_create_user(
            $prefix . '-' . $suffix,
            'synthetic-test-password',
            $prefix . '-' . $suffix . '@example.invalid'
        );
        if (!is_int($userId)) {
            throw new RuntimeException('Could not create synthetic truth test user.');
        }
        $this->createdUsers[] = $userId;
        return $userId;
    }

    private function seedWork(string $workId): void
    {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->works(),
            ['work_id' => $workId, 'work_title' => 'Synthetic truth Work']
        ), $this->database->last_error);
    }

    private function truthCount(): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->personalReadingTruths()}`"
        );
    }

    private function roundCount(): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->readingRounds()}`"
        );
    }

    private function storedTruthState(int $userId, WorkId $workId): string
    {
        return (string) $this->database->get_var($this->database->prepare(
            "SELECT truth_state FROM `{$this->tableNames->personalReadingTruths()}` WHERE user_id=%s AND work_id=%s",
            (string) $userId,
            $workId->value()
        ));
    }
}
