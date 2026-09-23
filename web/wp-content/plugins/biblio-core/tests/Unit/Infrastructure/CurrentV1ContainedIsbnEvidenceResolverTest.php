<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Migration\Preservation\{PreservedSourceEvidencePlan, PreservedSourceEvidencePrivacy};
use Biblio\Core\Application\Migration\Runner\{DeterministicJson, MigrationRunnerFailure, MigrationRunnerReason};
use Biblio\Core\Infrastructure\Migration\{CurrentV1ContainedWorkSourceIds, CurrentV1RestrictedSourceEvidenceResolver, CurrentV1ReviewedContainedWorkContract, CurrentV1SourceAdapter, FilesystemMigrationSourcePackageFactory};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrentV1ContainedIsbnEvidenceResolverTest extends TestCase
{
    private string $directory;
    private const ISBN = "9780306406157";

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . "/biblio-contained-isbn-" . bin2hex(random_bytes(8));
        mkdir($this->directory . "/data", 0700, true);
        $this->writeSource(self::ISBN);
    }

    protected function tearDown(): void
    {
        unlink($this->directory . "/data/books.json");
        rmdir($this->directory . "/data");
        rmdir($this->directory);
        parent::tearDown();
    }

    public function testFiveOccurrenceSlotsResolveWithoutIsbnIdentityInference(): void
    {
        $package = (new FilesystemMigrationSourcePackageFactory())->build($this->directory);
        $resolver = new CurrentV1RestrictedSourceEvidenceResolver();
        $before = hash_file("sha256", $this->directory . "/data/books.json");
        foreach (["parent-1" => 3, "parent-2" => 2] as $parent => $count) {
            foreach (range(1, $count) as $slot) {
                $plan = $this->plan($package->manifestDigest(), $parent, $slot);
                $payload = $plan->canonicalPayload();
                $resolver->verify($package, $plan);
                self::assertSame($payload, $plan->canonicalPayload());
            }
        }
        self::assertSame($before, hash_file("sha256", $this->directory . "/data/books.json"));
    }

    public static function invalidDescriptors(): array
    {
        return [
            "other child" => [["sourceIdentity" => "v1.book/parent-1/contained-work/2/isbn"]],
            "missing slot" => [["sourceIdentity" => "v1.book/parent-1/contained-work/4/isbn"]],
            "zero slot" => [["sourceIdentity" => "v1.book/parent-1/contained-work/0/isbn"]],
            "noncanonical slot" => [["sourceIdentity" => "v1.book/parent-1/contained-work/01/isbn"]],
            "wrong parent" => [["sourceEntityId" => "parent-2"]],
            "wrong prefix" => [["sourceIdentity" => "other/parent-1/contained-work/1/isbn"]],
            "wrong hash" => [["evidenceSha256" => str_repeat("a", 64)]],
            "wrong manifest" => [["manifestSha256" => str_repeat("b", 64)]],
            "wrong contract" => [["mappingContract" => "unrelated-contract"]],
            "unknown reason" => [["reasonCode" => "unknown_deferred_reason"]],
            "unknown type" => [["evidenceType" => "unknown_contained_work_evidence"]],
            "wrong field" => [["sourceField" => "isbn"]],
            "wrong collection" => [["sourceCollection" => "copies"]],
            "wrong file" => [["sourceFile" => "data/reading_goals.json"]],
            "wrong adapter" => [["adapterId" => "another-adapter"]],
            "wrong family" => [["sourceFamily" => "another-family"]],
            "wrong version" => [["sourceVersion" => "another-version"]],
        ];
    }

    #[DataProvider("invalidDescriptors")]
    public function testChangedIdentityEvidenceOrBindingFailsClosed(array $overrides): void
    {
        $package = (new FilesystemMigrationSourcePackageFactory())->build($this->directory);
        $this->assertRejected($package, $this->plan($package->manifestDigest(), overrides: $overrides));
    }

    public function testChangedRawBytesFailWithOldPackageAndReboundManifest(): void
    {
        $factory = new FilesystemMigrationSourcePackageFactory();
        $package = $factory->build($this->directory);
        $oldPlan = $this->plan($package->manifestDigest());
        // Semantically equivalent ISBN formatting is still different raw evidence.
        $this->writeSource("978-0-306-40615-7");
        $this->assertRejected($package, $oldPlan);
        $changed = $factory->build($this->directory);
        $this->assertRejected($changed, $oldPlan);
        $this->assertRejected($changed, $this->plan($changed->manifestDigest()));
    }

    public function testMissingOrNonstringRawEvidenceFailsEvenWithSelfConsistentHash(): void
    {
        foreach (["", null, 9780306406157] as $value) {
            $this->writeSource($value);
            $package = (new FilesystemMigrationSourcePackageFactory())->build($this->directory);
            $hash = DeterministicJson::hash(["source_slot" => CurrentV1ContainedWorkSourceIds::isbn("parent-1", 1), "one_based_slot" => 1, "isbn" => $value]);
            $this->assertRejected($package, $this->plan($package->manifestDigest(), overrides: ["evidenceSha256" => $hash]));
        }
    }

    private function assertRejected(\Biblio\Core\Application\Migration\Runner\MigrationSourcePackage $package, PreservedSourceEvidencePlan $plan): void
    {
        try {
            (new CurrentV1RestrictedSourceEvidenceResolver())->verify($package, $plan);
            self::fail("Changed preservation evidence must not resolve.");
        } catch (MigrationRunnerFailure $failure) {
            self::assertSame(MigrationRunnerReason::SourceChanged, $failure->reason());
            self::assertStringNotContainsString(self::ISBN, $failure->getMessage());
        }
    }

    private function plan(string $manifest, string $parent = "parent-1", int $slot = 1, array $overrides = []): PreservedSourceEvidencePlan
    {
        $identity = CurrentV1ContainedWorkSourceIds::isbn($parent, $slot);
        return new PreservedSourceEvidencePlan(...array_replace([
            "sourceIdentity" => $identity, "evidenceType" => "current_v1_contained_work_isbn",
            "reasonCode" => "contained_work_isbn_deferred", "adapterId" => CurrentV1SourceAdapter::ADAPTER_ID,
            "sourceFamily" => CurrentV1SourceAdapter::SOURCE_FAMILY, "sourceVersion" => CurrentV1SourceAdapter::SOURCE_VERSION,
            "manifestSha256" => $manifest, "mappingContract" => (new CurrentV1ReviewedContainedWorkContract($manifest))->identity(),
            "sourceFile" => "data/books.json", "sourceCollection" => "books", "sourceEntityId" => $parent,
            "sourceField" => "containedWorks", "evidenceSha256" => DeterministicJson::hash([
                "source_slot" => $identity, "one_based_slot" => $slot, "isbn" => self::ISBN,
            ]), "privacy" => PreservedSourceEvidencePrivacy::OrdinarySource,
        ], $overrides));
    }

    private function writeSource(mixed $isbn): void
    {
        $books = [];
        foreach (["parent-1" => 3, "parent-2" => 2] as $parent => $count) {
            $books[] = ["id" => $parent, "containedWorks" => array_fill(0, $count, ["isbn" => $isbn])];
        }
        file_put_contents($this->directory . "/data/books.json", json_encode(["books" => $books], JSON_THROW_ON_ERROR) . "\n");
    }
}
