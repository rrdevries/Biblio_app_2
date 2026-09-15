<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Identity\MigrationTargetValidator;
use Biblio\Core\Application\Identity\PersonalMigrationTarget;
use Biblio\Core\Application\Identity\PersonalMigrationTargetInvalid;
use Biblio\Core\Application\Identity\PersonalMigrationTargetReadiness;
use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Migration\MigrationRecordOutcome;
use Biblio\Core\Application\Migration\Runner\MigrationBuildProvenance;
use Biblio\Core\Application\Migration\Runner\MigrationEnvironment;
use Biblio\Core\Application\Migration\Runner\MigrationParticipant;
use Biblio\Core\Application\Migration\Runner\MigrationParticipantRegistry;
use Biblio\Core\Application\Migration\Runner\MigrationPlanningTarget;
use Biblio\Core\Application\Migration\Runner\MigrationRunner;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerFailure;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerReason;
use Biblio\Core\Application\Migration\Runner\MigrationSourceAdapter;
use Biblio\Core\Application\Migration\Runner\MigrationSourceAdapterRegistry;
use Biblio\Core\Application\Migration\Runner\MigrationSourceFinding;
use Biblio\Core\Application\Migration\Runner\MigrationSourcePackage;
use Biblio\Core\Application\Migration\Runner\MigrationSourceProfile;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;
use Biblio\Core\Application\Migration\Runner\PlannedMigrationRecord;
use Biblio\Core\Application\Migration\SourceObservation;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\FilesystemMigrationArtifactWriter;
use Biblio\Core\Infrastructure\Migration\FilesystemMigrationSourcePackageFactory;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryName;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final readonly class SyntheticSourceAdapter implements MigrationSourceAdapter
{
    public function adapterId(): string { return "synthetic-v1"; }
    public function sourceFamily(): string { return "synthetic"; }

    public function profile(MigrationSourcePackage $package): MigrationSourceProfile
    {
        $decoded = json_decode($package->read("source.json"), true, 32, JSON_THROW_ON_ERROR);
        $records = is_array($decoded["records"] ?? null) ? $decoded["records"] : [];
        $counts = [];
        $findings = [];
        foreach ($records as $index => $record) {
            if (!is_array($record) || !is_string($record["type"] ?? null) || !is_string($record["id"] ?? null)) {
                $findings[] = new MigrationSourceFinding(
                    "malformed_record",
                    "source.json#records/{$index}",
                    "Synthetic record is malformed."
                );
                continue;
            }
            $counts[$record["type"]] = ($counts[$record["type"]] ?? 0) + 1;
        }

        return new MigrationSourceProfile(
            is_string($decoded["version"] ?? null) ? $decoded["version"] : "unknown",
            $counts,
            is_array($decoded["unknown_categories"] ?? null)
                ? $decoded["unknown_categories"]
                : [],
            $findings
        );
    }

    public function supportsVersion(string $sourceVersion): bool
    {
        return $sourceVersion === "test-1";
    }

    public function records(
        MigrationSourcePackage $package,
        MigrationSourceProfile $profile
    ): iterable {
        unset($profile);
        $decoded = json_decode($package->read("source.json"), true, 32, JSON_THROW_ON_ERROR);
        foreach ($decoded["records"] ?? [] as $record) {
            if (!is_array($record) || !is_string($record["type"] ?? null) || !is_string($record["id"] ?? null)) {
                continue;
            }
            yield new MigrationSourceRecord(
                $record["type"],
                $record["id"],
                is_array($record["payload"] ?? null) ? $record["payload"] : [],
                is_array($record["references"] ?? null) ? $record["references"] : []
            );
        }
    }
}

final readonly class SyntheticMigrationParticipant implements MigrationParticipant
{
    public function __construct(
        private string $type,
        private bool $fail = false,
        private bool $preserve = false,
        private bool $quarantine = false
    ) {
    }

    public function sourceType(): string { return $this->type; }

    public function plan(
        MigrationSourceRecord $record,
        MigrationPlanningTarget $target
    ): PlannedMigrationRecord {
        if ($this->fail) {
            throw new RuntimeException("Synthetic planning failure with hidden payload.");
        }
        if ($this->preserve) {
            return new PlannedMigrationRecord(
                MigrationDisposition::PreservedDeferred,
                [],
                "future_participant",
                $record->references()
            );
        }
        if ($this->quarantine) {
            return new PlannedMigrationRecord(
                MigrationDisposition::Quarantined,
                [],
                "unresolved_reference",
                $record->references(),
                "Synthetic reference cannot be resolved."
            );
        }
        return new PlannedMigrationRecord(
            MigrationDisposition::Mapped,
            [[
                "operation" => "synthetic_map",
                "target_type" => "synthetic_target",
                "target_id" => $target->libraryId() . ":" . $record->sourceId(),
            ]]
        );
    }

    public function apply(
        MigrationSourceRecord $record,
        SourceObservation $observation,
        PlannedMigrationRecord $plan,
        MigrationPlanningTarget $target
    ): MigrationRecordOutcome {
        unset($record, $observation, $plan, $target);
        return MigrationRecordOutcome::failed("not_executable_in_run_01", false);
    }
}

final class SyntheticTargetValidator implements MigrationTargetValidator
{
    public int $calls = 0;

    public function __construct(
        private bool $valid = true,
        private bool $clean = true
    ) {
    }

    public function validate(UserId $targetUserId, LibraryId $targetLibraryId): PersonalMigrationTarget
    {
        ++$this->calls;
        if (!$this->valid) {
            throw new PersonalMigrationTargetInvalid("Synthetic target invalid.");
        }
        return new PersonalMigrationTarget(
            $targetUserId,
            $targetLibraryId,
            LibraryName::personalDefault(),
            new PersonalMigrationTargetReadiness([
                "synthetic" => $this->clean ? 0 : 1,
            ])
        );
    }
}

final class SyntheticMigrationEnvironment implements MigrationEnvironment
{
    public function __construct(private bool $healthy = true)
    {
    }

    public function assertHealthy(): void
    {
        if (!$this->healthy) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::UnhealthySchema,
                "Synthetic schema is unhealthy."
            );
        }
    }

    public function provenance(): MigrationBuildProvenance
    {
        return new MigrationBuildProvenance(
            "v2.001",
            1025,
            "2.30.0",
            str_repeat("a", 40),
            false
        );
    }
}

final class MigrationRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            $this->removeDirectory($directory);
        }
        parent::tearDown();
    }

    public function testSourceManifestIsDeterministicOrderedAndByteSensitive(): void
    {
        $root = $this->directory();
        mkdir($root . "/nested");
        file_put_contents($root . "/z.txt", "last\n");
        file_put_contents($root . "/nested/a.txt", "first\n");
        $factory = new FilesystemMigrationSourcePackageFactory();

        $first = $factory->build($root);
        $second = $factory->build($root);

        self::assertSame($first->manifestDigest(), $second->manifestDigest());
        self::assertSame(
            ["nested/a.txt", "z.txt"],
            array_map(static fn ($file): string => $file->relativePath(), $first->files())
        );
        file_put_contents($root . "/z.txt", "changed\n");
        self::assertNotSame(
            $first->manifestDigest(),
            $factory->build($root)->manifestDigest()
        );
        self::assertSame("first\n", $first->read("nested/a.txt"));
        self::assertFileDoesNotExist($root . "/source-profile.json");
    }

    public function testSourcePackageFailsClosedForMissingUnreadableAndSymlinkInput(): void
    {
        $factory = new FilesystemMigrationSourcePackageFactory();
        try {
            $factory->build($this->directory() . "/missing");
            self::fail("Missing source should fail.");
        } catch (MigrationRunnerFailure $failure) {
            self::assertSame(MigrationRunnerReason::SourceMissing, $failure->reason());
        }

        $root = $this->directory();
        file_put_contents($root . "/locked.txt", "locked");
        chmod($root . "/locked.txt", 0000);
        try {
            $factory->build($root);
            self::fail("Unreadable source should fail.");
        } catch (MigrationRunnerFailure $failure) {
            self::assertSame(MigrationRunnerReason::SourceUnreadable, $failure->reason());
        } finally {
            chmod($root . "/locked.txt", 0600);
        }

        $root = $this->directory();
        $outside = $this->directory();
        file_put_contents($outside . "/outside.txt", "outside");
        if (symlink($outside . "/outside.txt", $root . "/escape")) {
            try {
                $factory->build($root);
                self::fail("Symlink source should fail.");
            } catch (MigrationRunnerFailure $failure) {
                self::assertSame(MigrationRunnerReason::SourceUnsafe, $failure->reason());
            }
        }
    }

    public function testPackageDetectsBytesChangedAfterProfile(): void
    {
        $root = $this->directory();
        file_put_contents($root . "/source.json", "one");
        $package = (new FilesystemMigrationSourcePackageFactory())->build($root);
        file_put_contents($root . "/source.json", "two");

        $this->expectException(MigrationRunnerFailure::class);
        $this->expectExceptionMessage("changed after profiling");
        $package->read("source.json");
    }

    public function testAdapterVersionIdentityHashAndEnumerationAreDeterministic(): void
    {
        $root = $this->source([
            ["type" => "supported", "id" => "b", "payload" => ["x" => 2]],
            ["type" => "supported", "id" => "a", "payload" => ["x" => 1]],
        ]);
        $runner = $this->runner(new SyntheticTargetValidator(), [
            new SyntheticMigrationParticipant("supported"),
        ]);

        $first = $runner->dryRun($root, "synthetic-v1", new UserId("7"), new LibraryId("lib-7"), true);
        $second = $runner->dryRun($root, "synthetic-v1", new UserId("7"), new LibraryId("lib-7"), true);
        $records = $first->payload()["plan"]["records"];

        self::assertSame($first->canonicalJson(), $second->canonicalJson());
        self::assertSame(["a", "b"], array_column($records, "source_id"));
        self::assertSame(64, strlen($records[0]["payload_hash"]));

        $changed = new MigrationSourceRecord("supported", "a", ["x" => 2]);
        self::assertNotSame($records[0]["payload_hash"], $changed->payloadHash());
    }

    public function testUnsupportedVersionStopsBeforeTargetValidation(): void
    {
        $root = $this->source([], "not-supported");
        $target = new SyntheticTargetValidator();
        $runner = $this->runner($target, []);

        try {
            $runner->dryRun($root, "synthetic-v1", new UserId("7"), new LibraryId("lib-7"), true);
            self::fail("Unsupported version should fail.");
        } catch (MigrationRunnerFailure $failure) {
            self::assertSame(MigrationRunnerReason::UnsupportedVersion, $failure->reason());
            self::assertSame(0, $target->calls);
        }
    }

    public function testProfileSurfacesUnknownAndMalformedCategories(): void
    {
        $root = $this->source([
            ["type" => "supported", "id" => "1", "payload" => []],
            ["malformed" => true],
        ], "test-1", ["mystery"]);
        $profile = $this->runner(new SyntheticTargetValidator(), [])->profile(
            $root,
            "synthetic-v1"
        )->payload();

        self::assertSame(["mystery"], $profile["source"]["unknown_categories"]);
        self::assertSame(
            "malformed_record",
            $profile["source"]["findings"][0]["reason_code"]
        );
        self::assertTrue($profile["zero_write_confirmed"]);
    }

    public function testRegistrySurfacesUnsupportedPreservedErrorsAndReferences(): void
    {
        $root = $this->source([
            ["type" => "supported", "id" => "2", "payload" => []],
            ["type" => "supported", "id" => "1", "payload" => []],
            ["type" => "deferred", "id" => "3", "payload" => [], "references" => ["missing:9"]],
            ["type" => "error", "id" => "4", "payload" => []],
            ["type" => "unknown", "id" => "5", "payload" => []],
            ["type" => "quarantine", "id" => "6", "payload" => []],
        ]);
        $artifact = $this->runner(new SyntheticTargetValidator(), [
            new SyntheticMigrationParticipant("supported"),
            new SyntheticMigrationParticipant("deferred", false, true),
            new SyntheticMigrationParticipant("error", true),
            new SyntheticMigrationParticipant("quarantine", false, false, true),
        ])->dryRun(
            $root,
            "synthetic-v1",
            new UserId("7"),
            new LibraryId("lib-7"),
            true
        )->payload();

        self::assertSame(["unknown"], $artifact["plan"]["unsupported_source_types"]);
        self::assertSame(2, $artifact["plan"]["disposition_counts"]["mapped"]);
        self::assertSame(1, $artifact["plan"]["disposition_counts"]["preserved_deferred"]);
        self::assertCount(1, $artifact["plan"]["preservation_candidates"]);
        self::assertCount(1, $artifact["plan"]["quarantine_candidates"]);
        self::assertSame("participant_planning_error", $artifact["plan"]["planning_errors"][0]["reason_code"]);
        self::assertSame("missing:9", $artifact["plan"]["unmatched_references"][0]["reference"]);
    }

    public function testDuplicateParticipantOwnershipFailsClosed(): void
    {
        $this->expectException(MigrationRunnerFailure::class);
        $this->expectExceptionMessage("More than one participant owns");
        new MigrationParticipantRegistry([
            new SyntheticMigrationParticipant("same"),
            new SyntheticMigrationParticipant("same"),
        ]);
    }

    public function testInvalidAndNonEmptyTargetsFailClosedWithoutFallback(): void
    {
        $root = $this->source([]);
        foreach ([
            new SyntheticTargetValidator(false),
            new SyntheticTargetValidator(true, false),
        ] as $target) {
            try {
                $this->runner($target, [])->dryRun(
                    $root,
                    "synthetic-v1",
                    new UserId("991"),
                    new LibraryId("explicit-library"),
                    true
                );
                self::fail("Invalid target should fail.");
            } catch (MigrationRunnerFailure $failure) {
                self::assertSame(MigrationRunnerReason::InvalidTarget, $failure->reason());
                self::assertSame(1, $target->calls);
            }
        }
    }

    public function testArtifactIsDeterministicChecksummedAndOutsideSource(): void
    {
        $source = $this->source([]);
        $output = $this->directory();
        $artifact = $this->runner(new SyntheticTargetValidator(), [])->profile(
            $source,
            "synthetic-v1"
        );
        $writer = new FilesystemMigrationArtifactWriter();

        $first = $writer->write($artifact, $output, $source);
        $bytes = file_get_contents($first->artifactPath());
        $second = $writer->write($artifact, $output, $source);

        self::assertSame($first->artifactPath(), $second->artifactPath());
        self::assertSame($bytes, file_get_contents($second->artifactPath()));
        self::assertSame(hash("sha256", (string) $bytes), $first->checksum());
        self::assertStringContainsString($first->checksum(), (string) file_get_contents($first->checksumPath()));
        self::assertStringNotContainsString("secret", (string) $bytes);

        $this->expectException(MigrationRunnerFailure::class);
        try {
            $writer->write($artifact, $source . "/artifacts", $source);
        } finally {
            self::assertDirectoryDoesNotExist($source . "/artifacts");
        }
    }

    /** @param list<MigrationParticipant> $participants */
    private function runner(
        MigrationTargetValidator $target,
        array $participants,
        ?MigrationEnvironment $environment = null
    ): MigrationRunner {
        return new MigrationRunner(
            new FilesystemMigrationSourcePackageFactory(),
            new MigrationSourceAdapterRegistry([new SyntheticSourceAdapter()]),
            new MigrationParticipantRegistry($participants),
            $target,
            $environment ?? new SyntheticMigrationEnvironment()
        );
    }

    /**
     * @param list<array<string, mixed>> $records
     * @param list<string> $unknown
     */
    private function source(
        array $records,
        string $version = "test-1",
        array $unknown = []
    ): string {
        $root = $this->directory();
        file_put_contents($root . "/source.json", json_encode([
            "version" => $version,
            "records" => $records,
            "unknown_categories" => $unknown,
        ], JSON_THROW_ON_ERROR));
        return $root;
    }

    private function directory(): string
    {
        $path = sys_get_temp_dir() . "/biblio-mig-run-" . bin2hex(random_bytes(8));
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
