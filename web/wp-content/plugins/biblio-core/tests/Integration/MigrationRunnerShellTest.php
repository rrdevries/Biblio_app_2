<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Migration\MigrationRecordOutcome;
use Biblio\Core\Application\Migration\Runner\MigrationBuildProvenance;
use Biblio\Core\Application\Migration\Runner\MigrationEnvironment;
use Biblio\Core\Application\Migration\Runner\MigrationParticipant;
use Biblio\Core\Application\Migration\Runner\MigrationParticipantRegistry;
use Biblio\Core\Application\Migration\Runner\MigrationPlanningTarget;
use Biblio\Core\Application\Migration\Runner\MigrationRunner;
use Biblio\Core\Application\Migration\Runner\MigrationSourceAdapter;
use Biblio\Core\Application\Migration\Runner\MigrationSourceAdapterRegistry;
use Biblio\Core\Application\Migration\Runner\MigrationSourcePackage;
use Biblio\Core\Application\Migration\Runner\MigrationSourceProfile;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;
use Biblio\Core\Application\Migration\Runner\PlannedMigrationRecord;
use Biblio\Core\Application\Migration\SourceObservation;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\FilesystemMigrationSourcePackageFactory;
use Biblio\Core\Infrastructure\WordPress\Cli\MigrationCommand;
use Biblio\Core\Infrastructure\WordPress\Cli\MigrationCommandOutput;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\LibraryId;
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

final readonly class RunnerShellEnvironment implements MigrationEnvironment
{
    public function assertHealthy(): void
    {
    }

    public function provenance(): MigrationBuildProvenance
    {
        return new MigrationBuildProvenance(
            "v2.001",
            1025,
            "2.28.0",
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
