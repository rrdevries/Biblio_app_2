<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Cutover\RehearsalContract;
use Biblio\Core\Application\Migration\Runner\{DeterministicJson, MigrationSourceRecord};

/** Expands an explicitly accepted member set; never selects a population by shape. */
final readonly class CurrentV1ClassificationRepairApprovals
{
    /** @param list<array<string,mixed>> $members */
    public function __construct(private array $members, string $acceptedDigest)
    {
        RehearsalContract::equal(DeterministicJson::hash($members), $acceptedDigest, 'repair_population_changed');
        RehearsalContract::require($members !== [], 'repair_population_empty');
    }

    /**
     * @param list<MigrationSourceRecord> $records
     * @param array<string,array{members:array<string,string>,book_type_seed_key:string,genre_seed_keys:list<string>,decision_provenance:string}> $convergedGroups
     */
    public function contract(string $manifest, array $records, array $convergedGroups = []): CurrentV1ReviewedClassificationContract
    {
        $indexed = [];
        foreach ($records as $record) {
            $indexed[$record->sourceType()][$record->sourceId()] = $record;
        }
        $approvals = [];
        $seen = [];
        foreach ($this->members as $member) {
            $book = $indexed[CurrentV1SourceAdapter::BOOK][$member['book_id']] ?? null;
            $copy = $indexed[CurrentV1SourceAdapter::COPY][$member['copy_id']] ?? null;
            RehearsalContract::require($book !== null && $copy !== null && !isset($seen[$member['copy_id']]), 'repair_member_missing_or_duplicate');
            $seen[$member['copy_id']] = true;
            RehearsalContract::equal($copy->payload()['bookId'] ?? null, $book->sourceId(), 'repair_parent_changed');
            RehearsalContract::equal($book->payloadHash(), $member['book_payload_hash'], 'repair_book_payload_changed');
            RehearsalContract::equal($copy->payloadHash(), $member['copy_payload_hash'], 'repair_copy_payload_changed');
            $payload = $book->payload();
            $shape = [
                'book_type' => $payload['bookType'] ?? null,
                'categories' => $payload['categories'] ?? null,
                'genres' => $payload['genres'] ?? null,
                'review_state' => $payload['taxonomyMeta']['bookType']['migration']['status'] ?? null,
            ];
            RehearsalContract::equal($shape, $member['shape'], 'repair_shape_changed');
            $seed = self::seedForApprovedShape($shape);
            $approval = [
                'payload_hash' => $book->payloadHash(),
                'source_book_type_value' => $shape['book_type'],
                'source_book_type_review_state' => $shape['review_state'],
                'assignment_decision_status' => 'REVIEW_MAPPING',
                'book_type_seed_key' => $seed,
                'genre_seed_keys' => [],
                'decision_provenance' => 'POST-CUTOVER-FIX-01:owner-approved-exact-population',
            ];
            if (isset($approvals[$book->sourceId()])) {
                RehearsalContract::equal($approvals[$book->sourceId()], $approval, 'repair_book_approval_conflict');
            }
            $approvals[$book->sourceId()] = $approval;
        }
        return new CurrentV1ReviewedClassificationContract($manifest, $approvals, $convergedGroups);
    }

    /** @param array<string,mixed> $shape */
    private static function seedForApprovedShape(array $shape): string
    {
        if ($shape['book_type'] === 'Leesboek' && $shape['genres'] === [] && (
            ($shape['categories'] === ['Fictie'] && $shape['review_state'] === 'no_signal')
            || (in_array($shape['categories'], [['Non-fictie', 'Fictie'], ['Fictie', 'Non-fictie']], true)
                && $shape['review_state'] === 'review')
        )) {
            return 'book_type.reading_book';
        }
        RehearsalContract::require($shape === [
            'book_type' => 'Kookboek', 'categories' => ['Koken & Voeding'],
            'genres' => ['Recepten'], 'review_state' => 'no_signal',
        ], 'repair_unapproved_shape');
        return 'book_type.cookbook';
    }
}
