<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use InvalidArgumentException;
use Biblio\Core\Exception\ValidationException;

final readonly class AddBookObservedMetadata
{
    /** @var array<string, MetadataFieldValue> */
    private array $values;

    /** @param array<string, MetadataFieldValue> $values */
    public function __construct(array $values)
    {
        $normalized = [];
        foreach ($values as $field => $value) {
            if (UserObservedMetadataField::tryFrom($field) === null) {
                throw new InvalidArgumentException(
                    "Unsupported user-observed metadata field."
                );
            }
            $normalized[$field] = $value;
        }
        $this->values = $normalized;
    }

    public function value(
        MetadataField|UserObservedMetadataField $field
    ): ?MetadataFieldValue
    {
        return $this->values[$field->value] ?? null;
    }

    /** @return array<string, MetadataFieldValue> */
    public function values(): array { return $this->values; }

    public function withCanonicalIsbn(string $isbn13): self
    {
        $observed = $this->value(UserObservedMetadataField::Isbn)?->value();
        if ($observed !== null && $observed !== $isbn13) {
            throw new ValidationException(
                "Observed ISBN does not match the canonical identifier."
            );
        }
        return new self([
            ...$this->values,
            UserObservedMetadataField::Isbn->value =>
                new MetadataFieldValue($isbn13),
        ]);
    }
}
