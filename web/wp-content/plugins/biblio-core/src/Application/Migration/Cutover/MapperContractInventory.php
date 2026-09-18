<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

use Biblio\Core\Application\Migration\Runner\DeterministicJson;

final readonly class MapperContractInventory
{
    /** @var list<array<string,mixed>> */
    private array $entries;

    public function __construct()
    {
        $this->entries = self::reviewedCurrentInventory();
    }

    /** @return list<array<string,mixed>> */
    public function entries(): array { return $this->entries; }
    public function digest(): string { return DeterministicJson::hash($this->entries); }

    /** @return list<array<string,mixed>> */
    private static function reviewedCurrentInventory(): array
    {
        $rows = [
            ["current_v1_source_adapter", "CurrentV1SourceAdapter", "current-v1-json-29", ["A"], false, false, false, false],
            ["catalog_work_edition_item", "CAT Work/Edition/Item and ISBN grouping", "MIG-02-CAT-MAP-01", ["A", "C"], false, false, true, true],
            ["classification", "classification", "D-MIG-CLASS-MAP-01", ["A", "B", "C"], true, true, true, true],
            ["item_local", "Item-local", "D-MIG-ITEMLOCAL-MAP-01", ["A", "B", "C"], true, false, true, true],
            ["erroneous_copy_exclusion", "erroneous Copy exclusion", "D-MIG-COPY-EXCL-01", ["B", "C", "D"], true, true, true, true],
            ["author_contributor", "Author/contributor", "D-MIG-AUTH-MAP-01", ["A", "B", "C", "D"], true, true, true, true],
            ["reading", "ReadingRound/Reading Truth", "D-MIG-READ-MAP-01", ["A", "B", "C"], true, false, true, true],
            ["note", "Note", "D-MIG-NOTE-MAP-01", ["A", "B", "C"], true, false, true, true],
            ["assessments", "Rating/Review/Reflection", "D-MIG-ASSESS-MAP-01", ["A", "B", "C"], true, false, true, true],
            ["series", "Series", "D-MIG-SERIES-MAP-01", ["A", "B", "C", "D"], true, true, true, true],
            ["contained_works", "contained works", "MIG-02-CONTAINED-MAP-01", ["A", "B", "C", "D"], true, true, true, true],
            ["wishlist", "Wishlist", "D-MIG-WISHLIST-MAP-01", ["A", "B", "C"], true, false, true, true],
            ["reading_goals", "Reading Goals", "MIG-02-READING-GOAL-MAP-01", ["A", "B", "C", "D"], true, true, true, true],
            ["circulation", "circulation", "D-MIG-LOAN-01", ["A", "C", "D"], false, true, true, true],
            ["preserved_source_evidence", "preserved source evidence", "MIG-02-PRESERVE-01", ["A", "B", "C"], true, false, true, true],
            ["migration_foundation", "source-neutral participants/MIG-FND/reconciliation", "MIG-FND-01", ["A"], false, false, false, false],
        ];
        $entries = [];
        foreach ($rows as [$domain, $mapper, $contract, $classes, $manifest, $approvals, $digest, $regenerate]) {
            $entries[] = [
                "domain" => $domain,
                "mapper" => $mapper,
                "contract_id_version" => $contract,
                "classification" => $classes,
                "semantic_scope" => "accepted_current_v1_semantics_only",
                "population_bound" => $manifest,
                "per_record_approvals" => $approvals,
                "exact_source_set_digest" => $digest,
                "final_source_regeneration_required" => $regenerate,
            ];
        }
        return $entries;
    }
}
