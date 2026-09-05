<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use InvalidArgumentException;

final readonly class MetadataWorkLink
{
    public function __construct(private string $providerWorkKey)
    {
        if (
            $providerWorkKey === ""
            || strlen($providerWorkKey) > 64
            || trim($providerWorkKey) !== $providerWorkKey
        ) {
            throw new InvalidArgumentException("Invalid provider Work key.");
        }
    }

    public function providerWorkKey(): string
    {
        return $this->providerWorkKey;
    }

    public function relation(): MetadataWorkRelation
    {
        return MetadataWorkRelation::ExplicitLink;
    }
}
