<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

/**
 * One provider-neutral Add Book review binding.
 *
 * Contributor role mappings expose the canonical targets, while current
 * untyped contributor values use the evidence-only fallback. Format mappings
 * remain empty until a canonical allowlist contains a value.
 */
final readonly class MetadataFieldBinding
{
    /**
     * @param array<string, MetadataBindingTarget> $explicitMappings
     */
    public function __construct(
        private MetadataField $field,
        private MetadataBindingTarget $target,
        private array $explicitMappings = [],
        private ?MetadataBindingTarget $fallbackTarget = null
    ) {
    }

    public function field(): MetadataField { return $this->field; }
    public function target(): MetadataBindingTarget { return $this->target; }

    /** @return array<string, MetadataBindingTarget> */
    public function explicitMappings(): array { return $this->explicitMappings; }

    public function fallbackTarget(): ?MetadataBindingTarget
    {
        return $this->fallbackTarget;
    }
}
