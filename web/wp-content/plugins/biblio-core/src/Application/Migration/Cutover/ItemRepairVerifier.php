<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Cutover;

/** Verifies Copy-scoped outcomes; eligibility is supplied by the accepted Item planner. */
final class ItemRepairVerifier
{
    /**
     * The required set is the exact approved/eligible plan intersection, never all
     * Copies of an approved Book or all Copies sharing an example title.
     *
     * @param array<string,string> $eligibleWorkIds Copy-bound source ID => existing Work ID
     * @param list<array{source_id:string,item_id:string}> $repairMappings Uncollapsed ledger mappings
     * @param list<string> $excludedSourceIds Accepted erroneous Copy source IDs
     * @param array<string,string> $priorLinks Previous source ID => Item ID
     * @param array<string,string> $visibleItems Authenticated catalog Item ID => Work ID
     * @return array<string,string> Verified repair source ID => Item ID
     */
    public function verify(array $eligibleWorkIds, array $repairMappings, array $excludedSourceIds,
        array $priorLinks, array $visibleItems): array
    {
        RehearsalContract::require($eligibleWorkIds !== [], 'repair_eligible_set_empty');
        $excluded = array_fill_keys($excludedSourceIds, true);
        foreach ($excludedSourceIds as $sourceId) {
            RehearsalContract::require(!isset($eligibleWorkIds[$sourceId]), 'repair_excluded_copy_approved');
            RehearsalContract::require(!isset($priorLinks[$sourceId]), 'repair_excluded_copy_materialized');
        }
        $links = []; $items = [];
        foreach ($repairMappings as $mapping) {
            $sourceId = $mapping['source_id']; $itemId = $mapping['item_id'];
            RehearsalContract::require(!isset($excluded[$sourceId]), 'repair_excluded_copy_materialized');
            RehearsalContract::require(isset($eligibleWorkIds[$sourceId]), 'repair_unexpected_copy_materialized');
            RehearsalContract::require(!isset($links[$sourceId]), 'repair_copy_multiple_items');
            RehearsalContract::require($itemId !== '' && !isset($items[$itemId])
                && !in_array($itemId, $priorLinks, true), 'repair_item_identity_reused');
            RehearsalContract::require(isset($visibleItems[$itemId])
                && $visibleItems[$itemId] === $eligibleWorkIds[$sourceId], 'repair_copy_item_not_visible_on_expected_work');
            $links[$sourceId] = $itemId; $items[$itemId] = true;
        }
        $expected = array_keys($eligibleWorkIds); $actual = array_keys($links);
        sort($expected, SORT_STRING); sort($actual, SORT_STRING);
        RehearsalContract::equal($actual, $expected, 'repair_eligible_copy_without_item');
        return $links;
    }
}
