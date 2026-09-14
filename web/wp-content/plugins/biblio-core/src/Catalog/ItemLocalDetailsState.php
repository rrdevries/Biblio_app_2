<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class ItemLocalDetailsState
{
    public function __construct(
        private ?ItemCondition $condition = null,
        private ?ItemAcquisitionDate $inLibrarySince = null,
        private ?AcquisitionMethod $acquisitionMethod = null,
        private ?string $acquiredVia = null,
        private ?PaidAmount $paidAmount = null,
        private ?bool $signed = null,
        private ?string $signedBy = null,
        private ?string $copyLimitation = null,
        private ?DustJacketState $dustJacket = null,
        private ?bool $inscription = null,
        private ?string $provenance = null,
        private ?string $completeness = null
    ) {
        self::assertText($this->acquiredVia, 512, "Acquired via");
        self::assertText($this->signedBy, 512, "Signed by");
        self::assertText($this->copyLimitation, 191, "Copy limitation");
        self::assertText($this->provenance, 1024, "Provenance");
        self::assertText($this->completeness, 1024, "Completeness");

        if ($this->signedBy !== null && $this->signed !== true) {
            throw new ValidationException("Signed by requires signed=true.");
        }
    }

    public static function unknown(): self { return new self(); }

    public function condition(): ?ItemCondition { return $this->condition; }
    public function inLibrarySince(): ?ItemAcquisitionDate { return $this->inLibrarySince; }
    public function acquisitionMethod(): ?AcquisitionMethod { return $this->acquisitionMethod; }
    public function acquiredVia(): ?string { return $this->acquiredVia; }
    public function paidAmount(): ?PaidAmount { return $this->paidAmount; }
    public function signed(): ?bool { return $this->signed; }
    public function signedBy(): ?string { return $this->signedBy; }
    public function copyLimitation(): ?string { return $this->copyLimitation; }
    public function dustJacket(): ?DustJacketState { return $this->dustJacket; }
    public function inscription(): ?bool { return $this->inscription; }
    public function provenance(): ?string { return $this->provenance; }
    public function completeness(): ?string { return $this->completeness; }

    public function isUnknown(): bool
    {
        return $this->condition === null
            && $this->inLibrarySince === null
            && $this->acquisitionMethod === null
            && $this->acquiredVia === null
            && $this->paidAmount === null
            && $this->signed === null
            && $this->signedBy === null
            && $this->copyLimitation === null
            && $this->dustJacket === null
            && $this->inscription === null
            && $this->provenance === null
            && $this->completeness === null;
    }

    public function equals(self $other): bool
    {
        return $this->condition === $other->condition
            && self::dateEquals($this->inLibrarySince, $other->inLibrarySince)
            && $this->acquisitionMethod === $other->acquisitionMethod
            && $this->acquiredVia === $other->acquiredVia
            && self::amountEquals($this->paidAmount, $other->paidAmount)
            && $this->signed === $other->signed
            && $this->signedBy === $other->signedBy
            && $this->copyLimitation === $other->copyLimitation
            && $this->dustJacket === $other->dustJacket
            && $this->inscription === $other->inscription
            && $this->provenance === $other->provenance
            && $this->completeness === $other->completeness;
    }

    private static function assertText(?string $value, int $maximum, string $field): void
    {
        if ($value === null) {
            return;
        }
        if (
            preg_match('//u', $value) !== 1
            || mb_strlen($value, "UTF-8") > $maximum
            || preg_match('/[^\p{Z}\s]/u', $value) !== 1
            || preg_match('/\p{Cc}/u', $value) === 1
        ) {
            throw new ValidationException("{$field} text is invalid.");
        }
    }

    private static function dateEquals(?ItemAcquisitionDate $left, ?ItemAcquisitionDate $right): bool
    {
        return $left === null ? $right === null : $right !== null && $left->equals($right);
    }

    private static function amountEquals(?PaidAmount $left, ?PaidAmount $right): bool
    {
        return $left === null ? $right === null : $right !== null && $left->equals($right);
    }
}
