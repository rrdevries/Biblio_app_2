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
    CurrentV1SeriesSourceIds,
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

    public function testReviewedSeriesEvidenceShapesVerifyExactSlotsAndHashes(): void
    {
        $directory = $this->seriesSource();
        $package = (new FilesystemMigrationSourcePackageFactory())->build($directory);
        $resolver = new CurrentV1RestrictedSourceEvidenceResolver();
        $bookId = "book-series";

        $missingIdentity = CurrentV1SeriesSourceIds::membership($bookId);
        $resolver->verify($package, $this->seriesPlan(
            $package->manifestDigest(),
            $missingIdentity,
            "current_v1_series_membership_without_name",
            "series_name_missing",
            "seriesName",
            DeterministicJson::hash([
                "source_slot" => $missingIdentity,
                "series" => true,
                "series_name" => "",
                "series_number" => "1",
            ])
        ));

        $positionIdentity = CurrentV1SeriesSourceIds::unsafePosition("book-position");
        $resolver->verify($package, $this->seriesPlan(
            $package->manifestDigest(),
            $positionIdentity,
            "current_v1_series_position",
            "series_position_not_safely_mappable",
            "seriesNumber",
            DeterministicJson::hash([
                "source_slot" => $positionIdentity,
                "series_name" => "Unsafe Series",
                "series_number" => "1.2",
            ]),
            "book-position"
        ));

        $containedIdentity = CurrentV1SeriesSourceIds::contained("book-contained", 2);
        $resolver->verify($package, $this->seriesPlan(
            $package->manifestDigest(),
            $containedIdentity,
            "current_v1_contained_work_series",
            "contained_work_series_deferred",
            "containedWorks",
            DeterministicJson::hash([
                "source_slot" => $containedIdentity,
                "one_based_slot" => 2,
                "series" => "Contained Series",
                "series_index" => "4",
            ]),
            "book-contained"
        ));
        self::addToAssertionCount(3);

        $this->expectException(MigrationRunnerFailure::class);
        $resolver->verify($package, $this->seriesPlan(
            $package->manifestDigest(),
            $containedIdentity,
            "current_v1_contained_work_series",
            "contained_work_series_deferred",
            "containedWorks",
            str_repeat("f", 64),
            "book-contained"
        ));
    }

    public function testReviewedWishlistAuxiliaryEnvelopeVerifiesExactHash(): void
    {
        $directory = $this->wishlistSource();
        $package = (new FilesystemMigrationSourcePackageFactory())->build($directory);
        $resolver = new CurrentV1RestrictedSourceEvidenceResolver();
        $resolver->verify($package, $this->wishlistPlan(
            $package->manifestDigest(),
            DeterministicJson::hash([
                "source_slot" => "wishlistItems",
                "raw_type" => "edition",
                "title_group_key" => "private-group-sentinel",
                "desired_carrier" => "Fysiek boek",
            ])
        ));
        self::addToAssertionCount(1);

        $this->expectException(MigrationRunnerFailure::class);
        $resolver->verify($package, $this->wishlistPlan(
            $package->manifestDigest(),
            str_repeat("f", 64)
        ));
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

    private function seriesPlan(
        string $manifest,
        string $sourceIdentity,
        string $evidenceType,
        string $reason,
        string $sourceField,
        string $evidenceHash,
        string $bookId = "book-series"
    ): PreservedSourceEvidencePlan {
        return new PreservedSourceEvidencePlan(
            $sourceIdentity,
            $evidenceType,
            $reason,
            CurrentV1SourceAdapter::ADAPTER_ID,
            CurrentV1SourceAdapter::SOURCE_FAMILY,
            CurrentV1SourceAdapter::SOURCE_VERSION,
            $manifest,
            "d-mig-series-map-01.2026-09-17:test",
            "data/books.json",
            "books",
            $bookId,
            $sourceField,
            $evidenceHash,
            PreservedSourceEvidencePrivacy::OrdinarySource
        );
    }

    private function seriesSource(): string
    {
        $directory = sys_get_temp_dir()
            . "/biblio-series-recovery-"
            . bin2hex(random_bytes(8));
        mkdir($directory . "/data", 0750, true);
        $this->temporaryDirectories[] = $directory;
        file_put_contents(
            $directory . "/data/books.json",
            json_encode(["books" => [
                [
                    "id" => "book-series",
                    "series" => true,
                    "seriesName" => "",
                    "seriesNumber" => "1",
                    "containedWorks" => [],
                ],
                [
                    "id" => "book-position",
                    "series" => true,
                    "seriesName" => "Unsafe Series",
                    "seriesNumber" => "1.2",
                    "containedWorks" => [],
                ],
                [
                    "id" => "book-contained",
                    "series" => false,
                    "seriesName" => "",
                    "seriesNumber" => "",
                    "containedWorks" => [
                        ["series" => "", "seriesIndex" => ""],
                        ["series" => "Contained Series", "seriesIndex" => "4"],
                    ],
                ],
            ]], JSON_THROW_ON_ERROR) . "\n"
        );
        return $directory;
    }

    private function wishlistPlan(
        string $manifest,
        string $evidenceHash
    ): PreservedSourceEvidencePlan {
        return new PreservedSourceEvidencePlan(
            "v1.wishlist_item/wish-1/auxiliary",
            "current_v1_wishlist_auxiliary",
            "wishlist_auxiliary_evidence_preserved",
            CurrentV1SourceAdapter::ADAPTER_ID,
            CurrentV1SourceAdapter::SOURCE_FAMILY,
            CurrentV1SourceAdapter::SOURCE_VERSION,
            $manifest,
            "d-mig-wishlist-map-01.2026-09-18:test",
            "data/books.json",
            "wishlistItems",
            "wish-1",
            "auxiliaryEvidence",
            $evidenceHash,
            PreservedSourceEvidencePrivacy::RestrictedSource
        );
    }

    private function wishlistSource(): string
    {
        $directory = sys_get_temp_dir()
            . "/biblio-wishlist-recovery-"
            . bin2hex(random_bytes(8));
        mkdir($directory . "/data", 0750, true);
        $this->temporaryDirectories[] = $directory;
        file_put_contents(
            $directory . "/data/books.json",
            json_encode([
                "books" => [],
                "wishlistItems" => [[
                    "id" => "wish-1",
                    "type" => "edition",
                    "titleGroupKey" => "private-group-sentinel",
                    "desiredCarrier" => "Fysiek boek",
                ]],
            ], JSON_THROW_ON_ERROR) . "\n"
        );
        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        $files = [$directory . "/data/books.json", $directory . "/data", $directory];
        foreach ($files as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
    }
}
