<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Reading\GetPersonalWorkReadingStatusService;
use Biblio\Core\Catalog\Classification\{ClassificationNameNormalizer,ClassificationTermName,ClassificationTermStatus,LibraryBookType,LibraryBookTypeId,LibraryCatalogContext,LibraryCatalogSelection,LibraryGenre,LibraryGenreId,LibrarySubject,LibrarySubjectId};
use Biblio\Core\Catalog\{Work,WorkId};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{WpdbLibraryBookTypeRepository,WpdbLibraryCatalogContextRepository,WpdbLibraryClassificationReadRepository,WpdbLibraryGenreRepository,WpdbLibraryRepository,WpdbLibrarySubjectRepository,WpdbReadingRoundRepository,WpdbTransactionManager,WpdbWorkRepository};
use Biblio\Core\Library\{Library,LibraryId};
use Biblio\Core\Reading\{PersonalWorkReadingStatus,ReadingDate,ReadingPeriod,ReadingRound,ReadingRoundId};
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use DateTimeImmutable;
use DateTimeZone;

final class ExistingSourceReadFoundationTest extends PersistenceIntegrationTestCase
{
    public function testClassificationOptionsAndBatchContextsAreTenantScopedDeterministicAndBounded(): void
    {
        $libraryA = $this->addLibrary('library-a');
        $libraryB = $this->addLibrary('library-b');
        $workA = $this->addWork('work-a', 'Alpha');
        $workMissing = $this->addWork('work-missing', 'Missing');
        $workForeign = $this->addWork('work-foreign', 'Foreign');
        $normalizer = ClassificationNameNormalizer::create();

        $bookA = $this->bookType($libraryA, 'book-a', 'Leesboek', $normalizer);
        $genreA = $this->genre($libraryA, 'genre-a', 'Avontuur', ClassificationTermStatus::Active, $normalizer);
        $genreInactive = $this->genre($libraryA, 'genre-inactive', 'Historisch', ClassificationTermStatus::Inactive, $normalizer);
        $subjectA = $this->subject($libraryA, 'subject-a', 'Geschiedenis', $normalizer);
        $foreignBook = $this->bookType($libraryB, 'book-foreign', 'Foreign', $normalizer);

        $bookTypes = new WpdbLibraryBookTypeRepository($this->database, $this->tableNames);
        $genres = new WpdbLibraryGenreRepository($this->database, $this->tableNames);
        $subjects = new WpdbLibrarySubjectRepository($this->database, $this->tableNames);
        $bookTypes->add($bookA);
        $bookTypes->add($foreignBook);
        $genres->add($genreInactive);
        $genres->add($genreA);
        $subjects->add($subjectA);

        $contexts = new WpdbLibraryCatalogContextRepository($this->database, $this->tableNames);
        $transactions = new WpdbTransactionManager($this->database);
        $transactions->run(fn () => $contexts->add(LibraryCatalogContext::create(
            $libraryA,
            $workA,
            new LibraryCatalogSelection($bookA->id(), [$genreInactive->id(), $genreA->id()], [$subjectA->id()])
        )));
        $transactions->run(fn () => $contexts->add(LibraryCatalogContext::create(
            $libraryB,
            $workForeign,
            new LibraryCatalogSelection($foreignBook->id())
        )));

        $reads = new WpdbLibraryClassificationReadRepository($this->database, $this->tableNames);
        self::assertSame(['book-a'], array_map(static fn (LibraryBookType $term): string => $term->id()->value(), $reads->activeBookTypes($libraryA)));
        self::assertSame(['genre-a'], array_map(static fn (LibraryGenre $term): string => $term->id()->value(), $reads->activeGenres($libraryA)));
        self::assertSame(['subject-a'], array_map(static fn (LibrarySubject $term): string => $term->id()->value(), $reads->activeSubjects($libraryA)));

        $before = $this->database->num_queries;
        $result = $reads->classificationsForWorks($libraryA, [$workForeign, $workA, $workMissing]);
        self::assertSame(3, $this->database->num_queries - $before, 'A classification batch must use a constant three queries.');
        self::assertSame(['work-foreign', 'work-a', 'work-missing'], array_keys($result));
        self::assertNull($result['work-foreign']);
        self::assertNull($result['work-missing']);
        self::assertSame('book-a', $result['work-a']?->bookTypeId()->value());
        self::assertSame(['genre-a', 'genre-inactive'], array_map(static fn (LibraryGenreId $id): string => $id->value(), $result['work-a']?->genreIds() ?? []));
        self::assertSame(['subject-a'], array_map(static fn (LibrarySubjectId $id): string => $id->value(), $result['work-a']?->subjectIds() ?? []));

        $before = $this->database->num_queries;
        self::assertSame([], $reads->classificationsForWorks($libraryA, []));
        self::assertSame(0, $this->database->num_queries - $before);
    }

    public function testPersonalReadingStatusBatchUsesOneActorScopedQueryAndStablePrecedence(): void
    {
        $read = $this->addWork('work-read', 'Read');
        $reading = $this->addWork('work-reading', 'Reading');
        $foreignOnly = $this->addWork('work-foreign-only', 'Foreign only');
        $actor = new UserId('actor');
        $other = new UserId('other');
        $now = new DateTimeImmutable('2026-09-04 12:00:00.123456', new DateTimeZone('UTC'));
        $rounds = new WpdbReadingRoundRepository($this->database, $this->tableNames);
        $rounds->addForUser($actor, ReadingRound::historical(
            new ReadingRoundId('round-read'),
            $actor,
            $read,
            ReadingPeriod::ended(null, ReadingDate::year(2025)),
            $now
        ));
        $rounds->addForUser($actor, ReadingRound::legacyActive(
            new ReadingRoundId('round-reading'),
            $actor,
            $reading,
            null,
            $now
        ));
        $rounds->addForUser($other, ReadingRound::legacyActive(
            new ReadingRoundId('round-other'),
            $other,
            $foreignOnly,
            null,
            $now
        ));

        $service = new GetPersonalWorkReadingStatusService(
            new ControllableAuthenticatedUser($actor),
            $rounds
        );
        $before = $this->database->num_queries;
        $statuses = $service->getMany([$foreignOnly, $reading, $read]);

        self::assertSame(1, $this->database->num_queries - $before, 'Personal status must be loaded in one batch query.');
        self::assertSame([
            'work-foreign-only' => PersonalWorkReadingStatus::NotRead,
            'work-reading' => PersonalWorkReadingStatus::Reading,
            'work-read' => PersonalWorkReadingStatus::Read,
        ], $statuses);

        $before = $this->database->num_queries;
        self::assertSame([], $service->getMany([]));
        self::assertSame(0, $this->database->num_queries - $before);
    }

    private function addLibrary(string $id): LibraryId
    {
        $libraryId = new LibraryId($id);
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add(Library::privateLibrary($libraryId));
        return $libraryId;
    }

    private function addWork(string $id, string $title): WorkId
    {
        $workId = new WorkId($id);
        (new WpdbWorkRepository($this->database, $this->tableNames))->add(new Work($workId, $title));
        return $workId;
    }

    private function bookType(LibraryId $libraryId, string $id, string $name, ClassificationNameNormalizer $normalizer): LibraryBookType
    {
        $termName = new ClassificationTermName($name);
        return new LibraryBookType($libraryId, new LibraryBookTypeId($id), $termName, $normalizer->normalize($termName), ClassificationTermStatus::Active);
    }

    private function genre(LibraryId $libraryId, string $id, string $name, ClassificationTermStatus $status, ClassificationNameNormalizer $normalizer): LibraryGenre
    {
        $termName = new ClassificationTermName($name);
        return new LibraryGenre($libraryId, new LibraryGenreId($id), $termName, $normalizer->normalize($termName), $status);
    }

    private function subject(LibraryId $libraryId, string $id, string $name, ClassificationNameNormalizer $normalizer): LibrarySubject
    {
        $termName = new ClassificationTermName($name);
        return new LibrarySubject($libraryId, new LibrarySubjectId($id), $termName, $normalizer->normalize($termName), ClassificationTermStatus::Active);
    }
}
