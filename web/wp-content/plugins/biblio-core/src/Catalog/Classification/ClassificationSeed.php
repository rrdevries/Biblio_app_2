<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog\Classification;

final readonly class ClassificationSeed
{
    public function __construct(
        private ClassificationSeedKey $key,
        private ClassificationTermName $defaultName
    ) {
    }

    public function key(): ClassificationSeedKey
    {
        return $this->key;
    }

    public function defaultName(): ClassificationTermName
    {
        return $this->defaultName;
    }
}
