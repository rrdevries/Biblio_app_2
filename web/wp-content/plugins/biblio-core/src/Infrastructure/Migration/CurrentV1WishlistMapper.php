<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Migration\Preservation\{
    PreservedSourceEvidenceMigrationParticipant,
    PreservedSourceEvidencePlan,
    PreservedSourceEvidencePrivacy
};
use Biblio\Core\Application\Migration\Runner\{
    DeterministicJson,
    MigrationPlanningTarget,
    MigrationRunnerFailure,
    MigrationRunnerReason,
    MigrationSourceInspection,
    MigrationSourceMappingFinding,
    MigrationSourceMappingResult,
    MigrationSourceRecord
};
use Biblio\Core\Application\Migration\Wishlist\{
    WishlistMigrationFailure,
    WishlistMigrationParticipant,
    WishlistPlan
};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\UserId;
use DateTimeImmutable;
use DateTimeZone;

/** Implements only the reviewed manifest-bound CURRENT Wishlist semantics. */
final readonly class CurrentV1WishlistMapper
{
    /** @var list<string> */
    private const FIELDS = [
        "authorSnapshot", "bookId", "createdAt", "desiredBinding",
        "desiredCarrier", "desiredLanguage", "fulfilledAt", "fulfilledCopyId",
        "id", "notes", "priority", "status", "titleGroupKey",
        "titleSnapshot", "type", "updatedAt",
    ];

    public function __construct(
        private CurrentV1ReviewedWishlistContract $contract =
            new CurrentV1ReviewedWishlistContract()
    ) {
    }

    /**
     * @param array<string, MigrationSourceRecord> $books
     * @param array<string, MigrationSourceRecord> $wishlist
     * @param array<string, string> $workRepresentatives book ID => representative Book ID
     */
    public function map(
        MigrationSourceInspection $inspection,
        MigrationPlanningTarget $target,
        array $books,
        array $wishlist,
        array $workRepresentatives
    ): MigrationSourceMappingResult {
        if (!hash_equals(
            $this->contract->manifestSha256(),
            $inspection->package()->manifestDigest()
        )) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::SourceChanged,
                "The reviewed CURRENT Wishlist contract does not match the source manifest."
            );
        }

        $records = [];
        $findings = [];
        $targetUserId = new UserId($target->userId());

        foreach ($wishlist as $item) {
            $payload = $item->payload();
            $bookId = $payload["bookId"] ?? null;
            if (!$this->validIdentityAndShape($item, $payload, $bookId)) {
                $findings[] = $this->finding(
                    $item,
                    MigrationDisposition::Quarantined,
                    CurrentV1WishlistMappingReason::InvalidSourceStructure
                );
                continue;
            }
            if (!isset($books[$this->sourceKey($bookId)])) {
                $findings[] = $this->finding(
                    $item,
                    MigrationDisposition::Quarantined,
                    CurrentV1WishlistMappingReason::UnmatchedParentBook
                );
                continue;
            }
            if (!array_key_exists($bookId, $workRepresentatives)) {
                $findings[] = $this->finding(
                    $item,
                    MigrationDisposition::Quarantined,
                    CurrentV1WishlistMappingReason::UnresolvedWorkIdentity
                );
                continue;
            }
            if (($payload["type"] ?? null) !== "edition") {
                $findings[] = $this->finding(
                    $item,
                    MigrationDisposition::Quarantined,
                    CurrentV1WishlistMappingReason::UnreviewedType
                );
                continue;
            }
            if (($payload["status"] ?? null) !== "active") {
                $findings[] = $this->finding(
                    $item,
                    MigrationDisposition::Quarantined,
                    CurrentV1WishlistMappingReason::InvalidLifecycle
                );
                continue;
            }
            if (
                ($payload["fulfilledCopyId"] ?? null) !== ""
                || ($payload["fulfilledAt"] ?? null) !== ""
            ) {
                $findings[] = $this->finding(
                    $item,
                    MigrationDisposition::Quarantined,
                    CurrentV1WishlistMappingReason::UnsupportedFulfillment
                );
                continue;
            }

            $createdAt = $this->timestamp($payload["createdAt"] ?? null);
            $updatedAt = $this->timestamp($payload["updatedAt"] ?? null);
            if (
                !$createdAt instanceof DateTimeImmutable
                || !$updatedAt instanceof DateTimeImmutable
                || $updatedAt < $createdAt
            ) {
                $findings[] = $this->finding(
                    $item,
                    MigrationDisposition::Quarantined,
                    CurrentV1WishlistMappingReason::InvalidTimestamp
                );
                continue;
            }
            if (
                !is_string($payload["titleGroupKey"] ?? null)
                || $payload["titleGroupKey"] === ""
                || !is_string($payload["desiredCarrier"] ?? null)
            ) {
                $findings[] = $this->finding(
                    $item,
                    MigrationDisposition::Quarantined,
                    CurrentV1WishlistMappingReason::InvalidAuxiliaryEvidence
                );
                continue;
            }

            $workSourceId = CurrentV1CatalogSourceIds::work($bookId);
            try {
                $active = MigrationSourceRecord::typed(
                    WishlistMigrationParticipant::SOURCE_TYPE,
                    $item->sourceId(),
                    new WishlistPlan(
                        $targetUserId,
                        $workSourceId,
                        $createdAt,
                        $updatedAt,
                        $this->contract->identity(),
                        $inspection->package()->manifestDigest(),
                        $inspection->adapter()->adapterId(),
                        $inspection->adapter()->sourceFamily(),
                        $inspection->profile()->sourceVersion()
                    ),
                    [CatalogWorkMigrationParticipant::SOURCE_TYPE . ":" . $workSourceId]
                );
            } catch (WishlistMigrationFailure | ValidationException) {
                $findings[] = $this->finding(
                    $item,
                    MigrationDisposition::Quarantined,
                    CurrentV1WishlistMappingReason::InvalidTimestamp
                );
                continue;
            }
            $preservation = $this->preservation($inspection, $item);
            $records[] = $active;
            $records[] = $preservation;
            $findings[] = $this->finding(
                $item,
                MigrationDisposition::Mapped,
                CurrentV1WishlistMappingReason::WorkOnlyPlanned,
                [$this->identity($active), $this->identity($preservation)]
            );
        }

        $findings[] = new MigrationSourceMappingFinding(
            "v1.wishlist_auxiliary",
            "mapping_contract:" . $this->contract->identity(),
            MigrationDisposition::Mapped,
            CurrentV1WishlistMappingReason::MappingContractApplied->value
        );

        return new MigrationSourceMappingResult($records, $findings);
    }

    /** @param array<string,mixed> $payload */
    private function validIdentityAndShape(
        MigrationSourceRecord $item,
        array $payload,
        mixed $bookId
    ): bool {
        $keys = array_keys($payload);
        $expected = self::FIELDS;
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if (!is_string($bookId) || $bookId === "") {
            return false;
        }
        $references = [CurrentV1SourceAdapter::BOOK . ":" . $bookId];
        if (
            is_string($payload["fulfilledCopyId"] ?? null)
            && $payload["fulfilledCopyId"] !== ""
        ) {
            $references[] = CurrentV1SourceAdapter::COPY . ":"
                . $payload["fulfilledCopyId"];
        }
        return trim($item->sourceId()) !== ""
            && ($payload["id"] ?? null) === $item->sourceId()
            && $keys === $expected
            && $item->references() === $references;
    }

    private function preservation(
        MigrationSourceInspection $inspection,
        MigrationSourceRecord $item
    ): MigrationSourceRecord {
        $payload = $item->payload();
        $sourceIdentity = CurrentV1SourceAdapter::WISHLIST_ITEM . "/"
            . $item->sourceId() . "/auxiliary";
        $plan = new PreservedSourceEvidencePlan(
            $sourceIdentity,
            "current_v1_wishlist_auxiliary",
            CurrentV1WishlistMappingReason::AuxiliaryEvidencePreserved->value,
            $inspection->adapter()->adapterId(),
            $inspection->adapter()->sourceFamily(),
            $inspection->profile()->sourceVersion(),
            $inspection->package()->manifestDigest(),
            $this->contract->identity(),
            "data/books.json",
            "wishlistItems",
            $item->sourceId(),
            "auxiliaryEvidence",
            DeterministicJson::hash([
                "source_slot" => "wishlistItems",
                "raw_type" => $payload["type"],
                "title_group_key" => $payload["titleGroupKey"],
                "desired_carrier" => $payload["desiredCarrier"],
            ]),
            PreservedSourceEvidencePrivacy::RestrictedSource
        );

        return MigrationSourceRecord::typed(
            PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE,
            $sourceIdentity,
            $plan
        );
    }

    private function timestamp(mixed $raw): ?DateTimeImmutable
    {
        if (
            !is_string($raw)
            || preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}Z$/D',
                $raw
            ) !== 1
        ) {
            return null;
        }
        $instant = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.v\Z',
            $raw,
            new DateTimeZone("UTC")
        );
        $errors = DateTimeImmutable::getLastErrors();
        if (
            !$instant instanceof DateTimeImmutable
            || ($errors !== false
                && ($errors["warning_count"] > 0 || $errors["error_count"] > 0))
            || $instant->format('Y-m-d\TH:i:s.v\Z') !== $raw
        ) {
            return null;
        }

        return $instant;
    }

    /** @param list<array{source_type:string,source_id:string}> $planned */
    private function finding(
        MigrationSourceRecord $record,
        MigrationDisposition $disposition,
        CurrentV1WishlistMappingReason $reason,
        array $planned = []
    ): MigrationSourceMappingFinding {
        return new MigrationSourceMappingFinding(
            $record->sourceType(),
            $record->sourceId(),
            $disposition,
            $reason->value,
            $planned
        );
    }

    /** @return array{source_type:string,source_id:string} */
    private function identity(MigrationSourceRecord $record): array
    {
        return [
            "source_type" => $record->sourceType(),
            "source_id" => $record->sourceId(),
        ];
    }

    private function sourceKey(string $sourceId): string
    {
        return "source:" . $sourceId;
    }
}
