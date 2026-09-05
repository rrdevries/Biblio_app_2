<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog;

use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\Edition;
use LogicException;

final readonly class LocalEditionResolution
{
    /** @param list<Edition> $editions */
    private function __construct(
        private LocalEditionResolutionType $type,
        private CanonicalIsbnIdentity $identity,
        private array $editions
    ) {
    }

    public static function exact(
        CanonicalIsbnIdentity $identity,
        Edition $edition
    ): self {
        return new self(
            LocalEditionResolutionType::LocalExact,
            $identity,
            [$edition]
        );
    }

    public static function none(CanonicalIsbnIdentity $identity): self
    {
        return new self(LocalEditionResolutionType::LocalNone, $identity, []);
    }

    /** @param list<Edition> $editions */
    public static function ambiguous(
        CanonicalIsbnIdentity $identity,
        array $editions
    ): self {
        return new self(
            LocalEditionResolutionType::LocalAmbiguous,
            $identity,
            $editions
        );
    }

    public function type(): LocalEditionResolutionType { return $this->type; }
    public function identity(): CanonicalIsbnIdentity { return $this->identity; }
    /** @return list<Edition> */
    public function editions(): array { return $this->editions; }
    public function edition(): ?Edition { return $this->editions[0] ?? null; }

    public function requireEdition(): Edition
    {
        return $this->edition()
            ?? throw new LogicException("Resolution does not contain an Edition.");
    }
}
