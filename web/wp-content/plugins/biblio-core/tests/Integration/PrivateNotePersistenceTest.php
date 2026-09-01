<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Notes\CorrectPrivateNoteReadingRoundService;
use Biblio\Core\Application\Notes\CreatePrivateNoteService;
use Biblio\Core\Application\Notes\DeletePrivateNoteService;
use Biblio\Core\Application\Notes\GetPrivateNoteService;
use Biblio\Core\Application\Notes\ListMyPrivateNotesService;
use Biblio\Core\Application\Notes\ListPrivateNotesForReadingRoundService;
use Biblio\Core\Application\Notes\ListPrivateNotesForWorkService;
use Biblio\Core\Application\Notes\PrivateNoteCreation;
use Biblio\Core\Application\Notes\Read\GetMyPrivateNotesForWorkService;
use Biblio\Core\Application\Notes\Read\PrivateNoteView;
use Biblio\Core\Application\Notes\RenderPrivateNoteContentService;
use Biblio\Core\Application\Notes\UpdatePrivateNoteContentService;
use Biblio\Core\Application\Reading\DeleteHistoricalReadingRoundService;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPrivateNoteRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingRoundRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Notes\PrivateNote;
use Biblio\Core\Notes\PrivateNoteClock;
use Biblio\Core\Notes\PrivateNoteId;
use Biblio\Core\Notes\PrivateNoteIdCollisionExhausted;
use Biblio\Core\Notes\PrivateNoteIdGenerator;
use Biblio\Core\Notes\PrivateNoteNotAvailable;
use Biblio\Core\Notes\PrivateNotePageRequest;
use Biblio\Core\Notes\PrivateNoteReadingRoundUnavailable;
use Biblio\Core\Notes\PrivateNoteStale;
use Biblio\Core\Notes\PrivateNoteVersion;
use Biblio\Core\Notes\StrictPrivateNoteContentPolicy;
use Biblio\Core\Reading\ReadingRoundDeletionNotAllowed;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundVersion;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use DateTimeImmutable;

final class QueuePrivateNoteIdGenerator implements PrivateNoteIdGenerator
{
    /** @param list<string> $ids */
    public function __construct(private array $ids) {}

    public function next(): PrivateNoteId
    {
        return new PrivateNoteId(array_shift($this->ids) ?? 'unexpected-id');
    }
}

final class QueuePrivateNoteClock implements PrivateNoteClock
{
    /** @param list<string> $instants */
    public function __construct(private array $instants) {}

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            array_shift($this->instants) ?? '2026-08-22T20:00:00+00:00'
        );
    }
}

final class PrivateNotePersistenceTest extends PersistenceIntegrationTestCase
{
    private ControllableAuthenticatedUser $actor;
    private WpdbPrivateNoteRepository $notes;
    private WpdbReadingRoundRepository $rounds;
    private WpdbWorkRepository $works;
    private WpdbTransactionManager $transactions;
    private StrictPrivateNoteContentPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = new ControllableAuthenticatedUser(new UserId('user-a'));
        $this->policy = new StrictPrivateNoteContentPolicy();
        $this->notes = new WpdbPrivateNoteRepository(
            $this->database,
            $this->tableNames,
            $this->policy
        );
        $this->rounds = new WpdbReadingRoundRepository(
            $this->database,
            $this->tableNames
        );
        $this->works = new WpdbWorkRepository($this->database, $this->tableNames);
        $this->transactions = new WpdbTransactionManager($this->database);
        $this->works->add(new Work(new WorkId('work-a'), 'Werk A'));
        $this->works->add(new Work(new WorkId('work-b'), 'Werk B'));
        $this->insertRound('round-active', 'user-a', 'work-a', null, 'source_started');
        $this->insertRound('round-ended', 'user-a', 'work-a', 'completed', 'source_started');
        $this->insertRound('round-history', 'user-a', 'work-a', 'completed', 'historical_manual');
        $this->insertRound('round-other-work', 'user-a', 'work-b', 'completed', 'historical_manual');
        $this->insertRound('round-foreign', 'user-b', 'work-a', 'completed', 'historical_manual');
        self::assertSame(5, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->readingRounds()}`"
        ));
        self::assertSame([
            'round-active', 'round-ended', 'round-foreign', 'round-history',
            'round-other-work',
        ], $this->database->get_col(
            "SELECT reading_round_id FROM `{$this->tableNames->readingRounds()}` "
            . "ORDER BY reading_round_id"
        ));
        self::assertSame('user-a', $this->database->get_var(
            $this->database->prepare(
                "SELECT user_id FROM `{$this->tableNames->readingRounds()}` "
                . "WHERE reading_round_id = %s",
                'round-active'
            )
        ));
        self::assertSame('user-a', $this->database->get_var(
            $this->database->prepare(
                "SELECT user_id FROM `{$this->tableNames->readingRounds()}` "
                . "WHERE reading_round_id = %s AND user_id = %s",
                'round-active',
                'user-a'
            )
        ));
        $foundRound = $this->rounds->findForUser(
            new ReadingRoundId('round-active'),
            new UserId('user-a')
        );
        self::assertNotNull($foundRound, $this->database->last_error);
    }

    public function testCreateAndAllOwnerScopedProjectionsSupportMultiplicityAndEveryRoundState(): void
    {
        $create = $this->createService([
            'note-work', 'note-active-1', 'note-active-2', 'note-ended',
            'note-history',
        ]);
        $unlinked = $create->createForWork(new WorkId('work-a'), '<p>Los</p>');
        $activeOne = $create->createForReadingRound(
            new ReadingRoundId('round-active'),
            '<p><strong>Actief</strong></p>'
        );
        $activeTwo = $create->createForReadingRound(
            new ReadingRoundId('round-active'),
            '<p>Tweede</p>'
        );
        $create->createForReadingRound(
            new ReadingRoundId('round-ended'),
            '<p>Ended</p>'
        );
        $create->createForReadingRound(
            new ReadingRoundId('round-history'),
            '<p>Historisch</p>'
        );

        self::assertNull($unlinked->readingRoundId());
        self::assertTrue($activeOne->workId()->equals(new WorkId('work-a')));
        self::assertSame(1, $activeTwo->version()->value());
        self::assertCount(5, (new ListPrivateNotesForWorkService(
            $this->actor,
            $this->notes
        ))->list(new WorkId('work-a'))->notes());
        self::assertCount(2, (new ListPrivateNotesForReadingRoundService(
            $this->actor,
            $this->notes,
            $this->rounds
        ))->list(new ReadingRoundId('round-active'))->notes());
        self::assertCount(5, (new ListMyPrivateNotesService(
            $this->actor,
            $this->notes
        ))->list()->notes());

        $memberRead = new GetPrivateNoteService($this->actor, $this->notes);
        self::assertSame(
            $activeOne->id()->value(),
            $memberRead->get($activeOne->id())?->id()->value()
        );
        self::assertNull($memberRead->get(new PrivateNoteId('note-unknown')));

        $this->actor->authenticateAs(new UserId('user-b'));
        self::assertNull((new GetPrivateNoteService(
            $this->actor,
            $this->notes
        ))->get($activeOne->id()));
        self::assertSame([], (new ListPrivateNotesForWorkService(
            $this->actor,
            $this->notes
        ))->list(new WorkId('work-a'))->notes());
        self::assertSame([], (new ListPrivateNotesForReadingRoundService(
            $this->actor,
            $this->notes,
            $this->rounds
        ))->list(new ReadingRoundId('round-active'))->notes());
    }

    public function testContentAndContextMutationsUseCasNoOpAndImmutableWork(): void
    {
        $note = $this->createService(['note-cas'])->createForWork(
            new WorkId('work-a'),
            '<p>Eerste</p>'
        );
        $contentUpdate = new UpdatePrivateNoteContentService(
            $this->actor,
            $this->notes,
            $this->policy,
            new QueuePrivateNoteClock([
                '2026-08-22T11:00:00+00:00',
                '2026-08-22T12:00:00+00:00',
            ]),
            $this->transactions
        );
        $updated = $contentUpdate->update(
            $note->id(),
            PrivateNoteVersion::initial(),
            '<p>Tweede</p>'
        );
        $noOp = $contentUpdate->update(
            $note->id(),
            PrivateNoteVersion::initial(),
            '<p>Tweede</p>'
        );

        self::assertSame(2, $updated->version()->value());
        self::assertSame(2, $noOp->version()->value());

        try {
            $contentUpdate->update(
                $note->id(),
                PrivateNoteVersion::initial(),
                '<p>Stale</p>'
            );
            self::fail('Divergent stale update succeeded.');
        } catch (PrivateNoteStale $stale) {
            self::assertSame(2, $stale->current()->version()->value());
        }

        $context = new CorrectPrivateNoteReadingRoundService(
            $this->actor,
            $this->notes,
            $this->rounds,
            new QueuePrivateNoteClock([
                '2026-08-22T13:00:00+00:00',
                '2026-08-22T14:00:00+00:00',
            ]),
            $this->transactions
        );
        $attached = $context->correct(
            $note->id(),
            new PrivateNoteVersion(2),
            new ReadingRoundId('round-ended')
        );
        self::assertSame('round-ended', $attached->readingRoundId()?->value());
        $contextNoOp = $context->correct(
            $note->id(),
            new PrivateNoteVersion(2),
            new ReadingRoundId('round-ended')
        );
        self::assertSame(3, $contextNoOp->version()->value());
        $removed = $context->correct(
            $note->id(),
            new PrivateNoteVersion(3),
            null
        );
        self::assertNull($removed->readingRoundId());
        self::assertSame('work-a', $removed->workId()->value());
        self::assertSame(4, $removed->version()->value());

        foreach (['round-other-work', 'round-foreign'] as $invalidRound) {
            try {
                $context->correct(
                    $note->id(),
                    new PrivateNoteVersion(4),
                    new ReadingRoundId($invalidRound)
                );
                self::fail('Invalid context succeeded.');
            } catch (PrivateNoteReadingRoundUnavailable $failure) {
                self::assertSame(
                    FailureReason::PrivateNoteReadingRoundUnavailable,
                    $failure->reason()
                );
            }
        }

        self::assertSame(4, $this->notes->findForUser(
            $note->id(),
            new UserId('user-a')
        )?->version()->value());
    }

    public function testHardDeleteIsOwnerAndVersionScopedAndNeverDeletesReadingData(): void
    {
        $note = $this->createService(['note-delete'])->createForReadingRound(
            new ReadingRoundId('round-ended'),
            '<p>Delete</p>'
        );
        $delete = new DeletePrivateNoteService(
            $this->actor,
            $this->notes,
            $this->transactions
        );

        try {
            $delete->delete($note->id(), new PrivateNoteVersion(2));
            self::fail('Stale delete succeeded.');
        } catch (PrivateNoteStale) {
            self::addToAssertionCount(1);
        }

        $this->actor->authenticateAs(new UserId('user-b'));

        try {
            $delete->delete($note->id(), PrivateNoteVersion::initial());
            self::fail('Foreign delete succeeded.');
        } catch (PrivateNoteNotAvailable) {
            self::addToAssertionCount(1);
        }

        $this->actor->authenticateAs(new UserId('user-a'));
        $delete->delete($note->id(), PrivateNoteVersion::initial());
        self::assertNull($this->notes->findForUser($note->id(), new UserId('user-a')));

        foreach ([$note->id(), new PrivateNoteId('note-unknown')] as $unavailableId) {
            try {
                $delete->delete($unavailableId, PrivateNoteVersion::initial());
                self::fail('Unavailable Private Note deletion succeeded.');
            } catch (PrivateNoteNotAvailable) {
                self::addToAssertionCount(1);
            }
        }

        self::assertNotNull($this->works->find(new WorkId('work-a')));
        self::assertNotNull($this->rounds->findForUser(
            new ReadingRoundId('round-ended'),
            new UserId('user-a')
        ));
    }

    public function testHistoricalRoundDeleteNullsOnlyContextAndDeniedDeleteChangesNothing(): void
    {
        $note = $this->createService(['note-round-delete'])->createForReadingRound(
            new ReadingRoundId('round-history'),
            '<p>Blijft</p>'
        );
        $before = $this->notes->findForUser($note->id(), new UserId('user-a'));
        $roundDelete = new DeleteHistoricalReadingRoundService(
            $this->actor,
            $this->rounds,
            $this->transactions
        );
        $roundDelete->delete(
            new ReadingRoundId('round-history'),
            ReadingRoundVersion::initial()
        );
        $after = $this->notes->findForUser($note->id(), new UserId('user-a'));

        self::assertNotNull($after);
        self::assertNull($after->readingRoundId());
        self::assertSame($before?->content()->value(), $after->content()->value());
        self::assertSame($before?->workId()->value(), $after->workId()->value());
        self::assertSame($before?->version()->value(), $after->version()->value());
        self::assertSame(
            $before?->updatedAt()->format('Y-m-d H:i:s.u'),
            $after->updatedAt()->format('Y-m-d H:i:s.u')
        );

        $activeNote = $this->createService(['note-active-delete'])->createForReadingRound(
            new ReadingRoundId('round-active'),
            '<p>Actief</p>'
        );

        try {
            $roundDelete->delete(
                new ReadingRoundId('round-active'),
                ReadingRoundVersion::initial()
            );
            self::fail('Active round deletion succeeded.');
        } catch (ReadingRoundDeletionNotAllowed) {
            self::assertSame(
                'round-active',
                $this->notes->findForUser(
                    $activeNote->id(),
                    new UserId('user-a')
                )?->readingRoundId()?->value()
            );
        }
    }

    public function testIdCollisionRetriesAreBoundedAndNoEventsAreWritten(): void
    {
        $eventsBefore = (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->libraryActivityEvents()}`"
        );
        $seed = $this->createService(['collision'])->createForWork(
            new WorkId('work-a'),
            '<p>Seed</p>'
        );
        self::assertSame('collision', $seed->id()->value());
        $retried = $this->createService(['collision', 'unique'])->createForWork(
            new WorkId('work-a'),
            '<p>Retry</p>'
        );
        self::assertSame('unique', $retried->id()->value());

        try {
            $this->createService([
                'collision', 'collision', 'collision', 'collision',
            ])->createForWork(new WorkId('work-a'), '<p>Exhaust</p>');
            self::fail('Collision exhaustion succeeded.');
        } catch (PrivateNoteIdCollisionExhausted $failure) {
            self::assertSame(
                FailureReason::PrivateNoteIdCollisionExhausted,
                $failure->reason()
            );
        }

        self::assertSame($eventsBefore, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->libraryActivityEvents()}`"
        ));
    }

    public function testRepositoryDefensePaginationAndForeignKeys(): void
    {
        $create = $this->createService(['page-1', 'page-2', 'page-3']);
        $create->createForWork(new WorkId('work-a'), '<p>Een</p>');
        $create->createForWork(new WorkId('work-a'), '<p>Twee</p>');
        $create->createForWork(new WorkId('work-a'), '<p>Drie</p>');
        $first = (new ListMyPrivateNotesService(
            $this->actor,
            $this->notes
        ))->list(new PrivateNotePageRequest(2));

        self::assertCount(2, $first->notes());
        self::assertTrue($first->hasMore());
        $last = $first->notes()[1];
        $second = (new ListMyPrivateNotesService(
            $this->actor,
            $this->notes
        ))->list(new PrivateNotePageRequest(
            2,
            $last->updatedAt(),
            $last->id()
        ));
        self::assertCount(1, $second->notes());
        self::assertFalse($second->hasMore());

        $invalid = PrivateNote::create(
            new PrivateNoteId('invalid-context'),
            new UserId('user-a'),
            new WorkId('work-a'),
            new ReadingRoundId('round-other-work'),
            $this->policy->sanitize('<p>Fout</p>'),
            new DateTimeImmutable('2026-08-22T16:00:00+00:00')
        );

        try {
            $this->transactions->run(
                fn () => $this->notes->addForUser(new UserId('user-a'), $invalid)
            );
            self::fail('Persistence accepted cross-Work context.');
        } catch (PersistenceException $failure) {
            self::assertSame(FailureReason::PersistenceWriteFailed, $failure->reason());
        }

        $previousSuppression = $this->database->suppress_errors(true);
        try {
            $unknownWorkResult = $this->database->query($this->database->prepare(
                "INSERT INTO `{$this->tableNames->privateNotes()}` "
                . "(private_note_id, user_id, work_id, reading_round_id, note_content, "
                . "created_at, updated_at, note_version) VALUES (%s,%s,%s,NULL,%s,%s,%s,%d)",
                'bad-fk', 'user-a', 'missing-work', '<p>Bad</p>',
                '2026-08-22 10:00:00.000000', '2026-08-22 10:00:00.000000', 1
            ));
            $unknownRoundResult = $this->database->query($this->database->prepare(
                "INSERT INTO `{$this->tableNames->privateNotes()}` "
                . "(private_note_id, user_id, work_id, reading_round_id, note_content, "
                . "created_at, updated_at, note_version) VALUES (%s,%s,%s,%s,%s,%s,%s,%d)",
                'bad-round-fk', 'user-a', 'work-a', 'missing-round', '<p>Bad</p>',
                '2026-08-22 10:00:00.000000', '2026-08-22 10:00:00.000000', 1
            ));
        } finally {
            $this->database->suppress_errors($previousSuppression);
        }
        self::assertFalse($unknownWorkResult);
        self::assertFalse($unknownRoundResult);
    }

    public function testAdapterReadBoundaryIsOneQueryOwnerWorkBoundedAndDeterministic(): void
    {
        $create = $this->createService([
            'view-note-z', 'view-note-m', 'view-note-a', 'view-other-work',
        ]);
        $create->createForWork(new WorkId('work-a'), '<p><strong>Z</strong></p>');
        $create->createForWork(new WorkId('work-a'), '<p>M</p>');
        $create->createForWork(new WorkId('work-a'), '<blockquote>A</blockquote>');
        $create->createForWork(new WorkId('work-b'), '<p>Andere Work</p>');
        $this->actor->authenticateAs(new UserId('user-b'));
        $this->createService(['view-foreign'])->createForWork(
            new WorkId('work-a'),
            '<p>Vreemd</p>'
        );
        $this->actor->authenticateAs(new UserId('user-a'));

        $table = $this->tableNames->privateNotes();
        foreach ([
            'view-note-z' => '2026-08-31 12:00:00.200000',
            'view-note-m' => '2026-08-31 12:00:00.200000',
            'view-note-a' => '2026-08-31 12:00:00.100000',
            'view-other-work' => '2026-08-31 13:00:00.000000',
            'view-foreign' => '2026-08-31 14:00:00.000000',
        ] as $id => $updatedAt) {
            self::assertSame(1, $this->database->query($this->database->prepare(
                "UPDATE `{$table}` SET updated_at = %s WHERE private_note_id = %s",
                $updatedAt,
                $id
            )));
        }

        $service = new GetMyPrivateNotesForWorkService(
            $this->actor,
            $this->notes,
            new RenderPrivateNoteContentService($this->policy)
        );
        $beforeFirst = $this->database->num_queries;
        $first = $service->forWork(
            new WorkId('work-a'),
            new PrivateNotePageRequest(2)
        );

        self::assertSame(1, $this->database->num_queries - $beforeFirst);
        self::assertSame(
            ['view-note-z', 'view-note-m'],
            array_map(
                static fn (PrivateNoteView $view): string => $view->id()->value(),
                $first->notes()
            )
        );
        self::assertSame(
            ['<p><strong>Z</strong></p>', '<p>M</p>'],
            array_map(
                static fn (PrivateNoteView $view): string => $view->contentHtml(),
                $first->notes()
            )
        );
        self::assertSame(
            '2026-08-31 12:00:00.200000',
            $first->nextCursor()?->beforeUpdatedAt()->format('Y-m-d H:i:s.u')
        );
        self::assertSame('view-note-m', $first->nextCursor()?->beforeId()->value());

        $cursor = $first->nextCursor();
        self::assertNotNull($cursor);
        $beforeSecond = $this->database->num_queries;
        $second = $service->forWork(
            new WorkId('work-a'),
            new PrivateNotePageRequest(
                2,
                $cursor->beforeUpdatedAt(),
                $cursor->beforeId()
            )
        );

        self::assertSame(1, $this->database->num_queries - $beforeSecond);
        self::assertSame(
            ['view-note-a'],
            array_map(
                static fn (PrivateNoteView $view): string => $view->id()->value(),
                $second->notes()
            )
        );
        self::assertNull($second->nextCursor());
        self::assertSame(3, count(array_unique(array_merge(
            array_map(
                static fn (PrivateNoteView $view): string => $view->id()->value(),
                $first->notes()
            ),
            array_map(
                static fn (PrivateNoteView $view): string => $view->id()->value(),
                $second->notes()
            )
        ))));

        $beforeZero = $this->database->num_queries;
        $zero = $service->forWork(new WorkId('work-without-notes'));
        self::assertSame(1, $this->database->num_queries - $beforeZero);
        self::assertSame([], $zero->notes());
        self::assertNull($zero->nextCursor());
    }

    public function testInvalidStoredContentCannotReachAdapterReadView(): void
    {
        $note = $this->createService(['view-compromised'])->createForWork(
            new WorkId('work-a'),
            '<p>Veilig</p>'
        );
        self::assertSame(1, $this->database->update(
            $this->tableNames->privateNotes(),
            ['note_content' => '<p onclick="alert(1)">Onveilig</p>'],
            ['private_note_id' => $note->id()->value()],
            ['%s'],
            ['%s']
        ));
        $service = new GetMyPrivateNotesForWorkService(
            $this->actor,
            $this->notes,
            new RenderPrivateNoteContentService($this->policy)
        );

        try {
            $service->forWork(new WorkId('work-a'));
            self::fail('Invalid stored Private Note content reached the view.');
        } catch (PersistenceException $failure) {
            self::assertSame(FailureReason::PersistenceReadFailed, $failure->reason());
        }
    }

    /** @param list<string> $ids */
    private function createService(array $ids): CreatePrivateNoteService
    {
        return new CreatePrivateNoteService(
            $this->actor,
            $this->works,
            $this->rounds,
            $this->policy,
            new PrivateNoteCreation(
                new QueuePrivateNoteIdGenerator($ids),
                $this->notes
            ),
            new QueuePrivateNoteClock([
                '2026-08-22T10:00:00.100001+00:00',
                '2026-08-22T10:00:00.100002+00:00',
                '2026-08-22T10:00:00.100003+00:00',
                '2026-08-22T10:00:00.100004+00:00',
                '2026-08-22T10:00:00.100005+00:00',
            ]),
            $this->transactions
        );
    }

    private function insertRound(
        string $id,
        string $userId,
        string $workId,
        ?string $outcome,
        string $provenance
    ): void {
        $ended = $outcome !== null;
        $historical = $provenance === 'historical_manual';
        $result = $this->database->insert($this->tableNames->readingRounds(), [
            'reading_round_id' => $id,
            'user_id' => $userId,
            'work_id' => $workId,
            'item_id' => null,
            'external_loan_id' => null,
            'started_at' => null,
            'round_outcome' => $outcome,
            'provenance' => $provenance,
            'reading_started_year' => $historical ? null : 2026,
            'reading_started_month' => $historical ? null : 8,
            'reading_started_day' => $historical ? null : 22,
            'reading_finished_year' => $ended ? 2026 : null,
            'reading_finished_month' => $ended ? 8 : null,
            'reading_finished_day' => $ended ? 22 : null,
            'created_at' => '2026-08-22 09:00:00.000000',
            'updated_at' => '2026-08-22 09:00:00.000000',
            'ended_at' => $ended ? '2026-08-22 09:00:00.000000' : null,
            'round_version' => 1,
        ], [
            '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
            '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d',
        ]);
        self::assertSame(1, $result, $this->database->last_error);
    }
}
