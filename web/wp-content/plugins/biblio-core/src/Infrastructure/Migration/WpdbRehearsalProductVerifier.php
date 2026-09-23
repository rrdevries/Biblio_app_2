<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Migration\{MigrationLedgerRepository, MigrationRun};
use Biblio\Core\Application\Migration\Cutover\{RehearsalContract, RehearsalProductVerifier};
use Biblio\Core\Application\Migration\Preservation\PreservedSourceEvidencePlan;
use Biblio\Core\Application\Migration\Runner\{DeterministicJson, PreparedMigrationPlan};
use Biblio\Core\Catalog\{AuthorId, ItemId, SeriesId, WorkId};
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

final readonly class WpdbRehearsalProductVerifier implements RehearsalProductVerifier
{
    public function __construct(private wpdb $db, private CoreTableNames $tables, private MigrationLedgerRepository $ledger, private CoreApplication $app) {}

    public function verify(MigrationRun $run, PreparedMigrationPlan $prepared): array
    {
        // Production reconciliation verifies exact payload, ownership, relation order,
        // ISBN, details, and all mapped targets through Core repositories.
        RehearsalContract::require($this->app->migrationReconciliation()->reconcile($run, $prepared)->accepted(), "product_reconciliation_failed");
        $targets = [];
        foreach ($this->ledger->snapshot($run->id())->observations() as $observation) {
            foreach ($observation->mappings() as $mapping) {
                $targets[$mapping->targetType()][] = $mapping->targetId();
            }
        }
        $domainTables = [
            "work" => $this->tables->works(), "edition" => $this->tables->editions(),
            "item" => $this->tables->items(), "item_local_details" => $this->tables->itemLocalDetails(),
            "canonical_isbn" => $this->tables->editionIdentifierClaims(),
            "author" => $this->tables->authors(), "author_contributor_credit" => $this->tables->authorContributorCredits(),
            "work_contributor" => $this->tables->workContributors(),
            "series" => $this->tables->series(), "work_series_membership" => $this->tables->workSeries(),
            "work_containment" => $this->tables->workContainments(),
            "library_catalog_context" => $this->tables->libraryCatalogContexts(),
            "reading_round" => $this->tables->readingRounds(), "personal_reading_truth" => $this->tables->personalReadingTruths(),
            "private_note" => $this->tables->privateNotes(), "rating" => $this->tables->ratings(),
            "written_review" => $this->tables->reviews(), "wishlist_entry" => $this->tables->wishlistEntries(),
        ];
        $counts = [];
        $qa = [];
        foreach ($domainTables as $type => $table) {
            // Several mappings may reference one product; count distinct string IDs.
            $ids = array_values(array_unique($targets[$type] ?? [], SORT_STRING));
            $count = $this->db->get_var("SELECT COUNT(*) FROM `{$table}`");
            RehearsalContract::require($count !== null && (int) $count === count($ids), "unexpected_product_count");
            $counts[$type] = (int) $count;
            sort($ids, SORT_STRING);
            $qa[$type] = $ids[0] ?? null;
        }
        foreach ([$this->tables->externalLoans(), $this->tables->contributionPublications(), $this->tables->metadataLookupSnapshots(),
            $this->tables->bibliographicDiscoverySnapshots(), $this->tables->bibliographicProviderIdentities()] as $table) {
            RehearsalContract::require($this->db->get_var("SELECT COUNT(*) FROM `{$table}`") === "0", "unexpected_nonmigration_state");
        }
        $items = $this->tables->items();
        $editions = $this->tables->editions();
        $works = $this->tables->works();
        $details = $this->tables->itemLocalDetails();
        RehearsalContract::require($this->db->get_var("SELECT COUNT(*) FROM `{$items}` i LEFT JOIN `{$editions}` e ON e.edition_id=i.edition_id LEFT JOIN `{$works}` w ON w.work_id=e.work_id WHERE e.edition_id IS NULL OR w.work_id IS NULL") === "0", "orphan_item");
        RehearsalContract::require($this->db->get_var("SELECT COUNT(*) FROM `{$details}` d LEFT JOIN `{$items}` i ON i.item_id=d.item_id WHERE i.item_id IS NULL") === "0", "orphan_item_details");
        $edges = $this->db->get_results("SELECT parent_work_id,contained_work_id FROM `{$this->tables->workContainments()}`", ARRAY_A);
        $graph = [];
        foreach ($edges as $edge) {
            $graph[$edge["parent_work_id"]][] = $edge["contained_work_id"];
        }
        foreach (array_keys($graph) as $origin) {
            $pending = $graph[$origin];
            $seen = [];
            while ($pending !== []) {
                $node = array_pop($pending);
                RehearsalContract::require($node !== $origin, "containment_cycle");
                if (!isset($seen[$node])) {
                    $seen[$node] = true;
                    array_push($pending, ...($graph[$node] ?? []));
                }
            }
        }
        $recovered = 0;
        foreach ($prepared->records() as $item) {
            $typed = $item->record()->typedPlan();
            if ($typed instanceof \Biblio\Core\Application\Migration\Circulation\CirculationPlan) {
                $evidenceTable = $typed->hasMaterialLifecycleConflict() ? $this->tables->migrationQuarantine() : $this->tables->migrationPreservations();
                $row = $this->db->get_row($this->db->prepare(
                    "SELECT o.observation_id,p.evidence_json FROM `{$evidenceTable}` p JOIN `{$this->tables->migrationSourceObservations()}` o ON o.observation_id=p.observation_id AND o.run_id=p.run_id WHERE p.run_id=%s AND o.source_type=%s AND o.source_id=%s",
                    $run->id(), $item->record()->sourceType(), $item->record()->sourceId()), ARRAY_A);
                RehearsalContract::require(is_array($row), "circulation_evidence_missing");
                RehearsalContract::equal(json_decode($row["evidence_json"], true), $typed->restrictedEvidence(
                    $run->id(), $row["observation_id"], $run->sourceFamily(), $item->record()->sourceType(), $run->sourceSnapshot(), $item->record()->payloadHash()), "circulation_evidence_changed");
                ++$recovered;
            }
            if ($typed instanceof \Biblio\Core\Application\Migration\Catalog\CatalogItemPlan && $typed->preservation() !== null) {
                $expected = $typed->preservation();
                $row = $this->db->get_row($this->db->prepare(
                    "SELECT p.reason_code,p.evidence_json,p.evidence_reference FROM `{$this->tables->migrationPreservations()}` p JOIN `{$this->tables->migrationSourceObservations()}` o ON o.observation_id=p.observation_id AND o.run_id=p.run_id WHERE p.run_id=%s AND o.source_type=%s AND o.source_id=%s",
                    $run->id(), $item->record()->sourceType(), $item->record()->sourceId()), ARRAY_A);
                RehearsalContract::require(is_array($row), "item_preservation_missing");
                RehearsalContract::equal($row["reason_code"], $expected->reason(), "item_preservation_reason_changed");
                RehearsalContract::equal($row["evidence_reference"], $expected->sourceEvidenceReference(), "item_preservation_locator_changed");
                RehearsalContract::equal(json_decode($row["evidence_json"], true), $expected->outcomeEvidence(), "item_preservation_evidence_changed");
                ++$recovered;
            }
            if ($typed instanceof PreservedSourceEvidencePlan) {
                (new CurrentV1RestrictedSourceEvidenceResolver())->verify($prepared->inspection()->package(), $typed);
                $row = $this->db->get_row($this->db->prepare(
                    "SELECT p.reason_code,p.evidence_json,p.evidence_reference FROM `{$this->tables->migrationPreservations()}` p JOIN `{$this->tables->migrationSourceObservations()}` o ON o.observation_id=p.observation_id AND o.run_id=p.run_id WHERE p.run_id=%s AND o.source_type=%s AND o.source_id=%s",
                    $run->id(), $item->record()->sourceType(), $item->record()->sourceId()
                ), ARRAY_A);
                RehearsalContract::require(is_array($row), "preservation_missing");
                RehearsalContract::equal($row["reason_code"], $typed->reasonCode(), "preservation_reason_changed");
                RehearsalContract::equal($row["evidence_reference"], $typed->locator(), "preservation_locator_changed");
                RehearsalContract::equal(json_decode($row["evidence_json"], true), $typed->evidenceDescriptor(), "preservation_evidence_changed");
                ++$recovered;
            }
        }
        $actor = get_current_user_id();
        $networkRequests = 0;
        $denyNetwork = static function () use (&$networkRequests): \WP_Error {
            ++$networkRequests;
            return new \WP_Error("rehearsal_network_disabled", "Rehearsal network access is disabled.");
        };
        add_filter("pre_http_request", $denyNetwork, PHP_INT_MIN);
        wp_set_current_user((int) $run->targetUserId()->value());
        try {
            $this->app->libraryContexts()->myLibraries();
            $this->app->libraryContexts()->get($run->targetLibraryId());
            $this->app->catalogUiReads()->activeOverview($run->targetLibraryId());
            if ($qa["item"] !== null) {
                $this->app->catalogUiReads()->itemDetail($run->targetLibraryId(), new ItemId($qa["item"]));
            }
            if ($qa["work"] !== null) {
                $work = new WorkId($qa["work"]);
                $this->app->bibliographicMetadata()->editions([$work]);
                $this->app->bibliographicRelationships()->contributorsForWorks([$work]);
                $this->app->bibliographicRelationships()->seriesForWorks([$work]);
                $this->app->readingHistory()->forWork($work);
                $this->app->privateNoteViewsForWork()->forWork($work);
            }
            if ($qa["author"] !== null) {
                RehearsalContract::require($this->app->bibliographicRelationships()->authors([new AuthorId($qa["author"])]) !== [], "author_smoke_failed");
            }
            if ($qa["series"] !== null) {
                RehearsalContract::require($this->app->bibliographicRelationships()->series([new SeriesId($qa["series"])]) !== [], "series_smoke_failed");
            }
            $this->app->myWishlist()->get();
            RehearsalContract::require($networkRequests === 0, "provider_request_attempted");
        } finally {
            wp_set_current_user($actor);
            remove_filter("pre_http_request", $denyNetwork, PHP_INT_MIN);
        }
        return ["counts" => $counts, "qa_candidates" => $qa, "restricted_evidence_verified" => $recovered,
            "application_smoke" => "passed", "network_requests" => 0,
            "target_identity_set_sha256" => DeterministicJson::hash(self::targetIdentityFingerprintInput($targets))];
    }

    /**
     * @param array<string, list<string>> $targets
     * @return list<array{target_type: string, target_id: string}>
     */
    private static function targetIdentityFingerprintInput(array $targets): array
    {
        $identities = [];
        foreach ($targets as $type => $ids) {
            foreach ($ids as $id) {
                // IDs are values, never PHP array keys; retain repeated mappings.
                $identities[] = ["target_type" => $type, "target_id" => $id];
            }
        }
        usort($identities, static fn (array $left, array $right): int =>
            strcmp($left["target_type"], $right["target_type"])
                ?: strcmp($left["target_id"], $right["target_id"]));
        return $identities;
    }
}
