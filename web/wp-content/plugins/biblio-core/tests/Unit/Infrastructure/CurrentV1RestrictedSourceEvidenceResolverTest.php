<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Migration\Preservation\{
    PreservedSourceEvidencePlan,
    PreservedSourceEvidencePrivacy
};
use Biblio\Core\Application\Migration\Runner\{
    DeterministicJson,
    MigrationRunnerFailure,
    MigrationRunnerReason
};
use Biblio\Core\Infrastructure\Migration\{
    CurrentV1RestrictedSourceEvidenceResolver,
    CurrentV1SourceAdapter,
    FilesystemMigrationSourcePackageFactory
};
use PHPUnit\Framework\TestCase;

final class CurrentV1RestrictedSourceEvidenceResolverTest extends TestCase
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

    public function testDescriptorAndImmutablePackageVerifyExactRestrictedEvidence(): void
    {
        $body = "private-reflection-recovery-sentinel";
        $sourceIdentity = "current-v1:book:book-1:reflection";
        $directory = $this->source($body);
        $package = (new FilesystemMigrationSourcePackageFactory())->build($directory);
        $plan = $this->plan(
            $package->manifestDigest(),
            $sourceIdentity,
            DeterministicJson::hash([
                "source_slot" => $sourceIdentity,
                "body" => $body,
            ])
        );

        (new CurrentV1RestrictedSourceEvidenceResolver())->verify($package, $plan);
        self::addToAssertionCount(1);
    }

    public function testWrongManifestAndChangedBodyFailWithoutLeakingBody(): void
    {
        $body = "private-reflection-failure-sentinel";
        $sourceIdentity = "current-v1:book:book-1:reflection";
        $directory = $this->source($body);
        $factory = new FilesystemMigrationSourcePackageFactory();
        $package = $factory->build($directory);
        $resolver = new CurrentV1RestrictedSourceEvidenceResolver();

        try {
            $resolver->verify(
                $package,
                $this->plan(
                    str_repeat("a", 64),
                    $sourceIdentity,
                    DeterministicJson::hash([
                        "source_slot" => $sourceIdentity,
                        "body" => $body,
                    ])
                )
            );
            self::fail("Wrong package provenance must fail.");
        } catch (MigrationRunnerFailure $exception) {
            self::assertSame(MigrationRunnerReason::SourceChanged, $exception->reason());
            self::assertStringNotContainsString($body, $exception->getMessage());
        }

        file_put_contents(
            $directory . "/data/books.json",
            json_encode([
                "books" => [[
                    "id" => "book-1",
                    "reflection" => "changed-private-reflection",
                ]],
            ], JSON_THROW_ON_ERROR) . "\n"
        );
        try {
            $resolver->verify(
                $package,
                $this->plan(
                    $package->manifestDigest(),
                    $sourceIdentity,
                    DeterministicJson::hash([
                        "source_slot" => $sourceIdentity,
                        "body" => $body,
                    ])
                )
            );
            self::fail("Changed source bytes must fail.");
        } catch (MigrationRunnerFailure $exception) {
            self::assertSame(MigrationRunnerReason::SourceChanged, $exception->reason());
            self::assertStringNotContainsString($body, $exception->getMessage());
            self::assertStringNotContainsString(
                "changed-private-reflection",
                $exception->getMessage()
            );
        }
    }

    private function plan(
        string $manifest,
        string $sourceIdentity,
        string $evidenceHash
    ): PreservedSourceEvidencePlan {
        return new PreservedSourceEvidencePlan(
            $sourceIdentity,
            "current_v1_reflection",
            "reflection_target_not_available",
            CurrentV1SourceAdapter::ADAPTER_ID,
            CurrentV1SourceAdapter::SOURCE_FAMILY,
            CurrentV1SourceAdapter::SOURCE_VERSION,
            $manifest,
            "current-v1-assessment-map-v1",
            "data/books.json",
            "books",
            "book-1",
            "reflection",
            $evidenceHash,
            PreservedSourceEvidencePrivacy::RestrictedSource
        );
    }

    private function source(string $body): string
    {
        $directory = sys_get_temp_dir()
            . "/biblio-preservation-recovery-"
            . bin2hex(random_bytes(8));
        mkdir($directory . "/data", 0750, true);
        $this->temporaryDirectories[] = $directory;
        file_put_contents(
            $directory . "/data/books.json",
            json_encode([
                "books" => [[
                    "id" => "book-1",
                    "reflection" => $body,
                ]],
            ], JSON_THROW_ON_ERROR) . "\n"
        );
        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        $files = [
            $directory . "/data/books.json",
            $directory . "/data",
            $directory,
        ];
        foreach ($files as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
    }
}
