<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Wishlist;

use Biblio\Core\Application\Migration\Runner\TypedMigrationPlan;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Identity\{IdentifierConstraints,UserId};
use Biblio\Core\Temporal\PersistedDateTimeConstraints;
use DateTimeImmutable;
use DateTimeZone;

/** Source-neutral plan for one active Work-only Wishlist intent. */
final readonly class WishlistPlan implements TypedMigrationPlan
{
    public function __construct(
        private UserId $targetUserId,
        private string $workSourceId,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
        private string $mappingContract,
        private string $sourceManifestSha256,
        private string $sourceAdapterId,
        private string $sourceFamily,
        private string $sourceVersion
    ) {
        IdentifierConstraints::assertValid(
            $this->workSourceId,
            "Wishlist Work source ID"
        );
        if (
            trim($this->mappingContract) === ""
            || mb_strlen($this->mappingContract) > 191
            || preg_match('/^[a-f0-9]{64}$/D', $this->sourceManifestSha256) !== 1
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $this->sourceAdapterId) !== 1
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $this->sourceFamily) !== 1
            || trim($this->sourceVersion) === ""
            || mb_strlen($this->sourceVersion) > 191
        ) {
            throw new ValidationException("Wishlist migration provenance is invalid.");
        }

        try {
            PersistedDateTimeConstraints::assertSupported(
                $this->createdAt,
                "Wishlist creation time"
            );
            PersistedDateTimeConstraints::assertSupported(
                $this->updatedAt,
                "Wishlist update time"
            );
            if ($this->updatedAt < $this->createdAt) {
                throw new ValidationException(
                    "Wishlist update time cannot precede creation time."
                );
            }
        } catch (ValidationException $failure) {
            throw new WishlistMigrationFailure(
                WishlistMigrationReason::InvalidHistoricalTimestamp,
                "Wishlist plan has invalid approved timestamps.",
                $failure
            );
        }
    }

    public function targetUserId(): UserId { return $this->targetUserId; }
    public function workSourceId(): string { return $this->workSourceId; }
    public function createdAt(): DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): DateTimeImmutable { return $this->updatedAt; }
    public function mappingContract(): string { return $this->mappingContract; }
    public function sourceManifestSha256(): string
    {
        return $this->sourceManifestSha256;
    }
    public function sourceAdapterId(): string { return $this->sourceAdapterId; }
    public function sourceFamily(): string { return $this->sourceFamily; }
    public function sourceVersion(): string { return $this->sourceVersion; }

    public function canonicalPayload(): array
    {
        return [
            "target_kind" => "wishlist_entry",
            "target_user_id" => $this->targetUserId->value(),
            "specificity" => "work_only",
            "work_source_id" => $this->workSourceId,
            "edition_source_id" => null,
            "lifecycle" => "active",
            "created_at" => self::instant($this->createdAt),
            "updated_at" => self::instant($this->updatedAt),
            "mapping_contract" => $this->mappingContract,
            "source_manifest_sha256" => $this->sourceManifestSha256,
            "source_adapter_id" => $this->sourceAdapterId,
            "source_family" => $this->sourceFamily,
            "source_version" => $this->sourceVersion,
        ];
    }

    private static function instant(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone("UTC"))
            ->format("Y-m-d\\TH:i:s.u\\Z");
    }
}
