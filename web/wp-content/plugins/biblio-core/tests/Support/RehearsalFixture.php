<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Support;

use Biblio\Core\Application\Migration\Cutover\{ApprovedRehearsalSource, FinalPopulationContractBundle, FinalSourceDriftEngine, FinalSourceExportProvenance, FinalSourceRetentionMetadata};
use Biblio\Core\Infrastructure\Migration\{CurrentV1FinalSourceSnapshotBuilder, CurrentV1SourceAdapter, FilesystemMigrationSourcePackageFactory, FinalSourceInspector, FinalSourceIntakeService};
use ZipArchive;

/** Explicit synthetic approval fixture. Never discovers or reads CURRENT data. */
final class RehearsalFixture
{
    public static function source(string $directory, bool $withQuarantine = false): ApprovedRehearsalSource
    {
        mkdir($directory . "/raw/data", 0700, true);
        $book = [
            "id" => "book-1", "title" => "Synthetic Rehearsal Book", "isbn" => "", "isbn10" => "", "isbn13" => "",
            "authors" => ["Synthetic Author"], "authorIds" => ["author-1"],
            "bookType" => "Leesboek", "carrier" => "Fysiek boek", "collectionStatus" => "owned", "ownershipStatus" => "owned",
            "readMarker" => "yes", "readStatus" => "finished", "readHistory" => [], "readRegistration" => null,
            "readingRounds" => [["id" => "round-1", "startedAt" => "", "finishedAt" => "", "stoppedAt" => "", "stopReason" => "", "pauses" => [],
                "startedAtPartial" => ["value" => "2020-01-02", "precision" => "day"], "finishedAtPartial" => ["value" => "2020-01-03", "precision" => "day"], "stoppedAtPartial" => null]],
            "notes" => [["id" => "1577923200000_test01", "text" => "synthetic-private-note-canary", "createdAt" => "2020-01-02T00:00:00.000Z", "updatedAt" => "2020-01-03T00:00:00.000Z"]],
            "circulationRounds" => [], "containedWorks" => [["title" => "Synthetic contained work", "author" => "", "isbn" => "", "series" => "", "seriesIndex" => ""]], "categories" => [], "genres" => [], "series" => true, "seriesName" => "Synthetic Series", "seriesNumber" => "",
            "rating" => 4, "reviews" => [["date" => "2020-01-02T03:04:05.678Z", "text" => "synthetic-private-review-canary"]], "reflection" => "synthetic-private-reflection-canary", "variantOfBookId" => "",
            "acquisition" => ["type" => "bought", "source" => "synthetic-private-shop-canary", "date" => ["value" => "2020", "precision" => "year"]],
        ];
        $copy = ["id" => "copy-1", "bookId" => "book-1", "status" => "owned", "ownershipStatus" => "owned", "condition" => "",
            "archived" => false, "archiveReason" => "", "notes" => "", "acquisition" => $book["acquisition"], "circulationRounds" => []];
        if ($withQuarantine) {
            $loan = ["id" => "loan-1", "type" => "borrowed", "counterparty" => "synthetic-private-counterparty-canary",
                "startDate" => ["value" => "2020-01-02", "precision" => "day"], "endDate" => null, "notes" => "synthetic-private-loan-canary"];
            $book["circulationRounds"] = [$loan];
            $loan["endDate"] = ["value" => "2020-01-03", "precision" => "day"];
            $copy["circulationRounds"] = [$loan];
        }
        $second = array_replace($book, ["id" => "book-2", "title" => "Synthetic unread work", "readingRounds" => [], "notes" => [], "circulationRounds" => [], "containedWorks" => [],
            "rating" => 0, "reviews" => [], "reflection" => "", "readStatus" => "unread", "readMarker" => "no", "series" => false, "seriesName" => ""]);
        $wish = ["authorSnapshot" => "Synthetic Author", "bookId" => "book-2", "createdAt" => "2020-01-02T00:00:00.000Z", "updatedAt" => "2020-01-03T00:00:00.000Z",
            "desiredBinding" => "", "desiredCarrier" => "Fysiek boek", "desiredLanguage" => "", "fulfilledAt" => "", "fulfilledCopyId" => "", "id" => "wish-1", "notes" => "", "priority" => "",
            "status" => "active", "titleGroupKey" => "synthetic-unread", "titleSnapshot" => "Synthetic unread work", "type" => "edition"];
        $files = [
            "books.json" => ["schemaVersion" => 29, "books" => [$book, $second], "copies" => [$copy], "wishlistItems" => [$wish]],
            "authors.json" => ["schemaVersion" => 2, "authors" => [["id" => "author-1", "displayName" => "Synthetic Author"]]],
            "reading_goals.json" => ["schemaVersion" => 2, "goals" => []],
            "next_to_read.json" => ["items" => []], "releases_dismissed.json" => ["version" => 1, "dismissed" => []],
            "taxonomy_aliases.json" => ["version" => 2, "rules" => []],
        ];
        foreach (["book_types.json", "carriers.json", "categories.json", "genres.json", "releases_excluded.json", "releases_merged.json", "taxonomy_review_queue.json"] as $file) {
            $files[$file] = [];
        }
        foreach (["book_enrich_cache.json" => [1, ["entries"]], "cache.json" => [1, ["entries"]],
            "home_prefs.json" => [1, ["hiddenWidgets", "widgetOrder"]], "recommendations_ignored.json" => [1, ["ignoredBookIds"]],
            "recommendations_prefs.json" => [2, ["ignored", "shown"]], "releases.json" => [1, ["byAuthor", "lastCheckedAt", "lastRun", "mergedDiff"]],
            "releases_prefs.json" => [1, ["trackedAuthorIds"]]] as $file => [$version, $fields]) {
            $files[$file] = ["schemaVersion" => $version];
            foreach ($fields as $field) { $files[$file][$field] = []; }
        }
        $archive = $directory . "/synthetic.zip";
        $zip = new ZipArchive();
        $zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL);
        foreach ($files as $name => $payload) {
            $bytes = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            file_put_contents($directory . "/raw/data/" . $name, $bytes);
            $zip->addFromString("data/" . $name, $bytes);
        }
        $zip->close();
        $factory = new FilesystemMigrationSourcePackageFactory();
        $adapter = new CurrentV1SourceAdapter();
        $intake = (new FinalSourceIntakeService($factory))->intake($archive, hash_file("sha256", $archive),
            $factory->build($directory . "/raw")->manifestDigest(), "synthetic-rehearsal-01b", $directory . "/intake", $adapter, $adapter::SOURCE_VERSION,
            new FinalSourceExportProvenance("2026-09-18T12:00:00+00:00", "synthetic-operator", "synthetic-export", "1", str_repeat("a", 64), "synthetic-v1", "synthetic-runtime", "synthetic-freeze"),
            new FinalSourceRetentionMetadata("synthetic-retained", "verified", "verified"));
        $inspection = (new FinalSourceInspector($factory))->inspect($intake->extractionRoot(), $adapter);
        $snapshot = (new CurrentV1FinalSourceSnapshotBuilder())->build($inspection, $intake->identity());
        $bundle = FinalPopulationContractBundle::fromReport($snapshot, (new FinalSourceDriftEngine())->compare($snapshot, $snapshot));
        $quarantine = [];
        foreach ($inspection->records() as $record) {
            if ($record->typedPlan() instanceof \Biblio\Core\Application\Migration\Circulation\CirculationPlan && $record->typedPlan()->hasMaterialLifecycleConflict()) {
                $quarantine[] = ["source_type" => $record->sourceType(), "source_id" => $record->sourceId(), "payload_hash" => $record->payloadHash(),
                    "reason_code" => "ambiguous_circulation_semantics", "evidence_hash" => $record->payloadHash(), "no_target" => true];
            }
        }
        return new ApprovedRehearsalSource($intake, $bundle, ["state" => "approved_for_rehearsal", "review_id" => "explicit-synthetic-review",
            "package_digest" => $intake->identity()->digest(), "bundle_digest" => $bundle->digest(),
            "source_profile_digest" => \Biblio\Core\Application\Migration\Runner\DeterministicJson::hash($inspection->sourcePayload()),
            "quarantine" => $quarantine, "author_exceptions" => [], "occurrence_exceptions" => [], "copy_exclusions" => []], $archive);
    }

    public static function remove(string $directory): void
    {
        if (!is_dir($directory)) { return; }
        chmod($directory, 0700);
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) { continue; }
            if ($entry->isDir() && !$entry->isLink()) { self::remove($entry->getPathname()); }
            else { unlink($entry->getPathname()); }
        }
        rmdir($directory);
    }
}
