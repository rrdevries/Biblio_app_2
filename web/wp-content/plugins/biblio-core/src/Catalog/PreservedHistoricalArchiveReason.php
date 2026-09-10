<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\ValidationException;

final readonly class PreservedHistoricalArchiveReason implements ItemArchiveReasonValue
{
    public const MAXIMUM_TEXT_LENGTH = 500;
    public const MAXIMUM_ORIGINAL_VALUE_LENGTH = 191;

    public function __construct(
        private string $originalText,
        private ?string $originalValue = null
    ) {
        self::assertPlainText(
            $this->originalText,
            self::MAXIMUM_TEXT_LENGTH,
            "Preserved historical archive reason"
        );

        if ($this->originalValue !== null) {
            self::assertPlainText(
                $this->originalValue,
                self::MAXIMUM_ORIGINAL_VALUE_LENGTH,
                "Preserved historical archive reason original value"
            );
        }
    }

    public function kind(): ItemArchiveReasonKind
    {
        return ItemArchiveReasonKind::PreservedHistorical;
    }

    public function originalText(): string
    {
        return $this->originalText;
    }

    public function originalValue(): ?string
    {
        return $this->originalValue;
    }

    public function equals(ItemArchiveReasonValue $other): bool
    {
        return $other instanceof self
            && $other->originalText === $this->originalText
            && $other->originalValue === $this->originalValue;
    }

    private static function assertPlainText(
        string $value,
        int $maximumLength,
        string $label
    ): void {
        if (
            preg_match('//u', $value) !== 1
            || trim($value) === ""
            || mb_strlen($value) > $maximumLength
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1
            || preg_match('/<\/?[a-z][^>]*>/iu', $value) === 1
        ) {
            throw new ValidationException(
                "{$label} must be non-empty plain UTF-8 text of at most "
                    . "{$maximumLength} characters."
            );
        }
    }
}
