<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Catalog\Classification\Read\LibraryClassificationQueryService;
use Biblio\Core\Application\Library\{ActorLibraryContext,ActorLibraryContextRepository,LibraryContextQueryService};
use Biblio\Core\Application\Reading\GetPersonalWorkReadingStatusService;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\Classification\{LibraryClassificationReadRepository};
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\{Library,LibraryId,LibraryMembership,LibraryMembershipAssignment};
use Biblio\Core\Reading\{PersonalWorkReadingStatus,PersonalWorkReadingStatusSource,ReadingDate,ReadingPeriod,ReadingRound,ReadingRoundId};
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class ExistingSourceClassificationRepositoryStub implements LibraryClassificationReadRepository
{
    public int $calls = 0;

    public function activeBookTypes(LibraryId $libraryId): array { ++$this->calls; return []; }
    public function activeGenres(LibraryId $libraryId): array { ++$this->calls; return []; }
    public function activeSubjects(LibraryId $libraryId): array { ++$this->calls; return []; }
    public function classificationsForWorks(LibraryId $libraryId, array $workIds): array
    {
        ++$this->calls;
        return array_fill_keys(array_map(static fn (WorkId $id): string => $id->value(), $workIds), null);
    }
}

final class ExistingSourceActorLibraryContextRepositoryStub implements ActorLibraryContextRepository
{
    /** @param list<ActorLibraryContext> $records */
    public function __construct(private array $records) {}

    public function findForActor(LibraryId $libraryId, UserId $actorId): ?ActorLibraryContext
    {
        foreach ($this->records as $record) {
            if ($record->library()->id()->equals($libraryId) && $record->membership()->userId()->equals($actorId)) {
                return $record;
            }
        }
        return null;
    }

    public function listForActor(UserId $actorId): array { return []; }
}

final class ExistingSourceReadingStatusSourceStub implements PersonalWorkReadingStatusSource
{
    public ?UserId $queriedUser = null;

    /** @param array<string, list<ReadingRound>> $rounds */
    public function __construct(private array $rounds) {}

    public function findAllForUserAndWorks(UserId $userId, array $workIds): array
    {
        $this->queriedUser = $userId;
        $result = [];
        foreach ($workIds as $workId) {
            $result[$workId->value()] = $this->rounds[$workId->value()] ?? [];
        }
        return $result;
    }
}

final class ExistingSourceReadServicesTest extends TestCase
{
    public function testClassificationReadsAuthorizeBeforeRepositoryAccess(): void
    {
        $actorId = new UserId('actor');
        $libraryId = new LibraryId('library-a');
        $record = new ActorLibraryContext(
            Library::privateLibrary($libraryId),
            new LibraryMembershipAssignment($libraryId, $actorId, LibraryMembership::owner()),
            true
        );
        $repository = new ExistingSourceClassificationRepositoryStub();
        $service = new LibraryClassificationQueryService(
            new LibraryContextQueryService(
                new ControllableAuthenticatedUser($actorId),
                new ExistingSourceActorLibraryContextRepositoryStub([$record]),
                new LibraryAuthorizationPolicy()
            ),
            $repository
        );

        self::assertSame([], $service->activeBookTypes($libraryId));
        self::assertSame([], $service->activeGenres($libraryId));
        self::assertSame([], $service->activeSubjects($libraryId));
        self::assertSame(['work-a' => null], $service->classificationsForWorks($libraryId, [new WorkId('work-a')]));
        self::assertSame(4, $repository->calls);

        try {
            $service->activeGenres(new LibraryId('foreign-library'));
            self::fail('Foreign Library classification options were readable.');
        } catch (AuthorizationException) {
            self::assertSame(4, $repository->calls, 'Authorization must run before classification persistence.');
        }
    }

    public function testPersonalReadingStatusesAreBatchedForOnlyTheCurrentActor(): void
    {
        $actorId = new UserId('actor');
        $read = new WorkId('work-read');
        $reading = new WorkId('work-reading');
        $missing = new WorkId('work-missing');
        $now = new DateTimeImmutable('2026-09-04 10:00:00.123456', new DateTimeZone('UTC'));
        $source = new ExistingSourceReadingStatusSourceStub([
            $read->value() => [ReadingRound::historical(
                new ReadingRoundId('round-read'),
                $actorId,
                $read,
                ReadingPeriod::ended(null, ReadingDate::year(2025)),
                $now
            )],
            $reading->value() => [ReadingRound::legacyActive(
                new ReadingRoundId('round-reading'),
                $actorId,
                $reading,
                null,
                $now
            )],
        ]);
        $service = new GetPersonalWorkReadingStatusService(
            new ControllableAuthenticatedUser($actorId),
            $source
        );

        self::assertSame([
            'work-reading' => PersonalWorkReadingStatus::Reading,
            'work-read' => PersonalWorkReadingStatus::Read,
            'work-missing' => PersonalWorkReadingStatus::NotRead,
        ], $service->getMany([$reading, $read, $missing]));
        self::assertTrue($actorId->equals($source->queriedUser ?? new UserId('unexpected')));
        self::assertSame(PersonalWorkReadingStatus::Read, $service->get($read));
        self::assertSame([], $service->getMany([]));
    }

    public function testReadBatchesAreTypedAndBoundedAfterAuthorization(): void
    {
        $actorId = new UserId('actor');
        $libraryId = new LibraryId('library-a');
        $record = new ActorLibraryContext(
            Library::privateLibrary($libraryId),
            new LibraryMembershipAssignment($libraryId, $actorId, LibraryMembership::owner()),
            true
        );
        $classificationRepository = new ExistingSourceClassificationRepositoryStub();
        $classifications = new LibraryClassificationQueryService(
            new LibraryContextQueryService(
                new ControllableAuthenticatedUser($actorId),
                new ExistingSourceActorLibraryContextRepositoryStub([$record]),
                new LibraryAuthorizationPolicy()
            ),
            $classificationRepository
        );
        $readingSource = new ExistingSourceReadingStatusSourceStub([]);
        $statuses = new GetPersonalWorkReadingStatusService(
            new ControllableAuthenticatedUser($actorId),
            $readingSource
        );
        $oversized = array_map(static fn (int $number): WorkId => new WorkId("work-{$number}"), range(1, 101));

        foreach ([
            static fn () => $classifications->classificationsForWorks($libraryId, $oversized),
            static fn () => $statuses->getMany($oversized),
        ] as $operation) {
            try {
                $operation();
                self::fail('An invalid read batch was accepted.');
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(0, $classificationRepository->calls);
        self::assertNull($readingSource->queriedUser);
    }
}
