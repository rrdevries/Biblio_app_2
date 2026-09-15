<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitializer;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogSelectionResolver;
use Biblio\Core\Application\Catalog\ItemLocalDetailsRecorder;
use Biblio\Core\Application\Catalog\LocalEditionResolver;
use Biblio\Core\Application\Identity\PersonalMigrationTarget;
use Biblio\Core\Application\Identity\PersonalMigrationTargetReadiness;
use Biblio\Core\Application\Migration\Catalog\CatalogEditionMigrationParticipant;
use Biblio\Core\Application\Migration\Catalog\CatalogEditionPlan;
use Biblio\Core\Application\Migration\Catalog\CatalogItemMigrationParticipant;
use Biblio\Core\Application\Migration\Catalog\CatalogItemPlan;
use Biblio\Core\Application\Migration\Catalog\CatalogMigrationFailure;
use Biblio\Core\Application\Migration\Catalog\CatalogMigrationReason;
use Biblio\Core\Application\Migration\Catalog\CatalogMigrationRecordIdGenerator;
use Biblio\Core\Application\Migration\Catalog\CatalogMigrationWriter;
use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\Catalog\CatalogWorkPlan;
use Biblio\Core\Application\Migration\CommitMigrationRecordService;
use Biblio\Core\Application\Migration\MappingDisposition;
use Biblio\Core\Application\Migration\MigrationClock;
use Biblio\Core\Application\Migration\MigrationMode;
use Biblio\Core\Application\Migration\MigrationRecordOutcome;
use Biblio\Core\Application\Migration\MigrationRun;
use Biblio\Core\Application\Migration\Runner\MigrationParticipant;
use Biblio\Core\Application\Migration\Runner\MigrationPlanningTarget;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;
use Biblio\Core\Application\Migration\SourceObservation;
use Biblio\Core\Catalog\Classification\ClassificationNormalizedName;
use Biblio\Core\Catalog\Classification\ClassificationTermName;
use Biblio\Core\Catalog\Classification\ClassificationTermStatus;
use Biblio\Core\Catalog\Classification\LibraryBookType;
use Biblio\Core\Catalog\Classification\LibraryBookTypeId;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\EditionIsbnMetadata;
use Biblio\Core\Catalog\InventoryNumber;
use Biblio\Core\Catalog\Isbn13;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemCondition;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\ItemLocalDetailsState;
use Biblio\Core\Catalog\LibraryLocation;
use Biblio\Core\Catalog\LocationId;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ConflictException;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicMetadataRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionIdentifierClaimRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemLocalDetailsRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryBookTypeRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryCatalogContextRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryGenreRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMutationLock;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibrarySubjectRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLocationRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbMigrationLedgerRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryName;
use DateTimeImmutable;
use RuntimeException;

final class CatalogMigrationTestClock implements MigrationClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-09-14T10:00:00.123456+00:00");
    }
}

final class CatalogMigrationTestIds implements CatalogMigrationRecordIdGenerator
{
    private int $work = 0;
    private int $edition = 0;
    private int $item = 0;

    public function nextWorkId(): WorkId
    {
        return new WorkId("migrated-work-" . ++$this->work);
    }

    public function nextEditionId(): EditionId
    {
        return new EditionId("migrated-edition-" . ++$this->edition);
    }

    public function nextItemId(): ItemId
    {
        return new ItemId("migrated-item-" . ++$this->item);
    }
}

final class CatalogMigrationParticipantTest extends PersistenceIntegrationTestCase
{
    public function testTypedChainCreatesMapsAndReplaysWithoutDuplicateTargets(): void
    {
        $fixture = $this->fixture("chain");
        $location = new LibraryLocation(
            new LocationId("location-chain"),
            $fixture["library"],
            "Kast A"
        );
        $fixture["locations"]->save($location);

        $work = MigrationSourceRecord::typed(
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            "work/source-a",
            new CatalogWorkPlan("Dezelfde titel")
        );
        $edition = MigrationSourceRecord::typed(
            CatalogEditionMigrationParticipant::SOURCE_TYPE,
            "edition/source-a",
            new CatalogEditionPlan(
                "work/source-a",
                "Concrete editie",
                EditionIsbnMetadata::identified(null, new Isbn13("9780306406157"))
            )
        );
        $item = MigrationSourceRecord::typed(
            CatalogItemMigrationParticipant::SOURCE_TYPE,
            "copy/source-a",
            new CatalogItemPlan(
                "edition/source-a",
                $fixture["library"],
                $fixture["selection"],
                new InventoryNumber("INV-1"),
                $location->id(),
                new ItemLocalDetailsState(condition: ItemCondition::Goed)
            )
        );

        $this->apply($fixture, $fixture["work_participant"], $work);
        $editionResult = $this->apply(
            $fixture,
            $fixture["edition_participant"],
            $edition
        );
        $itemResult = $this->apply(
            $fixture,
            $fixture["item_participant"],
            $item
        );

        self::assertCount(3, $itemResult["outcome"]->mappings());
        self::assertSame([[
            "source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE,
            "source_id" => "work/source-a",
        ]], $editionResult["plan"]->dependencies());
        self::assertSame([[
            "source_type" => CatalogEditionMigrationParticipant::SOURCE_TYPE,
            "source_id" => "edition/source-a",
        ]], $itemResult["plan"]->dependencies());
        self::assertArrayNotHasKey(
            "typed_plan",
            $itemResult["plan"]->toArray()
        );
        self::assertSame(1, $this->countRows($this->tableNames->works()));
        self::assertSame(1, $this->countRows($this->tableNames->editions()));
        self::assertSame(1, $this->countRows($this->tableNames->editionIdentifierClaims()));
        self::assertSame(1, $this->countRows($this->tableNames->items()));
        self::assertSame(1, $this->countRows($this->tableNames->libraryCatalogContexts()));
        self::assertSame(1, $this->countRows($this->tableNames->itemLocalDetails()));
        self::assertSame(6, $this->countRows($this->tableNames->migrationTargetMappings()));

        try {
            $fixture["commit"]->commit(
                $fixture["run"],
                $itemResult["observation"],
                fn (): MigrationRecordOutcome => $fixture["item_participant"]->apply(
                    $item,
                    $itemResult["observation"],
                    $itemResult["plan"],
                    $fixture["target"]
                )
            );
            self::fail("A committed copy observation was applied twice.");
        } catch (ValidationException) {
            self::assertSame(1, $this->countRows($this->tableNames->items()));
            self::assertSame(1, $this->countRows($this->tableNames->itemLocalDetails()));
        }

        $sameTitle = MigrationSourceRecord::typed(
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            "work/source-b",
            new CatalogWorkPlan("Dezelfde titel")
        );
        $this->apply($fixture, $fixture["work_participant"], $sameTitle);
        self::assertSame(2, $this->countRows($this->tableNames->works()));

        $copyTwo = MigrationSourceRecord::typed(
            CatalogItemMigrationParticipant::SOURCE_TYPE,
            "copy/source-b",
            new CatalogItemPlan(
                "edition/source-a",
                $fixture["library"],
                $fixture["selection"],
                new InventoryNumber("INV-2")
            )
        );
        $this->apply($fixture, $fixture["item_participant"], $copyTwo);
        self::assertSame(2, $this->countRows($this->tableNames->items()));
        self::assertSame(1, $this->countRows($this->tableNames->editions()));

        $fixture["ledger"]->releaseRunLock($fixture["run"]->id());
        $later = $fixture;
        $later["run"] = $fixture["ledger"]->beginOrResume(MigrationRun::start(
            "catalog-run-chain-later",
            "synthetic",
            "snapshot-chain-later",
            hash("sha256", "snapshot-chain-later"),
            "test-1",
            "2.31.0",
            $fixture["run"]->targetUserId(),
            $fixture["library"],
            MigrationMode::Apply,
            (new CatalogMigrationTestClock())->now()
        ));
        $later["commit"] = new CommitMigrationRecordService(
            $fixture["ledger"],
            $fixture["transactions"],
            new CatalogMigrationTestClock()
        );
        $priorMapping = $this->apply(
            $later,
            $fixture["work_participant"],
            MigrationSourceRecord::typed(
                CatalogWorkMigrationParticipant::SOURCE_TYPE,
                "work/source-a",
                new CatalogWorkPlan("Dezelfde titel")
            )
        )["outcome"];
        self::assertSame(
            MappingDisposition::Reused,
            $priorMapping->mappings()[0]->disposition()
        );
        self::assertSame(2, $this->countRows($this->tableNames->works()));

        try {
            $this->apply(
                $later,
                $fixture["work_participant"],
                MigrationSourceRecord::typed(
                    CatalogWorkMigrationParticipant::SOURCE_TYPE,
                    "work/source-a",
                    new CatalogWorkPlan("Changed source title")
                )
            );
            self::fail("Changed committed Work payload was silently reused.");
        } catch (ConflictException $exception) {
            self::assertStringContainsString("different content", $exception->getMessage());
            self::assertSame(2, $this->countRows($this->tableNames->works()));
        }

        $changedCopy = MigrationSourceRecord::typed(
            CatalogItemMigrationParticipant::SOURCE_TYPE,
            "copy/source-a",
            new CatalogItemPlan(
                "edition/source-a",
                $fixture["library"],
                $fixture["selection"],
                new InventoryNumber("INV-CHANGED")
            )
        );
        try {
            $this->apply($later, $fixture["item_participant"], $changedCopy);
            self::fail("Changed committed copy payload was silently reused.");
        } catch (CatalogMigrationFailure $failure) {
            self::assertSame(CatalogMigrationReason::DivergentReplay, $failure->reason());
            self::assertSame(2, $this->countRows($this->tableNames->items()));
        }
    }

    public function testDistinctExplicitNoIsbnEditionsRemainDistinct(): void
    {
        $fixture = $this->fixture("no-isbn");
        $work = MigrationSourceRecord::typed(
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            "work/no-isbn",
            new CatalogWorkPlan("Werk")
        );
        $this->apply($fixture, $fixture["work_participant"], $work);

        foreach (["a", "b"] as $suffix) {
            $record = MigrationSourceRecord::typed(
                CatalogEditionMigrationParticipant::SOURCE_TYPE,
                "edition/no-isbn-{$suffix}",
                new CatalogEditionPlan(
                    "work/no-isbn",
                    "Zelfde editietitel",
                    EditionIsbnMetadata::withoutIsbn()
                )
            );
            $this->apply($fixture, $fixture["edition_participant"], $record);
        }

        self::assertSame(2, $this->countRows($this->tableNames->editions()));
        self::assertSame(0, $this->countRows($this->tableNames->editionIdentifierClaims()));
        self::assertSame(2, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->editions()}` WHERE explicitly_no_isbn=1"
        ));
    }

    public function testCanonicalIsbnConvergesButNeverMovesEditionAcrossWorks(): void
    {
        $fixture = $this->fixture("isbn");
        $existingWork = new Work(new WorkId("existing-isbn-work"), "Canonical Work");
        $existingEdition = new Edition(
            new EditionId("existing-isbn-edition"),
            $existingWork->id(),
            "Canonical Edition",
            EditionIsbnMetadata::identified(null, new Isbn13("9780306406157"))
        );
        $fixture["transactions"]->run(function () use (
            $fixture,
            $existingWork,
            $existingEdition
        ): void {
            $fixture["works"]->add($existingWork);
            $fixture["editions"]->add($existingEdition);
            $fixture["claims"]->claim(
                new Isbn13("9780306406157"),
                $existingEdition->id()
            );
        });

        $mappedWork = MigrationSourceRecord::typed(
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            "work/existing",
            new CatalogWorkPlan("Source title", $existingWork->id())
        );
        $workOutcome = $this->apply(
            $fixture,
            $fixture["work_participant"],
            $mappedWork
        )["outcome"];
        self::assertSame(
            MappingDisposition::Reused,
            $workOutcome->mappings()[0]->disposition()
        );

        $converging = MigrationSourceRecord::typed(
            CatalogEditionMigrationParticipant::SOURCE_TYPE,
            "edition/converging",
            new CatalogEditionPlan(
                "work/existing",
                "Source Edition title",
                EditionIsbnMetadata::identified(null, new Isbn13("9780306406157"))
            )
        );
        $editionOutcome = $this->apply(
            $fixture,
            $fixture["edition_participant"],
            $converging
        )["outcome"];
        self::assertSame($existingEdition->id()->value(), $editionOutcome->mappings()[0]->targetId());
        self::assertSame("Canonical Work", $fixture["works"]->find($existingWork->id())?->title());
        self::assertSame("Canonical Edition", $fixture["editions"]->find($existingEdition->id())?->title());

        $otherWork = MigrationSourceRecord::typed(
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            "work/other",
            new CatalogWorkPlan("Other Work")
        );
        $this->apply($fixture, $fixture["work_participant"], $otherWork);
        $conflicting = MigrationSourceRecord::typed(
            CatalogEditionMigrationParticipant::SOURCE_TYPE,
            "edition/conflict",
            new CatalogEditionPlan(
                "work/other",
                "Conflict",
                EditionIsbnMetadata::identified(null, new Isbn13("9780306406157"))
            )
        );

        try {
            $this->apply($fixture, $fixture["edition_participant"], $conflicting);
            self::fail("ISBN winner was silently moved across Works.");
        } catch (CatalogMigrationFailure $failure) {
            self::assertSame(
                CatalogMigrationReason::WorkEditionConflict,
                $failure->reason()
            );
            self::assertSame(1, $this->countRows($this->tableNames->editions()));
        }
    }

    public function testPlanningRejectsCrossLibraryAndInvalidMappedClassification(): void
    {
        $fixture = $this->fixture("planning");
        $foreignLibrary = $this->createLibrary("foreign-planning");
        $crossLibrary = MigrationSourceRecord::typed(
            CatalogItemMigrationParticipant::SOURCE_TYPE,
            "copy/cross-library",
            new CatalogItemPlan(
                "edition/anything",
                $foreignLibrary,
                $fixture["selection"]
            )
        );
        try {
            $fixture["item_participant"]->plan($crossLibrary, $fixture["target"]);
            self::fail("Cross-Library typed plan was accepted.");
        } catch (CatalogMigrationFailure $failure) {
            self::assertSame(CatalogMigrationReason::CrossLibraryTarget, $failure->reason());
        }

        $invalidClassification = MigrationSourceRecord::typed(
            CatalogItemMigrationParticipant::SOURCE_TYPE,
            "copy/invalid-classification",
            new CatalogItemPlan(
                "edition/anything",
                $fixture["library"],
                new LibraryCatalogSelection(new LibraryBookTypeId("missing-book-type"))
            )
        );
        try {
            $fixture["item_participant"]->plan(
                $invalidClassification,
                $fixture["target"]
            );
            self::fail("Unknown target classification was accepted.");
        } catch (CatalogMigrationFailure $failure) {
            self::assertSame(
                CatalogMigrationReason::InvalidClassificationTarget,
                $failure->reason()
            );
        }

        self::assertSame(0, $this->countRows($this->tableNames->works()));
        self::assertSame(0, $this->countRows($this->tableNames->items()));
        self::assertSame(0, $this->countRows($this->tableNames->migrationSourceObservations()));

        try {
            new CatalogEditionPlan(
                "work/anything",
                "Unknown ISBN is not no-ISBN",
                EditionIsbnMetadata::unknown()
            );
            self::fail("Unknown ISBN state was silently converted to no-ISBN.");
        } catch (ValidationException $exception) {
            self::assertStringContainsString(
                "canonical ISBN or explicit no-ISBN",
                $exception->getMessage()
            );
        }
    }

    public function testOuterCommitRollbackRemovesItemClassificationDetailsAndMappings(): void
    {
        $fixture = $this->fixture("rollback");
        $this->apply(
            $fixture,
            $fixture["work_participant"],
            MigrationSourceRecord::typed(
                CatalogWorkMigrationParticipant::SOURCE_TYPE,
                "work/rollback",
                new CatalogWorkPlan("Rollback Work")
            )
        );
        $this->apply(
            $fixture,
            $fixture["edition_participant"],
            MigrationSourceRecord::typed(
                CatalogEditionMigrationParticipant::SOURCE_TYPE,
                "edition/rollback",
                new CatalogEditionPlan(
                    "work/rollback",
                    "Rollback Edition",
                    EditionIsbnMetadata::withoutIsbn()
                )
            )
        );
        $record = MigrationSourceRecord::typed(
            CatalogItemMigrationParticipant::SOURCE_TYPE,
            "copy/rollback",
            new CatalogItemPlan(
                "edition/rollback",
                $fixture["library"],
                $fixture["selection"],
                localDetails: new ItemLocalDetailsState(
                    condition: ItemCondition::ZeerGoed
                )
            )
        );
        $plan = $fixture["item_participant"]->plan($record, $fixture["target"]);
        $observation = $this->observe($fixture, $record);

        try {
            $fixture["commit"]->commit(
                $fixture["run"],
                $observation,
                function () use ($fixture, $record, $observation, $plan): MigrationRecordOutcome {
                    $outcome = $fixture["item_participant"]->apply(
                        $record,
                        $observation,
                        $plan,
                        $fixture["target"]
                    );
                    throw new RuntimeException("synthetic outcome failure");
                }
            );
            self::fail("Synthetic outcome failure was hidden.");
        } catch (RuntimeException $exception) {
            self::assertSame("synthetic outcome failure", $exception->getMessage());
        }

        self::assertSame(0, $this->countRows($this->tableNames->items()));
        self::assertSame(0, $this->countRows($this->tableNames->libraryCatalogContexts()));
        self::assertSame(0, $this->countRows($this->tableNames->itemLocalDetails()));
        self::assertSame(2, $this->countRows($this->tableNames->migrationTargetMappings()));
        self::assertSame(
            "observed",
            $this->database->get_var($this->database->prepare(
                "SELECT processing_status FROM `{$this->tableNames->migrationSourceObservations()}` WHERE observation_id=%s",
                $observation->id()
            ))
        );
    }

    public function testMappedForeignLibraryItemFailsClosedWithoutLocalContamination(): void
    {
        $fixture = $this->fixture("foreign-item");
        $this->apply(
            $fixture,
            $fixture["work_participant"],
            MigrationSourceRecord::typed(
                CatalogWorkMigrationParticipant::SOURCE_TYPE,
                "work/foreign-item",
                new CatalogWorkPlan("Work")
            )
        );
        $editionResult = $this->apply(
            $fixture,
            $fixture["edition_participant"],
            MigrationSourceRecord::typed(
                CatalogEditionMigrationParticipant::SOURCE_TYPE,
                "edition/foreign-item",
                new CatalogEditionPlan(
                    "work/foreign-item",
                    "Edition",
                    EditionIsbnMetadata::withoutIsbn()
                )
            )
        );
        $editionId = new EditionId(
            $editionResult["outcome"]->mappings()[0]->targetId()
        );
        $foreignLibrary = $this->createLibrary("foreign-item-library");
        $foreignItem = Item::active(
            new ItemId("foreign-mapped-item"),
            $foreignLibrary,
            $editionId
        );
        $fixture["items"]->add($foreignItem);
        $record = MigrationSourceRecord::typed(
            CatalogItemMigrationParticipant::SOURCE_TYPE,
            "copy/foreign-item",
            new CatalogItemPlan(
                "edition/foreign-item",
                $fixture["library"],
                $fixture["selection"],
                approvedExistingItemId: $foreignItem->id()
            )
        );

        try {
            $this->apply($fixture, $fixture["item_participant"], $record);
            self::fail("Foreign-Library mapped Item was accepted.");
        } catch (CatalogMigrationFailure $failure) {
            self::assertSame(CatalogMigrationReason::CrossLibraryTarget, $failure->reason());
        }

        self::assertNull(
            $fixture["items"]->findInLibrary(
                $foreignItem->id(),
                $fixture["library"]
            )
        );
        self::assertNotNull(
            $fixture["items"]->findInLibrary($foreignItem->id(), $foreignLibrary)
        );
        self::assertSame(0, $this->countRows($this->tableNames->libraryCatalogContexts()));
        self::assertSame(0, $this->countRows($this->tableNames->itemLocalDetails()));
    }

    /** @return array<string, mixed> */
    private function fixture(string $suffix): array
    {
        $library = $this->createLibrary("library-{$suffix}");
        $bookTypes = new WpdbLibraryBookTypeRepository(
            $this->database,
            $this->tableNames
        );
        $bookTypeId = new LibraryBookTypeId("book-type-{$suffix}");
        $bookTypes->add(new LibraryBookType(
            $library,
            $bookTypeId,
            new ClassificationTermName("Leesboek"),
            new ClassificationNormalizedName("leesboek"),
            ClassificationTermStatus::Active
        ));
        $genres = new WpdbLibraryGenreRepository($this->database, $this->tableNames);
        $subjects = new WpdbLibrarySubjectRepository($this->database, $this->tableNames);
        $contexts = new WpdbLibraryCatalogContextRepository(
            $this->database,
            $this->tableNames
        );
        $locations = new WpdbLocationRepository($this->database, $this->tableNames);
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $editions = new WpdbEditionRepository($this->database, $this->tableNames);
        $items = new WpdbItemRepository($this->database, $this->tableNames);
        $details = new WpdbItemLocalDetailsRepository($this->database, $this->tableNames);
        $claims = new WpdbEditionIdentifierClaimRepository(
            $this->database,
            $this->tableNames
        );
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $transactions = new WpdbTransactionManager($this->database);
        $clock = new CatalogMigrationTestClock();
        $run = $ledger->beginOrResume(MigrationRun::start(
            "catalog-run-{$suffix}",
            "synthetic",
            "snapshot-{$suffix}",
            hash("sha256", "snapshot-{$suffix}"),
            "test-1",
            "2.31.0",
            new UserId("catalog-user-{$suffix}"),
            $library,
            MigrationMode::Apply,
            $clock->now()
        ));
        $target = new MigrationPlanningTarget(new PersonalMigrationTarget(
            $run->targetUserId(),
            $library,
            new LibraryName("Library {$suffix}"),
            new PersonalMigrationTargetReadiness([])
        ));
        $initializer = new LibraryCatalogContextInitializer(
            $contexts,
            new LibraryCatalogSelectionResolver($bookTypes, $genres, $subjects),
            new WpdbLibraryMutationLock($this->database, $this->tableNames)
        );
        $writer = new CatalogMigrationWriter(
            $ledger,
            new CatalogMigrationTestIds(),
            $works,
            $editions,
            $items,
            new LocalEditionResolver(
                new \Biblio\Core\Catalog\IsbnCanonicalizer(),
                $claims,
                $editions,
                new WpdbBibliographicMetadataRepository(
                    $this->database,
                    $this->tableNames
                )
            ),
            $claims,
            $contexts,
            $initializer,
            $locations,
            $bookTypes,
            $genres,
            $subjects,
            $details,
            new ItemLocalDetailsRecorder($items, $details)
        );

        return [
            "library" => $library,
            "selection" => new LibraryCatalogSelection($bookTypeId),
            "locations" => $locations,
            "works" => $works,
            "editions" => $editions,
            "items" => $items,
            "claims" => $claims,
            "transactions" => $transactions,
            "ledger" => $ledger,
            "run" => $run,
            "target" => $target,
            "commit" => new CommitMigrationRecordService(
                $ledger,
                $transactions,
                $clock
            ),
            "work_participant" => new CatalogWorkMigrationParticipant($writer),
            "edition_participant" => new CatalogEditionMigrationParticipant($writer),
            "item_participant" => new CatalogItemMigrationParticipant($writer),
        ];
    }

    private function createLibrary(string $id): LibraryId
    {
        $libraryId = new LibraryId($id);
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add(
            Library::privateLibrary($libraryId, new LibraryName($id))
        );
        return $libraryId;
    }

    /** @param array<string, mixed> $fixture */
    private function apply(
        array $fixture,
        MigrationParticipant $participant,
        MigrationSourceRecord $record
    ): array {
        $plan = $participant->plan($record, $fixture["target"]);
        $observation = $this->observe($fixture, $record);
        $outcome = $fixture["commit"]->commit(
            $fixture["run"],
            $observation,
            fn (): MigrationRecordOutcome => $participant->apply(
                $record,
                $observation,
                $plan,
                $fixture["target"]
            )
        );
        return [
            "plan" => $plan,
            "observation" => $observation,
            "outcome" => $outcome,
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function observe(
        array $fixture,
        MigrationSourceRecord $record
    ): SourceObservation {
        return $fixture["ledger"]->addOrFindObservation(
            SourceObservation::observe(
                $fixture["run"],
                $record->sourceType(),
                $record->sourceId(),
                $record->payloadHash(),
                $record->payload(),
                null,
                (new CatalogMigrationTestClock())->now()
            )
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM `{$table}`");
    }
}
