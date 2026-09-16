<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Catalog\CatalogItemPreservationPlan;
use Biblio\Core\Application\Migration\MigrationDisposition;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerFailure;
use Biblio\Core\Application\Migration\Runner\MigrationRunnerReason;
use Biblio\Core\Application\Migration\Runner\MigrationSourceInspection;
use Biblio\Core\Application\Migration\Runner\MigrationSourceMappingFinding;
use Biblio\Core\Application\Migration\Runner\MigrationSourceRecord;
use Biblio\Core\Catalog\AcquisitionMethod;
use Biblio\Core\Catalog\ItemAcquisitionDate;
use Biblio\Core\Catalog\ItemLocalDetailsState;
use Biblio\Core\Exception\ValidationException;

/**
 * D-MIG-ITEMLOCAL-MAP-01 semantic bridge for the one reviewed CURRENT source.
 * It neither parses another source shape nor owns product or ledger writes.
 */
final readonly class CurrentV1ItemLocalMapper
{
    public function __construct(
        private CurrentV1ReviewedItemLocalContract $contract =
            new CurrentV1ReviewedItemLocalContract()
    ) {
    }

    /**
     * @param array<string,MigrationSourceRecord> $copiesByKey
     * @param array<string,MigrationSourceRecord> $booksByKey
     */
    public function map(
        MigrationSourceInspection $inspection,
        array $copiesByKey,
        array $booksByKey
    ): CurrentV1ItemLocalMappingResult {
        if (!hash_equals(
            $this->contract->manifestSha256(),
            $inspection->package()->manifestDigest()
        )) {
            throw new MigrationRunnerFailure(
                MigrationRunnerReason::SourceChanged,
                "The reviewed CURRENT Item-local contract does not match the source manifest."
            );
        }

        $mappings = [];
        $findings = [];
        foreach ($booksByKey as $book) {
            if ($this->hasBookAcquisitionEvidence($book->payload())) {
                $findings[] = $this->finding(
                    $book,
                    MigrationDisposition::PreservedDeferred,
                    CurrentV1ItemLocalMappingReason::BookAcquisitionEvidencePreserved
                );
            }
        }

        foreach ($copiesByKey as $copy) {
            $mapping = $this->mapCopy($copy, $findings);
            $mappings[$copy->sourceId()] = $mapping;
        }

        $findings[] = new MigrationSourceMappingFinding(
            "v1.item_local_auxiliary",
            "mapping_contract:" . $this->contract->identity(),
            MigrationDisposition::Mapped,
            CurrentV1ItemLocalMappingReason::MappingContractApplied->value
        );

        return new CurrentV1ItemLocalMappingResult($mappings, $findings);
    }

    /** @param list<MigrationSourceMappingFinding> $findings */
    private function mapCopy(
        MigrationSourceRecord $copy,
        array &$findings
    ): CurrentV1ItemLocalMapping {
        $payload = $copy->payload();
        $acquisition = $payload["acquisition"] ?? null;
        if ($acquisition !== null && (!is_array($acquisition) || array_is_list($acquisition))) {
            throw $this->unsupported("CURRENT V1 Copy acquisition structure is unsupported.");
        }

        $type = null;
        $dateValue = null;
        $source = null;
        if ($acquisition !== null) {
            $type = $acquisition["type"] ?? null;
            $dateValue = $acquisition["date"] ?? null;
            $source = $acquisition["source"] ?? null;
        }
        if ($type !== null && !is_string($type)) {
            throw $this->unsupported("CURRENT V1 Copy acquisition type is unsupported.");
        }
        if ($type === "borrowed") {
            $findings[] = $this->finding(
                $copy,
                MigrationDisposition::PreservedDeferred,
                CurrentV1ItemLocalMappingReason::ExternalBorrowedCopyPreserved
            );
            return new CurrentV1ItemLocalMapping(
                CurrentV1ItemEligibility::NotLibraryItemExternalBorrowed,
                null
            );
        }
        if (!in_array($type, [null, "", "bought", "received"], true)) {
            throw $this->unsupported("CURRENT V1 Copy acquisition type is not reviewed.");
        }

        try {
            $date = $this->acquisitionDate($dateValue);
        } catch (ValidationException) {
            $findings[] = $this->finding(
                $copy,
                MigrationDisposition::Quarantined,
                CurrentV1ItemLocalMappingReason::InvalidAcquisitionDate
            );
            return new CurrentV1ItemLocalMapping(
                CurrentV1ItemEligibility::InvalidItemLocalAcquisitionDate,
                null
            );
        }

        if ($source === "") {
            $source = null;
        }
        if ($source !== null && !is_string($source)) {
            throw $this->unsupported("CURRENT V1 acquisition source text is unsupported.");
        }
        $method = match ($type) {
            "bought" => AcquisitionMethod::Purchased,
            "received" => AcquisitionMethod::Received,
            default => null,
        };
        $state = new ItemLocalDetailsState(
            inLibrarySince: $date,
            acquisitionMethod: $method,
            acquiredVia: $source
        );
        $details = $state->isUnknown() ? null : $state;
        $findings[] = $this->finding(
            $copy,
            MigrationDisposition::Mapped,
            $details === null
                ? CurrentV1ItemLocalMappingReason::ReviewedAbsence
                : CurrentV1ItemLocalMappingReason::NonEmptyStateReady
        );

        $evidence = $this->copyEvidenceFlags($payload);
        $preservation = null;
        if (array_sum(array_map(
            static fn (bool|int $value): int => is_bool($value) ? (int) $value : $value,
            $evidence
        )) > 0) {
            $findings[] = $this->finding(
                $copy,
                MigrationDisposition::PreservedDeferred,
                CurrentV1ItemLocalMappingReason::CopyAuxiliaryEvidencePreserved
            );
            $preservation = new CatalogItemPreservationPlan(
                CurrentV1ItemLocalMappingReason::CopyAuxiliaryEvidencePreserved->value,
                $copy->payloadHash(),
                CurrentV1SourceAdapter::SOURCE_FAMILY . ":"
                    . CurrentV1SourceAdapter::COPY . ":" . $copy->sourceId(),
                $evidence
            );
        }

        return new CurrentV1ItemLocalMapping(
            CurrentV1ItemEligibility::ItemEligible,
            $details,
            $preservation
        );
    }

    private function acquisitionDate(mixed $raw): ?ItemAcquisitionDate
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw) || array_is_list($raw)) {
            throw new ValidationException("Acquisition date shape is invalid.");
        }
        $precision = $raw["precision"] ?? null;
        $value = $raw["value"] ?? null;
        if (!is_string($precision) || !is_string($value)) {
            throw new ValidationException("Acquisition date value is invalid.");
        }

        return match ($precision) {
            "year" => preg_match('/^(\d{4})$/D', $value, $parts) === 1
                ? new ItemAcquisitionDate((int) $parts[1])
                : throw new ValidationException("Acquisition year shape is invalid."),
            "month" => preg_match('/^(\d{4})-(\d{2})$/D', $value, $parts) === 1
                ? new ItemAcquisitionDate((int) $parts[1], (int) $parts[2])
                : throw new ValidationException("Acquisition month shape is invalid."),
            "day" => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) === 1
                ? new ItemAcquisitionDate(
                    (int) $parts[1],
                    (int) $parts[2],
                    (int) $parts[3]
                )
                : throw new ValidationException("Acquisition day shape is invalid."),
            default => throw new ValidationException(
                "Acquisition date precision is invalid."
            ),
        };
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,bool|int>
     */
    private function copyEvidenceFlags(array $payload): array
    {
        $sourceNumbers = 0;
        foreach (["copyNumber", "legacyBookNumber", "sourceBookNumber"] as $field) {
            if ($this->nonEmptyString($payload[$field] ?? null)) {
                ++$sourceNumbers;
            }
        }
        $photos = 0;
        $slots = $payload["exemplarPhotos"] ?? null;
        if (is_array($slots)) {
            foreach ($slots as $slot) {
                if ($this->nonEmptyString($slot)) {
                    ++$photos;
                }
            }
        }

        return [
            "copy_note_present" => $this->nonEmptyString($payload["notes"] ?? null),
            "disposal_present" => is_array($payload["disposal"] ?? null),
            "exemplar_photo_slot_count" => $photos,
            "source_number_field_count" => $sourceNumbers,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function hasBookAcquisitionEvidence(array $payload): bool
    {
        if (is_array($payload["acquisition"] ?? null)) {
            return true;
        }
        foreach (["acquiredAt", "acqYear", "acqMonth", "acquiredVia", "giftFrom"] as $field) {
            if ($this->nonEmptyString($payload[$field] ?? null)) {
                return true;
            }
        }
        return false;
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== "";
    }

    private function finding(
        MigrationSourceRecord $record,
        MigrationDisposition $disposition,
        CurrentV1ItemLocalMappingReason $reason
    ): MigrationSourceMappingFinding {
        return new MigrationSourceMappingFinding(
            $record->sourceType(),
            $record->sourceId(),
            $disposition,
            $reason->value
        );
    }

    private function unsupported(string $message): MigrationRunnerFailure
    {
        return new MigrationRunnerFailure(
            MigrationRunnerReason::UnsupportedStructure,
            $message
        );
    }
}
