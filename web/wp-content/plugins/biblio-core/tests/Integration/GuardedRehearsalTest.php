<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Migration\Cutover\{GuardedRehearsal, RehearsalAuthorization, RehearsalBackupStore, RehearsalContract, RehearsalFailure, RehearsalFault, RehearsalProductVerifier, RehearsalTarget};
use Biblio\Core\Application\Migration\Runner\{DeterministicJson, MigrationBuildProvenance, MigrationEnvironment, MigrationPlanningTarget};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Migration\{RehearsalComposition, RehearsalDatabaseState, RehearsalEvidenceDirectory};
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Tests\Support\RehearsalFixture;
use PHPUnit\Framework\Attributes\DataProvider;

require_once dirname(__DIR__) . "/Support/RehearsalFixture.php";

final class GuardedRehearsalTest extends PersistenceIntegrationTestCase
{
    private string $directory;
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . "/biblio-rehearsal-test-" . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        if ($this->userId !== 0) {
            $this->database->delete($this->database->usermeta, ["user_id" => $this->userId]);
            $this->database->delete($this->database->users, ["ID" => $this->userId]);
        }
        RehearsalFixture::remove($this->directory);
        parent::tearDown();
    }

    public static function faults(): array
    {
        return [[RehearsalFault::None, false, false], [RehearsalFault::AfterProductCommit, false, false], [RehearsalFault::AfterPreservationCommit, false, false],
            [RehearsalFault::AfterProductCommit, true, false], [RehearsalFault::AfterPreservationCommit, true, false], [RehearsalFault::None, false, true],
            [RehearsalFault::None, false, false, true], [RehearsalFault::None, false, false, false, true],
            [RehearsalFault::None, false, false, false, false, true],
            "numeric ISBN target" => [RehearsalFault::None, false, false, false, false, false, true]];
    }

    #[DataProvider("faults")]
    public function testRealCandidateApplyResumeReplayAndExactRollback(RehearsalFault $fault, bool $rollbackInterrupted, bool $quarantine, bool $mutateMembership = false, bool $containedSeries = false, bool $containedIsbn = false, bool $canonicalIsbn = false): void
    {
        $this->userId = wp_create_user("rehearsal-" . bin2hex(random_bytes(4)), "synthetic-only", "rehearsal-" . bin2hex(random_bytes(4)) . "@example.invalid");
        $user = new UserId((string) $this->userId);
        $app = (new ProductionComposition($this->database))->application();
        $validated = $app->personalMigrationTargets()->bootstrap($user);
        $library = $validated->libraryId();
        $source = RehearsalFixture::source($this->directory, $quarantine, $containedSeries, $containedIsbn, $canonicalIsbn);
        $state = new RehearsalDatabaseState($this->database, $this->tableNames);
        // Injection is confined to tests: real target/environment refusal is tested separately.
        $target = new class($state, $user->value(), $library->value()) implements RehearsalTarget {
            public function __construct(private RehearsalDatabaseState $state, private string $user, private string $library) {}
            public function identity(): array { return ["fixture" => true, "target_user_id" => $this->user, "target_library_id" => $this->library]; }
            public function fingerprint(): array { return $this->state->fingerprint(); }
            public function assertEmpty(): void { $this->state->assertEmpty($this->user, $this->library); }
            public function acquire(): void {}
            public function release(): void {}
        };
        $environment = new class implements MigrationEnvironment {
            public function assertHealthy(): void {}
            public function provenance(): MigrationBuildProvenance { return new MigrationBuildProvenance("v2.001", 1026, "2.50.0", str_repeat("a", 40), false); }
        };
        $backups = new RehearsalFixtureBackups($this->database, $target, $mutateMembership);
        mkdir($this->directory . "/evidence", 0700);
        $composition = new RehearsalComposition($this->database, $app, $environment);
        $prepared = $composition->planner($source)->prepare($composition->planner($source)->inspectSource($source->intake->extractionRoot(), $source->intake->identity()->adapterId()), new MigrationPlanningTarget($validated));
        self::assertSame([], array_map(static fn ($failure) => [$failure->record()->sourceType(), $failure->reasonCode()], $prepared->failures()));
        self::assertSame([], array_values(array_map(static fn ($finding) => [$finding->sourceType(), $finding->sourceId(), $finding->reasonCode()],
            array_filter($prepared->findings(), static fn ($finding) => in_array($finding->disposition()->value, ["failed", "quarantined"], true)))));
        $isolation = new class implements \Biblio\Core\Application\Migration\Cutover\RehearsalIsolationGuard {
            public function fingerprint(): array { return ["test_only_protected_state" => str_repeat("a", 64)]; }
        };
        $rehearsal = $composition->create($source, $target, $backups, new RehearsalEvidenceDirectory($this->directory . "/evidence"), $isolation, $user, $library);
        if ($quarantine) {
            $baseline = $target->fingerprint();
            foreach (["missing", "reason_code", "evidence_hash"] as $change) {
                $review = $source->review;
                if ($change === "missing") {
                    $review["quarantine"] = [];
                } else {
                    $review["quarantine"][0][$change] = $change === "evidence_hash" ? str_repeat("f", 64) : "changed_reason";
                }
                $wrongSource = new \Biblio\Core\Application\Migration\Cutover\ApprovedRehearsalSource(
                    $source->intake, $source->bundle, $review, $this->directory . "/synthetic.zip"
                );
                $wrongRehearsal = $composition->create($wrongSource, $target, $backups,
                    new RehearsalEvidenceDirectory($this->directory . "/evidence"), $isolation, $user, $library);
                try { $wrongRehearsal->preflight(); self::fail("Unreviewed quarantine accepted"); }
                catch (RehearsalFailure $failure) { self::assertSame("quarantine_not_reviewed", $failure->reason); }
                self::assertSame($baseline, $target->fingerprint());
            }
        }
        $packet = $rehearsal->preflight();
        self::assertCount(57, $packet["preflight"]["baseline"]["biblio_tables"]);
        $confirmation = RehearsalAuthorization::confirmationFor($packet["preflight"], $packet["pre_apply_backup"], true, $fault);
        $authorization = new RehearsalAuthorization($packet["preflight"], $packet["pre_apply_backup"], "synthetic-operator-review", $confirmation, true, $fault);
        if ($mutateMembership) {
            try { $rehearsal->execute($authorization); self::fail("Membership byte drift accepted"); }
            catch (RehearsalFailure $failure) { self::assertSame("nonmigration_state_changed", $failure->reason); }
            $rehearsal->rollback($authorization);
            self::assertSame($packet["preflight"]["baseline"], $target->fingerprint());
            return;
        }
        try { $result = $rehearsal->execute($authorization); }
        catch (RehearsalFailure $failure) { $this->reportFailure($failure); }
        if ($fault !== RehearsalFault::None) {
            self::assertSame("interrupted", $result["checkpoint"]["status"]);
            if ($rollbackInterrupted) {
                $rehearsal->rollback($authorization);
                self::assertSame($packet["preflight"]["baseline"], $target->fingerprint());
                return;
            }
            $interrupted = $target->fingerprint();
            $wrong = $result["checkpoint"];
            $wrong["intent_digest"] = str_repeat("f", 64);
            try { $rehearsal->execute($authorization, "resume", $wrong); self::fail("Divergent resume accepted"); }
            catch (RehearsalFailure) { self::assertSame($interrupted, $target->fingerprint()); }
            try { $result = $rehearsal->execute($authorization, "resume", $result["checkpoint"]); }
            catch (RehearsalFailure $failure) { $this->reportFailure($failure); }
        }
        self::assertSame("completed", $result["checkpoint"]["status"]);
        self::assertContains("post_apply_quarantine_verification", $result["phases"]);
        self::assertContains("post_apply_product_verification", $result["phases"]);
        self::assertContains("post_apply_verification", $result["phases"]);
        self::assertSame($quarantine ? "accepted_with_reviewed_quarantine" : "accepted", $result["classification"]);
        try { $rehearsal->preflight(); self::fail("Nonempty target accepted"); }
        catch (RehearsalFailure $failure) { self::assertSame("target_not_empty", $failure->reason); }
        self::assertSame("POST_APPLY", $result["post_backup"]["phase"]);
        self::assertSame(1, $result["verification"]["counts"]["item"]);
        self::assertSame(1, $result["verification"]["counts"]["private_note"]);
        self::assertGreaterThan(0, $result["verification"]["restricted_evidence_verified"]);
        if ($canonicalIsbn) {
            self::assertSame(1, $result["verification"]["counts"]["canonical_isbn"]);
            self::assertSame("9780306406157", $result["verification"]["qa_candidates"]["canonical_isbn"]);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result["verification"]["target_identity_set_sha256"]);
        }
        if ($containedIsbn) {
            self::assertSame(7, $result["verification"]["counts"]["work"]);
            self::assertSame(2, $result["verification"]["counts"]["edition"]);
            self::assertSame(5, (int) $this->database->get_var("SELECT COUNT(*) FROM `{$this->tableNames->migrationPreservations()}` WHERE reason_code='contained_work_isbn_deferred'"));
            self::assertSame(0, (int) $this->database->get_var("SELECT COUNT(*) FROM `{$this->tableNames->migrationTargetMappings()}` m JOIN `{$this->tableNames->migrationSourceObservations()}` o ON m.observation_id=o.observation_id WHERE o.reason_code='contained_work_isbn_deferred'"));
        }
        if ($containedSeries) {
            $this->assertContainedSeriesReplayGuards($prepared, $result["checkpoint"]["run_id"], $target);
        }
        $replay = $rehearsal->execute($authorization, "replay", $result["checkpoint"]);
        self::assertTrue($replay["replay_zero_state_delta"]);
        self::assertSame($result["verification"]["target_identity_set_sha256"], $replay["verification"]["target_identity_set_sha256"]);
        self::assertSame($result["checkpoint"]["fingerprint"], $replay["checkpoint"]["fingerprint"]);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory . "/evidence", \FilesystemIterator::SKIP_DOTS)) as $file) {
            self::assertStringNotContainsString("synthetic-private-", file_get_contents($file->getPathname()));
        }
        if ($fault === RehearsalFault::None && !$quarantine) {
            $otherUser = wp_create_user("neutral-" . bin2hex(random_bytes(4)), "synthetic-only", "neutral-" . bin2hex(random_bytes(4)) . "@example.invalid");
            try {
                $otherTarget = $app->personalMigrationTargets()->bootstrap(new UserId((string) $otherUser));
                $otherPlan = $composition->planner($source)->prepare($prepared->inspection(), new MigrationPlanningTarget($otherTarget));
                self::assertNotSame($prepared->planSetDigest(), $otherPlan->planSetDigest());
                $neutral = \Biblio\Core\Application\Migration\Cutover\RehearsalPlanEvidence::describe($otherPlan, []);
                self::assertSame($packet["preflight"]["plan"]["target_neutral_plan_digest"], $neutral["target_neutral_plan_digest"]);
            } finally {
                $this->database->delete($this->database->usermeta, ["user_id" => $otherUser]);
                $this->database->delete($this->database->users, ["ID" => $otherUser]);
            }
        }
        try { $rehearsal->rollback($authorization); }
        catch (RehearsalFailure $failure) {
            $diff = [];
            foreach ($target->fingerprint()["tables"] as $table => $values) {
                if ($values !== $packet["preflight"]["baseline"]["tables"][$table]) {
                    $diff[$table] = [$packet["preflight"]["baseline"]["tables"][$table], $values];
                }
            }
            self::fail($failure->reason . ": " . json_encode($diff, JSON_THROW_ON_ERROR));
        }
        self::assertSame($packet["preflight"]["baseline"], $target->fingerprint());
    }

    /** @return list<array{string}> */
    public static function diagnosticSubphases(): array
    {
        return [["quarantine"], ["product"]];
    }

    #[DataProvider("diagnosticSubphases")]
    public function testOriginalPostApplyThrowableIsPreservedWithoutPrivateEvidence(string $subphase): void
    {
        $this->userId = wp_create_user("rehearsal-" . bin2hex(random_bytes(4)), "synthetic-only", "rehearsal-" . bin2hex(random_bytes(4)) . "@example.invalid");
        $user = new UserId((string) $this->userId);
        $app = (new ProductionComposition($this->database))->application();
        $library = $app->personalMigrationTargets()->bootstrap($user)->libraryId();
        $source = RehearsalFixture::source($this->directory, true);
        $state = new RehearsalDatabaseState($this->database, $this->tableNames);
        $target = new class($state, $user->value(), $library->value()) implements RehearsalTarget {
            public function __construct(private RehearsalDatabaseState $state, private string $user, private string $library) {}
            public function identity(): array { return ["fixture" => true, "target_user_id" => $this->user, "target_library_id" => $this->library]; }
            public function fingerprint(): array { return $this->state->fingerprint(); }
            public function assertEmpty(): void { $this->state->assertEmpty($this->user, $this->library); }
            public function acquire(): void {}
            public function release(): void {}
        };
        $environment = new class implements MigrationEnvironment {
            public function assertHealthy(): void {}
            public function provenance(): MigrationBuildProvenance { return new MigrationBuildProvenance("v2.001", 1026, "2.50.0", str_repeat("a", 40), false); }
        };
        $backups = new RehearsalFixtureBackups($this->database, $target);
        mkdir($this->directory . "/evidence", 0700);
        $evidence = new RehearsalEvidenceDirectory($this->directory . "/evidence");
        $isolation = new class implements \Biblio\Core\Application\Migration\Cutover\RehearsalIsolationGuard {
            public function fingerprint(): array { return ["test_only_protected_state" => str_repeat("a", 64)]; }
        };
        $composition = new RehearsalComposition($this->database, $app, $environment);
        $original = $composition->create($source, $target, $backups, $evidence, $isolation, $user, $library);
        $packet = $original->preflight();
        $authorization = new RehearsalAuthorization($packet["preflight"], $packet["pre_apply_backup"], "synthetic-operator-review",
            RehearsalAuthorization::confirmationFor($packet["preflight"], $packet["pre_apply_backup"], true), true);

        // The apply runner retains its real test ledger; only the observer's
        // post-apply read and verifier are faulted for these focused assertions.
        $property = static fn (string $name): mixed => (new \ReflectionObject($original))->getProperty($name)->getValue($original);
        $realLedger = $property("ledger");
        $snapshots = 0;
        $ledger = $this->createMock(\Biblio\Core\Application\Migration\MigrationLedgerRepository::class);
        $private = "synthetic-private-Note-body and credential=forbidden";
        $ledger->method("snapshot")->willReturnCallback(static function (string $runId) use ($realLedger, &$snapshots, $subphase, $private) {
            if ($subphase === "quarantine" && ++$snapshots === 2) { throw new \RuntimeException($private); }
            return $realLedger->snapshot($runId);
        });
        $products = $this->createMock(RehearsalProductVerifier::class);
        $products->method("verify")->willReturnCallback(static function () use ($subphase, $private): array {
            if ($subphase === "product") { throw new \RuntimeException($private); }
            throw new \LogicException("Unexpected product verifier call after quarantine failure.");
        });
        $guard = new GuardedRehearsal($property("source"), $property("packages"), $property("planner"), $property("apply"),
            $property("targets"), $target, $backups, $ledger, $products, $evidence, $isolation, $user, $library);
        try { $guard->execute($authorization); self::fail("Injected post-apply failure was accepted."); }
        catch (RehearsalFailure $failure) {
            self::assertSame("apply_or_verification_failed", $failure->reason);
            self::assertInstanceOf(\RuntimeException::class, $failure->getPrevious());
            self::assertSame($private, $failure->getPrevious()->getMessage());
        }
        $files = glob($this->directory . "/evidence/rehearsal-failure-*/*.json");
        self::assertIsArray($files);
        self::assertCount(1, $files);
        $bytes = file_get_contents($files[0]);
        self::assertIsString($bytes);
        self::assertStringNotContainsString($private, $bytes);
        $record = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR)["evidence"];
        self::assertSame($subphase === "quarantine" ? "post_apply_quarantine_verification" : "post_apply_product_verification", $record["failure"]["subphase"]);
        self::assertSame(\RuntimeException::class, $record["failure"]["original"]["class"]);
        self::assertSame("[redacted]", $record["failure"]["original"]["sanitized_message"]);
        self::assertSame(hash("sha256", $private), $record["failure"]["original"]["message_sha256"]);
    }

    private function assertContainedSeriesReplayGuards(\Biblio\Core\Application\Migration\Runner\PreparedMigrationPlan $prepared, string $runId, RehearsalTarget $target): void
    {
        $items = array_values(array_filter($prepared->records(), static fn ($item): bool =>
            $item->record()->typedPlan() instanceof \Biblio\Core\Application\Migration\Series\CatalogWorkSeriesPlan
            && $item->record()->typedPlan()->promotedPriorPreservation() !== null));
        self::assertCount(1, $items);
        $item = $items[0];
        $typed = $item->record()->typedPlan();
        self::assertNotSame(\Biblio\Core\Infrastructure\Migration\CurrentV1ReviewedSeriesContract::MANIFEST_SHA256,
            $typed->promotedPriorPreservation()->manifestSha256());
        self::assertSame($prepared->inspection()->package()->manifestDigest(), $typed->promotedPriorPreservation()->manifestSha256());
        $ledger = new \Biblio\Core\Infrastructure\Persistence\WordPress\WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $run = $ledger->findRun($runId);
        self::assertNotNull($run);
        $record = $item->record();
        $observation = \Biblio\Core\Application\Migration\SourceObservation::observe($run, $record->sourceType(), $record->sourceId(),
            $record->payloadHash(), $record->payload(), null, new \DateTimeImmutable("2026-09-24T12:00:00+00:00"));
        $before = $target->fingerprint();
        $exact = $item->participant()->apply($record, $observation, $item->plan(), $prepared->target());
        self::assertSame(\Biblio\Core\Application\Migration\MappingDisposition::Reused, $exact->mappings()[0]->disposition());
        foreach (["work", "series", "position", "contract", "manifest"] as $change) {
            $preservation = $typed->promotedPriorPreservation();
            if ($change === "manifest") {
                $preservation = new \Biblio\Core\Application\Migration\Preservation\PreservedSourceEvidencePlan(
                    $preservation->sourceIdentity(), $preservation->evidenceType(), $preservation->reasonCode(),
                    $preservation->adapterId(), $preservation->sourceFamily(), $preservation->sourceVersion(), str_repeat("f", 64),
                    $preservation->mappingContract(), $preservation->sourceFile(), $preservation->sourceCollection(),
                    $preservation->sourceEntityId(), $preservation->sourceField(), $preservation->evidenceSha256(), $preservation->privacy()
                );
            }
            $changed = \Biblio\Core\Application\Migration\Runner\MigrationSourceRecord::typed($record->sourceType(), $record->sourceId(),
                new \Biblio\Core\Application\Migration\Series\CatalogWorkSeriesPlan(
                    $change === "work" ? "unrelated-work" : $typed->workSourceId(),
                    $change === "series" ? "unrelated-series" : $typed->seriesSourceId(),
                    $change === "position" ? \Biblio\Core\Catalog\SeriesPosition::known("3") : $typed->position(),
                    $change === "contract" ? "unrelated-contract" : $typed->mappingContract(), $preservation
                ));
            try {
                $item->participant()->apply($changed, $observation, $item->participant()->plan($changed, $prepared->target()), $prepared->target());
                self::fail("Divergent contained-Series replay accepted: " . $change);
            } catch (\Biblio\Core\Application\Migration\Series\SeriesMigrationFailure $failure) {
                self::assertSame(\Biblio\Core\Application\Migration\Series\SeriesMigrationReason::DivergentReplay, $failure->reason());
            }
            self::assertSame($before, $target->fingerprint());
            if ($change === "position") {
                $changedObservation = \Biblio\Core\Application\Migration\SourceObservation::observe($run, $changed->sourceType(), $changed->sourceId(),
                    $changed->payloadHash(), $changed->payload(), null, $observation->createdAt());
                try {
                    $item->participant()->apply($changed, $changedObservation, $item->participant()->plan($changed, $prepared->target()), $prepared->target());
                    self::fail("Self-consistent changed payload reused a prior mapping.");
                } catch (\Biblio\Core\Application\Migration\Series\SeriesMigrationFailure $failure) {
                    self::assertSame(\Biblio\Core\Application\Migration\Series\SeriesMigrationReason::DivergentReplay, $failure->reason());
                }
                self::assertSame($before, $target->fingerprint());
            }
        }
    }

    private function reportFailure(RehearsalFailure $failure): never
    {
        $reports = [];
        foreach (glob($this->directory . "/evidence/rehearsal-reconciliation-*/*.json") ?: [] as $file) {
            $reports[] = json_decode(file_get_contents($file), true)["evidence"]["report"];
        }
        self::fail($failure->reason . ": " . json_encode($reports, JSON_THROW_ON_ERROR));
    }
}

/** Test-only snapshot transport; native gzip/MariaDB restore has its own disposable acceptance harness. */
final class RehearsalFixtureBackups implements RehearsalBackupStore
{
    private array $snapshots = [];
    public function __construct(private \wpdb $db, private RehearsalTarget $target, private bool $mutateMembership = false) {}
    public function create(string $phase, array $binding): array
    {
        if (DB_NAME !== "biblio_core_test") { throw new \LogicException("Isolated test DB required"); }
        $id = bin2hex(random_bytes(8));
        $tables = [];
        foreach ($this->db->get_col("SHOW TABLES") as $table) {
            $tables[$table] = [$this->db->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_A)["Create Table"], $this->db->get_results("SELECT * FROM `{$table}`", ARRAY_A)];
        }
        $receipt = ["backup_id" => $id, "phase" => $phase, "binding" => DeterministicJson::hash($binding), "baseline" => $this->target->fingerprint()];
        $triggers = [];
        foreach ($this->db->get_results("SHOW TRIGGERS", ARRAY_A) as $trigger) {
            $triggers[] = $this->db->get_row("SHOW CREATE TRIGGER `{$trigger['Trigger']}`", ARRAY_A)["SQL Original Statement"];
        }
        $this->snapshots[$id] = [$receipt, $tables, $triggers];
        if ($phase === "POST_APPLY" && $this->mutateMembership) {
            // Deliberate test-only byte drift after apply, with identical JSON semantics.
            RehearsalContract::require($this->db->query("UPDATE `{$this->db->prefix}biblio_library_memberships` SET additional_permissions=CONCAT(additional_permissions,' ')") === 1, "fixture_membership_mutation_failed");
        }
        return $receipt;
    }
    public function verify(array $receipt, array $binding): void
    {
        RehearsalContract::equal($this->snapshots[$receipt["backup_id"]][0], $receipt, "test_backup_changed");
        RehearsalContract::equal($receipt["binding"], DeterministicJson::hash($binding), "test_binding_changed");
    }
    public function restore(array $receipt, array $binding): void
    {
        $this->verify($receipt, $binding);
        if (DB_NAME !== "biblio_core_test") { throw new \LogicException("Isolated test DB required"); }
        $suppressed = $this->db->suppress_errors(true);
        $this->db->query("SET FOREIGN_KEY_CHECKS=0");
        try {
            foreach (array_keys($this->snapshots[$receipt["backup_id"]][1]) as $table) {
                RehearsalContract::require($this->db->query("DROP TABLE `{$table}`") !== false, "fixture_restore_drop_failed");
            }
            foreach ($this->snapshots[$receipt["backup_id"]][1] as $table => [$schema, $rows]) {
                RehearsalContract::require($this->db->query($schema) !== false, "fixture_restore_schema_failed");
                foreach ($rows as $row) { RehearsalContract::require($this->db->insert($table, $row) !== false, "fixture_restore_row_failed"); }
            }
            foreach ($this->snapshots[$receipt["backup_id"]][2] as $trigger) {
                RehearsalContract::require($this->db->query($trigger) !== false, "fixture_restore_trigger_failed");
            }
        } finally {
            $this->db->query("SET FOREIGN_KEY_CHECKS=1");
            $this->db->suppress_errors($suppressed);
        }
        wp_cache_flush();
    }
}
