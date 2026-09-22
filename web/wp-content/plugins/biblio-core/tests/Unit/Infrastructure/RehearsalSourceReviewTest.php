<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Migration\Cutover\{ApprovedRehearsalSource, RehearsalFailure, ReviewedSourceProfile};
use Biblio\Core\Application\Migration\Runner\{DeterministicJson, MigrationSourceFinding, MigrationSourceInspection, MigrationSourceProfile};
use Biblio\Core\Infrastructure\Migration\{CurrentV1SourceAdapter, FilesystemMigrationSourcePackageFactory, FinalSourceInspector};
use Biblio\Core\Tests\Support\RehearsalFixture;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . "/Support/RehearsalFixture.php";

final class RehearsalSourceReviewTest extends TestCase
{
    public function testExactProfileApprovalAndUnknownOrMalformedAdmissionRefusal(): void
    {
        $directory = sys_get_temp_dir() . "/biblio-rehearsal-review-" . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $source = RehearsalFixture::source($directory);
            $factory = new FilesystemMigrationSourcePackageFactory();
            $inspection = (new FinalSourceInspector($factory))->inspect($source->intake->extractionRoot(), new CurrentV1SourceAdapter());
            $admitted = ReviewedSourceProfile::forApprovedCandidate($source, $inspection);
            self::assertTrue($admitted->matches($inspection, $source->bundle->digest()));
            self::assertFalse($admitted->matches($inspection, str_repeat("f", 64)));
            foreach (["unreviewed_reason", "malformed_record", "unreadable_record", "malformed_value"] as $reason) {
                $profile = $inspection->profile();
                $changed = new MigrationSourceInspection($inspection->package(), $inspection->adapter(),
                    new MigrationSourceProfile($profile->sourceVersion(), $profile->categoryCounts(), $profile->unknownCategories(),
                        [...$profile->findings(), new MigrationSourceFinding($reason, "synthetic", "No private content")], $profile->categoryStrategies()),
                    $inspection->records(), $inspection->typeCounts());
                self::assertFalse($admitted->matches($changed, $source->bundle->digest()));
                $review = $source->review;
                $review["source_profile_digest"] = DeterministicJson::hash($changed->sourcePayload());
                $attempt = new ApprovedRehearsalSource($source->intake, $source->bundle, $review, $directory . "/synthetic.zip");
                try { ReviewedSourceProfile::forApprovedCandidate($attempt, $changed); self::fail("Unreviewed source shape admitted"); }
                catch (RehearsalFailure $failure) { self::assertSame("unreviewed_source_finding", $failure->reason); }
            }
            foreach (["state" => "mechanically_compatible", "bundle_digest" => str_repeat("f", 64), "package_digest" => str_repeat("e", 64)] as $field => $value) {
                $review = $source->review;
                $review[$field] = $value;
                try { new ApprovedRehearsalSource($source->intake, $source->bundle, $review, $directory . "/synthetic.zip"); self::fail("Invalid approval accepted"); }
                catch (RehearsalFailure) { $this->addToAssertionCount(1); }
            }
            $source->verify($factory);
            file_put_contents($directory . "/synthetic.zip", "synthetic changed archive", FILE_APPEND);
            try { $source->verify($factory); self::fail("Changed archive accepted"); }
            catch (RehearsalFailure $failure) { self::assertSame("candidate_archive_changed", $failure->reason); }
        } finally { RehearsalFixture::remove($directory); }
    }
}
