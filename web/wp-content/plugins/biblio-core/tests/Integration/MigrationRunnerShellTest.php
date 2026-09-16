<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Migration\MigrationRecordOutcome;
use Biblio\Core\Application\Migration\Author\{CatalogAuthorMigrationParticipant,CatalogAuthorPlan,CatalogWorkContributorMigrationParticipant,CatalogWorkContributorPlan};
use Biblio\Core\Application\Migration\Catalog\{CatalogEditionMigrationParticipant,CatalogEditionPlan,CatalogItemMigrationParticipant,CatalogItemPlan,CatalogWorkMigrationParticipant,CatalogWorkPlan};
use Biblio\Core\Application\Migration\Notes\{PrivateNoteMigrationParticipant,PrivateNotePlan};
use Biblio\Core\Application\Migration\Reading\{ReadingRoundMigrationParticipant,ReadingRoundPlan};
use Biblio\Core\Application\Migration\Runner\MigrationBuildProvenance;
use Biblio\Core\Application\Migration\Runner\MigrationEnvironment;
use Biblio\Core\Application\Migration\Runner\MigrationParticipant;
use Biblio\Core\Application\Migration\Runner\MigrationParticipantRegistry;
use Biblio\Core\Application\Migration\Runner\MigrationPlanningTarget;
use Biblio\Core\Application\Migration\Runner\MigrationRunner;
use Biblio\Core\Application\Migration\Runner\MigrationSourceAdapter;
use Biblio\Core\Application\Migration\Runner\MigrationSourceAdapterRegistry;
use Biblio\Core\Application\Migration\Runner\MigrationSourceMapperRegistry;
use Biblio\Core\Application\Migration\Runner\MigrationSourcePackage;
use Biblio\Core\Application\Migration\Runner\MigrationSourceProfile;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;
use Biblio\Core\Application\Migration\Runner\PlannedMigrationRecord;
use Biblio\Core\Application\Migration\SourceObservation;
use Biblio\Core\Catalog\Classification\{LibraryBookTypeId,LibraryCatalogSelection};
use Biblio\Core\Catalog\EditionIsbnMetadata;
use Biblio\Core\Catalog\{ContributorPosition,ContributorRole};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\CurrentV1SourceAdapter;
use Biblio\Core\Infrastructure\Migration\CurrentV1CatalogMapper;
use Biblio\Core\Infrastructure\Migration\CurrentV1ClassificationMapper;
use Biblio\Core\Infrastructure\Migration\CurrentV1ReviewedClassificationContract;
use Biblio\Core\Infrastructure\Migration\FilesystemMigrationSourcePackageFactory;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryBookTypeRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryGenreRepository;
use Biblio\Core\Infrastructure\WordPress\Cli\MigrationCommand;
use Biblio\Core\Infrastructure\WordPress\Cli\MigrationCommandOutput;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Notes\StrictPrivateNoteContentPolicy;
use Biblio\Core\Reading\{ReadingDate,ReadingPeriod,ReadingRoundOutcome};
use DateTimeImmutable;
use RuntimeException;

final readonly class RunnerShellSourceAdapter implements MigrationSourceAdapter
{
    public function adapterId(): string { return "synthetic-shell"; }
    public function sourceFamily(): string { return "synthetic"; }

    public function profile(MigrationSourcePackage $package): MigrationSourceProfile
    {
        $payload = json_decode($package->read("source.json"), true, 16, JSON_THROW_ON_ERROR);
        return new MigrationSourceProfile((string) ($payload["version"] ?? "unknown"), [
            "synthetic_record" => count($payload["records"] ?? []),
        ]);
    }

    public function supportsVersion(string $sourceVersion): bool
    {
        return $sourceVersion === "shell-test-1";
    }

    public function records(
        MigrationSourcePackage $package,
        MigrationSourceProfile $profile
    ): iterable {
        unset($profile);
        $payload = json_decode($package->read("source.json"), true, 16, JSON_THROW_ON_ERROR);
        foreach ($payload["records"] ?? [] as $record) {
            yield new MigrationSourceRecord(
                "synthetic_record",
                (string) $record["id"],
                ["value" => $record["value"]]
            );
        }
    }
}

final readonly class RunnerShellParticipant implements MigrationParticipant
{
    public function sourceType(): string { return "synthetic_record"; }

    public function plan(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target
    ): PlannedMigrationRecord {
        return new PlannedMigrationRecord(MigrationDisposition::Mapped, [[
            "operation" => "synthetic_no_write_plan",
            "target_type" => "synthetic",
            "target_id" => $target->libraryId() . ":" . $record->sourceId(),
        ]]);
    }

    public function apply(
        MigrationSourceRecord $record,
        SourceObservation $observation,
        PlannedMigrationRecord $plan,
        MigrationPlanningTarget $target
    ): MigrationRecordOutcome {
        unset($record, $observation, $plan, $target);
        throw new RuntimeException("RUN-01 integration participant is planning-only.");
    }
}

final readonly class RunnerShellCatalogAdapter implements MigrationSourceAdapter
{
    public function __construct(
        private LibraryId $libraryId,
        private LibraryBookTypeId $bookTypeId
    ) {
    }

    public function adapterId(): string { return "synthetic-catalog"; }
    public function sourceFamily(): string { return "synthetic"; }

    public function profile(MigrationSourcePackage $package): MigrationSourceProfile
    {
        $payload = json_decode($package->read("source.json"), true, 16, JSON_THROW_ON_ERROR);
        return new MigrationSourceProfile((string) ($payload["version"] ?? "unknown"), [
            CatalogAuthorMigrationParticipant::SOURCE_TYPE => 1,
            CatalogWorkMigrationParticipant::SOURCE_TYPE => 1,
            CatalogWorkContributorMigrationParticipant::SOURCE_TYPE => 1,
            CatalogEditionMigrationParticipant::SOURCE_TYPE => 1,
            CatalogItemMigrationParticipant::SOURCE_TYPE => 1,
        ]);
    }

    public function supportsVersion(string $sourceVersion): bool
    {
        return $sourceVersion === "catalog-test-1";
    }

    public function records(
        MigrationSourcePackage $package,
        MigrationSourceProfile $profile
    ): iterable {
        unset($package, $profile);
        yield MigrationSourceRecord::typed(
            CatalogAuthorMigrationParticipant::SOURCE_TYPE,
            "author/dry-run",
            new CatalogAuthorPlan("Dry-run Author")
        );
        yield MigrationSourceRecord::typed(
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            "work/dry-run",
            new CatalogWorkPlan("Dry-run Work")
        );
        yield MigrationSourceRecord::typed(
            CatalogEditionMigrationParticipant::SOURCE_TYPE,
            "edition/dry-run",
            new CatalogEditionPlan(
                "work/dry-run",
                "Dry-run Edition",
                EditionIsbnMetadata::unknown()
            )
        );
        yield MigrationSourceRecord::typed(
            CatalogWorkContributorMigrationParticipant::SOURCE_TYPE,
            "contributor/dry-run",
            new CatalogWorkContributorPlan(
                "author/dry-run",
                "work/dry-run",
                ContributorRole::Author,
                new ContributorPosition(1),
                "Dry-run Author"
            )
        );
        yield MigrationSourceRecord::typed(
            CatalogItemMigrationParticipant::SOURCE_TYPE,
            "copy/dry-run",
            new CatalogItemPlan(
                "edition/dry-run",
                $this->libraryId,
                new LibraryCatalogSelection($this->bookTypeId)
            )
        );
    }
}

final readonly class RunnerShellReadingAdapter implements MigrationSourceAdapter
{
    public function __construct(private UserId $userId)
    {
    }

    public function adapterId(): string { return "synthetic-reading"; }
    public function sourceFamily(): string { return "synthetic"; }

    public function profile(MigrationSourcePackage $package): MigrationSourceProfile
    {
        unset($package);
        return new MigrationSourceProfile("reading-test-1", [
            ReadingRoundMigrationParticipant::SOURCE_TYPE => 1,
        ]);
    }

    public function supportsVersion(string $sourceVersion): bool
    {
        return $sourceVersion === "reading-test-1";
    }

    public function records(
        MigrationSourcePackage $package,
        MigrationSourceProfile $profile
    ): iterable {
        unset($package, $profile);
        yield MigrationSourceRecord::typed(
            ReadingRoundMigrationParticipant::SOURCE_TYPE,
            "round/dry-run",
            new ReadingRoundPlan(
                $this->userId,
                "work/dry-run",
                ReadingRoundOutcome::Completed,
                ReadingPeriod::ended(
                    ReadingDate::year(2019),
                    ReadingDate::month(2020, 2)
                )
            )
        );
    }
}

final readonly class RunnerShellPrivateNoteAdapter implements MigrationSourceAdapter
{
    public function __construct(private UserId $userId)
    {
    }

    public function adapterId(): string { return "synthetic-private-note"; }
    public function sourceFamily(): string { return "synthetic"; }

    public function profile(MigrationSourcePackage $package): MigrationSourceProfile
    {
        unset($package);
        return new MigrationSourceProfile("private-note-test-1", [
            PrivateNoteMigrationParticipant::SOURCE_TYPE => 1,
        ]);
    }

    public function supportsVersion(string $sourceVersion): bool
    {
        return $sourceVersion === "private-note-test-1";
    }

    public function records(
        MigrationSourcePackage $package,
        MigrationSourceProfile $profile
    ): iterable {
        unset($package, $profile);
        yield MigrationSourceRecord::typed(
            PrivateNoteMigrationParticipant::SOURCE_TYPE,
            "note/dry-run",
            new PrivateNotePlan(
                $this->userId,
                "work/dry-run",
                (new StrictPrivateNoteContentPolicy())->sanitize(
                    "<p>private-note-body-must-not-appear</p>"
                ),
                new DateTimeImmutable("2001-02-03T04:05:06.123456+00:00"),
                new DateTimeImmutable("2002-03-04T05:06:07.654321+00:00"),
                "round/dry-run"
            )
        );
    }
}

final readonly class RunnerShellEnvironment implements MigrationEnvironment
{
    public function assertHealthy(): void
    {
    }

    public function provenance(): MigrationBuildProvenance
    {
        return new MigrationBuildProvenance(
            "v2.001",
            1026,
            "2.33.0",
            str_repeat("b", 40),
            false
        );
    }
}

final class RecordingMigrationCommandOutput implements MigrationCommandOutput
{
    /** @var list<string> */
    public array $lines = [];

    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    public function error(string $message): never
    {
        throw new RuntimeException($message);
    }
}

final class MigrationRunnerShellTest extends PersistenceIntegrationTestCase
{
    /** @var list<int> */
    private array $createdUsers = [];
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->createdUsers as $userId) {
            $this->database->delete($this->database->usermeta, ["user_id" => $userId], ["%d"]);
            $this->database->delete($this->database->users, ["ID" => $userId], ["%d"]);
        }
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            $this->removeDirectory($directory);
        }
        parent::tearDown();
    }

    public function testProfileAndDryRunCliWriteOnlyArtifactsAndPrintTheirPaths(): void
    {
        $userId = wp_create_user(
            "runner-shell-user",
            "synthetic-test-password",
            "runner-shell@example.invalid"
        );
        self::assertIsInt($userId);
        $this->createdUsers[] = $userId;

        $application = (new ProductionComposition($this->database))->application();
        $target = $application->personalMigrationTargets()->bootstrap(
            new UserId((string) $userId)
        );
        $source = $this->source("shell-test-1");
        $outputDirectory = $this->directory();
        $output = new RecordingMigrationCommandOutput();
        $command = $this->command($application, $output);

        $before = $this->allCoreTableCounts();
        $command->profile([], [
            "source-root" => $source,
            "source-adapter" => "synthetic-shell",
            "output-dir" => $outputDirectory,
        ]);
        $command->dry_run([], [
            "source-root" => $source,
            "source-adapter" => "synthetic-shell",
            "target-user-id" => (string) $userId,
            "target-library-id" => $target->libraryId()->value(),
            "require-empty" => true,
            "output-dir" => $outputDirectory,
        ]);
        $after = $this->allCoreTableCounts();

        self::assertSame($before, $after, "Profile/dry-run changed Core table counts.");
        self::assertCount(2, $output->lines);
        $profileOutput = json_decode($output->lines[0], true, 16, JSON_THROW_ON_ERROR);
        $dryRunOutput = json_decode($output->lines[1], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame("profile", $profileOutput["mode"]);
        self::assertSame("dry_run", $dryRunOutput["mode"]);
        self::assertTrue($profileOutput["zero_write"]);
        self::assertTrue($dryRunOutput["zero_write"]);
        self::assertFileExists($profileOutput["artifact_path"]);
        self::assertFileExists($dryRunOutput["artifact_path"]);
        self::assertSame(
            $dryRunOutput["artifact_sha256"],
            hash_file("sha256", $dryRunOutput["artifact_path"])
        );
        $dryRunArtifact = json_decode(
            (string) file_get_contents($dryRunOutput["artifact_path"]),
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(2, $dryRunArtifact["migration_artifact_version"]);
        self::assertFalse($dryRunArtifact["planning_reconciliation"]["applied"]);
        self::assertFalse($dryRunArtifact["planning_reconciliation"]["accepted"]);
        self::assertSame(1, $dryRunArtifact["planning_reconciliation"]["planned_observations"]);
    }

    public function testDefaultCliProfilesCurrentV1AdapterWithoutProductOrLedgerWrites(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        $source = $this->currentV1Source();
        $outputDirectory = $this->directory();
        $output = new RecordingMigrationCommandOutput();
        $command = new MigrationCommand(
            static fn (): CoreApplication => $application,
            dirname(__DIR__, 2) . "/biblio-core.php",
            output: $output
        );

        $before = $this->allCoreTableCounts();
        $command->profile([], [
            "source-root" => $source,
            "source-adapter" => CurrentV1SourceAdapter::ADAPTER_ID,
            "output-dir" => $outputDirectory,
        ]);
        self::assertSame($before, $this->allCoreTableCounts());

        self::assertCount(1, $output->lines);
        $commandOutput = json_decode($output->lines[0], true, 16, JSON_THROW_ON_ERROR);
        $artifactBytes = (string) file_get_contents($commandOutput["artifact_path"]);
        $artifact = json_decode($artifactBytes, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(CurrentV1SourceAdapter::ADAPTER_ID, $artifact["source"]["adapter_id"]);
        self::assertSame(CurrentV1SourceAdapter::SOURCE_VERSION, $artifact["source"]["source_version"]);
        self::assertSame(6, $artifact["record_count"]);
        self::assertTrue($artifact["zero_write_confirmed"]);
        self::assertStringNotContainsString("Private Adapter Author", $artifactBytes);
        self::assertStringNotContainsString("Private Adapter Title", $artifactBytes);
        self::assertSame(
            $commandOutput["artifact_sha256"],
            hash_file("sha256", $commandOutput["artifact_path"])
        );
    }

    public function testReviewedSyntheticCurrentV1PlansCirculationPrivatelyAndWithoutWrites(): void
    {
        $userId = wp_create_user(
            "runner-current-circulation-user",
            "synthetic-test-password",
            "runner-current-circulation@example.invalid"
        );
        self::assertIsInt($userId);
        $this->createdUsers[] = $userId;
        $application = (new ProductionComposition($this->database))->application();
        $target = $application->personalMigrationTargets()->bootstrap(
            new UserId((string) $userId)
        );
        $source = $this->currentV1Source();
        $manifest = (new FilesystemMigrationSourcePackageFactory())
            ->build($source)
            ->manifestDigest();
        $outputDirectory = $this->directory();
        $output = new RecordingMigrationCommandOutput();
        $sourceMappers = new MigrationSourceMapperRegistry([
            new CurrentV1CatalogMapper(
                classificationMapper: new CurrentV1ClassificationMapper(
                    new WpdbLibraryBookTypeRepository(
                        $this->database,
                        $this->tableNames
                    ),
                    new WpdbLibraryGenreRepository(
                        $this->database,
                        $this->tableNames
                    ),
                    new CurrentV1ReviewedClassificationContract($manifest)
                )
            ),
        ]);
        $command = new MigrationCommand(
            static fn (): CoreApplication => $application,
            dirname(__DIR__, 2) . "/biblio-core.php",
            static fn (CoreApplication $core): MigrationRunner => new MigrationRunner(
                new FilesystemMigrationSourcePackageFactory(),
                new MigrationSourceAdapterRegistry([new CurrentV1SourceAdapter()]),
                $core->migrationParticipants(),
                $core->personalMigrationTargets(),
                new RunnerShellEnvironment(),
                $sourceMappers
            ),
            output: $output
        );

        $before = $this->allCoreTableCounts();
        $command->dry_run([], [
            "source-root" => $source,
            "source-adapter" => CurrentV1SourceAdapter::ADAPTER_ID,
            "target-user-id" => (string) $userId,
            "target-library-id" => $target->libraryId()->value(),
            "output-dir" => $outputDirectory,
        ]);
        self::assertSame($before, $this->allCoreTableCounts());

        $commandOutput = json_decode($output->lines[0], true, 16, JSON_THROW_ON_ERROR);
        $artifactBytes = (string) file_get_contents($commandOutput["artifact_path"]);
        $artifact = json_decode($artifactBytes, true, 32, JSON_THROW_ON_ERROR);
        self::assertTrue($artifact["zero_write_confirmed"]);
        self::assertSame(
            3,
            $artifact["planning_reconciliation"]["planned_observations"]
        );
        self::assertSame(
            2,
            $artifact["plan"]["disposition_counts"]["mapped"]
        );
        self::assertSame(
            1,
            $artifact["plan"]["disposition_counts"]["quarantined"]
        );
        $circulation = array_values(array_filter(
            $artifact["plan"]["records"],
            static fn (array $record): bool =>
                $record["source_type"] === CurrentV1SourceAdapter::CIRCULATION_ROUND
        ));
        self::assertCount(1, $circulation);
        self::assertSame(
            "ambiguous_circulation_semantics",
            $circulation[0]["reason_code"]
        );
        self::assertSame([], $circulation[0]["operations"]);
        self::assertSame(
            1,
            $artifact["plan"]["source_mapping_finding_counts"]
                ["unresolved_classification_dependency"]
        );
        self::assertSame(
            1,
            $artifact["plan"]["source_mapping_finding_counts"]
                ["unresolved_item_local_dependency"]
        );
        self::assertStringNotContainsString("Private Counterparty", $artifactBytes);
        self::assertStringNotContainsString("private circulation note", $artifactBytes);
        self::assertStringNotContainsString('"source_payload"', $artifactBytes);
        self::assertSame(0, $this->allCoreTableCounts()[
            $this->tableNames->externalLoans()
        ]);
        self::assertSame(0, $this->allCoreTableCounts()[
            $this->tableNames->migrationSourceObservations()
        ]);
    }

    public function testDefaultCliRejectsUnreviewedCurrentV1Manifest(): void
    {
        $userId = wp_create_user(
            "runner-current-manifest-user",
            "synthetic-test-password",
            "runner-current-manifest@example.invalid"
        );
        self::assertIsInt($userId);
        $this->createdUsers[] = $userId;
        $application = (new ProductionComposition($this->database))->application();
        $target = $application->personalMigrationTargets()->bootstrap(
            new UserId((string) $userId)
        );
        $source = $this->currentV1Source();
        $before = $this->allCoreTableCounts();

        try {
            (new MigrationCommand(
                static fn (): CoreApplication => $application,
                dirname(__DIR__, 2) . "/biblio-core.php",
                output: new RecordingMigrationCommandOutput()
            ))->dry_run([], [
                "source-root" => $source,
                "source-adapter" => CurrentV1SourceAdapter::ADAPTER_ID,
                "target-user-id" => (string) $userId,
                "target-library-id" => $target->libraryId()->value(),
                "output-dir" => $this->directory(),
            ]);
            self::fail("An unreviewed CURRENT manifest must fail closed.");
        } catch (RuntimeException $exception) {
            self::assertStringContainsString("source_changed", $exception->getMessage());
        }

        self::assertSame($before, $this->allCoreTableCounts());
    }

    public function testCliReturnsFailureForMissingSourceInvalidTargetAndUnsupportedVersion(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        $outputDirectory = $this->directory();

        $cases = [
            [
                "source-root" => $outputDirectory . "/missing",
                "source-adapter" => "synthetic-shell",
                "target-user-id" => "999991",
                "target-library-id" => "missing-library",
                "output-dir" => $outputDirectory,
            ],
            [
                "source-root" => $this->source("shell-test-1"),
                "source-adapter" => "synthetic-shell",
                "target-user-id" => "999992",
                "target-library-id" => "missing-library",
                "output-dir" => $outputDirectory,
            ],
            [
                "source-root" => $this->source("unsupported"),
                "source-adapter" => "synthetic-shell",
                "target-user-id" => "999993",
                "target-library-id" => "missing-library",
                "output-dir" => $outputDirectory,
            ],
        ];

        foreach ($cases as $arguments) {
            try {
                $this->command(
                    $application,
                    new RecordingMigrationCommandOutput()
                )->dry_run([], $arguments);
                self::fail("Fatal CLI input should return nonzero.");
            } catch (RuntimeException $exception) {
                self::assertStringContainsString("Migration", $exception->getMessage());
            }
        }
    }

    public function testCatalogParticipantCliDryRunIsTypedOrderedAndZeroWrite(): void
    {
        $userId = wp_create_user(
            "runner-catalog-user",
            "synthetic-test-password",
            "runner-catalog@example.invalid"
        );
        self::assertIsInt($userId);
        $this->createdUsers[] = $userId;
        $application = (new ProductionComposition($this->database))->application();
        $target = $application->personalMigrationTargets()->bootstrap(
            new UserId((string) $userId)
        );
        $bookTypeId = $this->database->get_var($this->database->prepare(
            "SELECT book_type_id FROM `{$this->tableNames->libraryBookTypes()}` WHERE library_id=%s AND term_status='active' ORDER BY book_type_id LIMIT 1",
            $target->libraryId()->value()
        ));
        self::assertIsString($bookTypeId);
        $adapter = new RunnerShellCatalogAdapter(
            $target->libraryId(),
            new LibraryBookTypeId($bookTypeId)
        );
        $source = $this->source("catalog-test-1");
        $outputDirectory = $this->directory();
        $output = new RecordingMigrationCommandOutput();
        $command = new MigrationCommand(
            static fn (): CoreApplication => $application,
            dirname(__DIR__, 2) . "/biblio-core.php",
            static fn (CoreApplication $core): MigrationRunner => new MigrationRunner(
                new FilesystemMigrationSourcePackageFactory(),
                new MigrationSourceAdapterRegistry([$adapter]),
                $core->migrationParticipants(),
                $core->personalMigrationTargets(),
                new RunnerShellEnvironment()
            ),
            $output
        );

        $before = $this->allCoreTableCounts();
        $arguments = [
            "source-root" => $source,
            "source-adapter" => "synthetic-catalog",
            "target-user-id" => (string) $userId,
            "target-library-id" => $target->libraryId()->value(),
            "require-empty" => true,
            "output-dir" => $outputDirectory,
        ];
        $command->dry_run([], $arguments);
        $command->dry_run([], $arguments);
        self::assertSame($before, $this->allCoreTableCounts());

        $commandOutput = json_decode($output->lines[0], true, 16, JSON_THROW_ON_ERROR);
        $secondCommandOutput = json_decode(
            $output->lines[1],
            true,
            16,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(
            file_get_contents($commandOutput["artifact_path"]),
            file_get_contents($secondCommandOutput["artifact_path"])
        );
        $artifact = json_decode(
            (string) file_get_contents($commandOutput["artifact_path"]),
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        self::assertTrue($artifact["zero_write_confirmed"]);
        self::assertCount(5, $artifact["plan"]["records"]);
        self::assertSame([], $artifact["plan"]["planning_errors"]);
        self::assertSame([], $artifact["plan"]["records"][0]["dependencies"]);
        self::assertSame(
            [["source_id" => "work/dry-run", "source_type" => "catalog_work"]],
            $artifact["plan"]["records"][1]["dependencies"]
        );
        self::assertSame([[
            "operation" => "create_or_reuse_edition",
        ]], $artifact["plan"]["records"][1]["operations"]);
        self::assertSame(
            [["source_id" => "edition/dry-run", "source_type" => "catalog_edition"]],
            $artifact["plan"]["records"][2]["dependencies"]
        );
        self::assertSame(
            [
                ["source_id" => "author/dry-run", "source_type" => "catalog_author"],
                ["source_id" => "work/dry-run", "source_type" => "catalog_work"],
            ],
            $artifact["plan"]["records"][4]["dependencies"]
        );
        self::assertStringNotContainsString(
            "Dry-run Work",
            (string) file_get_contents($commandOutput["artifact_path"])
        );
        self::assertStringNotContainsString(
            "Dry-run Author",
            (string) file_get_contents($commandOutput["artifact_path"])
        );
    }

    public function testReadingRoundParticipantDryRunIsDeterministicAndZeroWrite(): void
    {
        $userId = wp_create_user(
            "runner-reading-user",
            "synthetic-test-password",
            "runner-reading@example.invalid"
        );
        self::assertIsInt($userId);
        $this->createdUsers[] = $userId;
        $application = (new ProductionComposition($this->database))->application();
        $user = new UserId((string) $userId);
        $target = $application->personalMigrationTargets()->bootstrap($user);
        $adapter = new RunnerShellReadingAdapter($user);
        $source = $this->source("reading-test-1");
        $outputDirectory = $this->directory();
        $output = new RecordingMigrationCommandOutput();
        $command = new MigrationCommand(
            static fn (): CoreApplication => $application,
            dirname(__DIR__, 2) . "/biblio-core.php",
            static fn (CoreApplication $core): MigrationRunner => new MigrationRunner(
                new FilesystemMigrationSourcePackageFactory(),
                new MigrationSourceAdapterRegistry([$adapter]),
                $core->migrationParticipants(),
                $core->personalMigrationTargets(),
                new RunnerShellEnvironment()
            ),
            $output
        );

        $before = $this->allCoreTableCounts();
        $arguments = [
            "source-root" => $source,
            "source-adapter" => "synthetic-reading",
            "target-user-id" => (string) $userId,
            "target-library-id" => $target->libraryId()->value(),
            "require-empty" => true,
            "output-dir" => $outputDirectory,
        ];
        $command->dry_run([], $arguments);
        $first = json_decode($output->lines[0], true, 16, JSON_THROW_ON_ERROR);
        $command->dry_run([], $arguments);
        $second = json_decode($output->lines[1], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($before, $this->allCoreTableCounts());

        $firstArtifact = (string) file_get_contents($first["artifact_path"]);
        $secondArtifact = (string) file_get_contents($second["artifact_path"]);
        self::assertSame($firstArtifact, $secondArtifact);
        $artifact = json_decode($firstArtifact, true, 32, JSON_THROW_ON_ERROR);
        self::assertTrue($artifact["zero_write_confirmed"]);
        self::assertCount(1, $artifact["plan"]["records"]);
        self::assertSame([[
            "source_id" => "work/dry-run",
            "source_type" => "catalog_work",
        ]], $artifact["plan"]["records"][0]["dependencies"]);
        self::assertSame([[
            "operation" => "create_or_reuse_reading_round",
        ]], $artifact["plan"]["records"][0]["operations"]);
        self::assertStringNotContainsString('"period"', $firstArtifact);
        self::assertStringNotContainsString('"outcome"', $firstArtifact);
        self::assertSame(0, $this->allCoreTableCounts()[
            $this->tableNames->personalReadingTruths()
        ]);
    }

    public function testPrivateNoteParticipantDryRunIsDeterministicPrivateAndZeroWrite(): void
    {
        $userId = wp_create_user(
            "runner-private-note-user",
            "synthetic-test-password",
            "runner-private-note@example.invalid"
        );
        self::assertIsInt($userId);
        $this->createdUsers[] = $userId;
        $application = (new ProductionComposition($this->database))->application();
        $user = new UserId((string) $userId);
        $target = $application->personalMigrationTargets()->bootstrap($user);
        $adapter = new RunnerShellPrivateNoteAdapter($user);
        $source = $this->source("private-note-test-1");
        $outputDirectory = $this->directory();
        $output = new RecordingMigrationCommandOutput();
        $command = new MigrationCommand(
            static fn (): CoreApplication => $application,
            dirname(__DIR__, 2) . "/biblio-core.php",
            static fn (CoreApplication $core): MigrationRunner => new MigrationRunner(
                new FilesystemMigrationSourcePackageFactory(),
                new MigrationSourceAdapterRegistry([$adapter]),
                $core->migrationParticipants(),
                $core->personalMigrationTargets(),
                new RunnerShellEnvironment()
            ),
            $output
        );

        $before = $this->allCoreTableCounts();
        $arguments = [
            "source-root" => $source,
            "source-adapter" => "synthetic-private-note",
            "target-user-id" => (string) $userId,
            "target-library-id" => $target->libraryId()->value(),
            "require-empty" => true,
            "output-dir" => $outputDirectory,
        ];
        $command->dry_run([], $arguments);
        $first = json_decode($output->lines[0], true, 16, JSON_THROW_ON_ERROR);
        $command->dry_run([], $arguments);
        $second = json_decode($output->lines[1], true, 16, JSON_THROW_ON_ERROR);
        self::assertSame($before, $this->allCoreTableCounts());

        $firstArtifact = (string) file_get_contents($first["artifact_path"]);
        $secondArtifact = (string) file_get_contents($second["artifact_path"]);
        self::assertSame($firstArtifact, $secondArtifact);
        $artifact = json_decode($firstArtifact, true, 32, JSON_THROW_ON_ERROR);
        self::assertTrue($artifact["zero_write_confirmed"]);
        self::assertSame([[
            "operation" => "create_or_reuse_private_note",
        ]], $artifact["plan"]["records"][0]["operations"]);
        self::assertSame([
            [
                "source_id" => "work/dry-run",
                "source_type" => "catalog_work",
            ],
            [
                "source_id" => "round/dry-run",
                "source_type" => "reading_round",
            ],
        ], $artifact["plan"]["records"][0]["dependencies"]);
        self::assertStringNotContainsString(
            "private-note-body-must-not-appear",
            $firstArtifact
        );
        self::assertStringNotContainsString('"content"', $firstArtifact);
        self::assertStringNotContainsString('"created_at"', $firstArtifact);
        self::assertSame(0, $this->allCoreTableCounts()[
            $this->tableNames->privateNotes()
        ]);
    }

    private function command(
        CoreApplication $application,
        RecordingMigrationCommandOutput $output
    ): MigrationCommand {
        return new MigrationCommand(
            static fn (): CoreApplication => $application,
            dirname(__DIR__, 2) . "/biblio-core.php",
            static fn (CoreApplication $core): MigrationRunner => new MigrationRunner(
                new FilesystemMigrationSourcePackageFactory(),
                new MigrationSourceAdapterRegistry([new RunnerShellSourceAdapter()]),
                new MigrationParticipantRegistry([new RunnerShellParticipant()]),
                $core->personalMigrationTargets(),
                new RunnerShellEnvironment()
            ),
            $output
        );
    }

    /** @return array<string, int> */
    private function allCoreTableCounts(): array
    {
        $like = $this->database->esc_like($this->database->prefix . "biblio_") . "%";
        $tables = $this->database->get_col($this->database->prepare(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME",
            DB_NAME,
            $like
        ));
        $counts = [];
        foreach ($tables as $table) {
            $counts[(string) $table] = (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `" . esc_sql((string) $table) . "`"
            );
        }
        return $counts;
    }

    private function source(string $version): string
    {
        $directory = $this->directory();
        file_put_contents($directory . "/source.json", json_encode([
            "version" => $version,
            "records" => [["id" => "record-1", "value" => "synthetic"]],
        ], JSON_THROW_ON_ERROR));
        return $directory;
    }

    private function currentV1Source(): string
    {
        $directory = $this->directory();
        mkdir($directory . "/data", 0700, true);
        $this->writeCurrentV1Json($directory, "books.json", [
            "schemaVersion" => 29,
            "books" => [[
                "id" => "book-1",
                "title" => "Private Adapter Title",
                "authors" => ["Private Adapter Author"],
                "authorIds" => ["author-1"],
                "readingRounds" => [],
                "notes" => [],
                "circulationRounds" => [[
                    "id" => "circulation-private-1",
                    "type" => "borrowed",
                    "counterparty" => "Private Counterparty",
                    "startDate" => [
                        "value" => "2024-02-03",
                        "precision" => "day",
                    ],
                    "endDate" => null,
                    "notes" => "private circulation note",
                ]],
                "containedWorks" => [],
                "categories" => [],
                "genres" => [],
            ]],
            "copies" => [[
                "id" => "copy-1",
                "bookId" => "book-1",
                "circulationRounds" => [[
                    "id" => "circulation-private-1",
                    "type" => "borrowed",
                    "counterparty" => "Private Counterparty",
                    "startDate" => [
                        "value" => "2024-02-03",
                        "precision" => "day",
                    ],
                    "endDate" => [
                        "value" => "2024-02-04",
                        "precision" => "day",
                    ],
                    "notes" => "private circulation note",
                ]],
                "condition" => "",
                "status" => "owned",
                "ownershipStatus" => "owned",
                "archived" => false,
                "notes" => "",
            ]],
            "wishlistItems" => [[
                "id" => "wish-1",
                "bookId" => "book-1",
                "fulfilledCopyId" => "",
                "status" => "active",
                "type" => "edition",
            ]],
        ]);
        $this->writeCurrentV1Json($directory, "authors.json", [
            "schemaVersion" => 2,
            "authors" => [[
                "id" => "author-1",
                "displayName" => "Private Adapter Author",
            ]],
        ]);
        $this->writeCurrentV1Json($directory, "reading_goals.json", [
            "schemaVersion" => 2,
            "goals" => [["id" => "goal-1", "title" => "Private Adapter Goal"]],
        ]);
        foreach ([
            "book_types.json", "carriers.json", "categories.json", "genres.json",
            "releases_excluded.json", "releases_merged.json", "taxonomy_review_queue.json",
        ] as $file) {
            $this->writeCurrentV1Json($directory, $file, []);
        }
        foreach ([
            "book_enrich_cache.json" => [1, ["entries"]],
            "cache.json" => [1, ["entries"]],
            "home_prefs.json" => [1, ["hiddenWidgets", "widgetOrder"]],
            "recommendations_ignored.json" => [1, ["ignoredBookIds"]],
            "recommendations_prefs.json" => [2, ["ignored", "shown"]],
            "releases.json" => [1, ["byAuthor", "lastCheckedAt", "lastRun", "mergedDiff"]],
            "releases_prefs.json" => [1, ["trackedAuthorIds"]],
        ] as $file => [$version, $fields]) {
            $payload = ["schemaVersion" => $version];
            foreach ($fields as $field) {
                $payload[$field] = [];
            }
            $this->writeCurrentV1Json($directory, $file, $payload);
        }
        $this->writeCurrentV1Json($directory, "next_to_read.json", ["items" => []]);
        $this->writeCurrentV1Json($directory, "releases_dismissed.json", [
            "version" => 1,
            "dismissed" => [],
        ]);
        $this->writeCurrentV1Json($directory, "taxonomy_aliases.json", [
            "version" => 2,
            "rules" => [],
        ]);

        return $directory;
    }

    /** @param mixed $payload */
    private function writeCurrentV1Json(
        string $root,
        string $file,
        mixed $payload
    ): void {
        file_put_contents(
            $root . "/data/" . $file,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );
    }

    private function directory(): string
    {
        $path = sys_get_temp_dir() . "/biblio-mig-shell-" . bin2hex(random_bytes(8));
        mkdir($path, 0700, true);
        $this->temporaryDirectories[] = $path;
        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = scandir($directory);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === "." || $item === "..") {
                continue;
            }
            $path = $directory . "/" . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
