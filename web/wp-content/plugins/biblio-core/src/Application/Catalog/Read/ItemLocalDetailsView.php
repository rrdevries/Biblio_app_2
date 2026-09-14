<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Catalog\Read;

use Biblio\Core\Catalog\ItemLocalDetails;
use Biblio\Core\Catalog\ItemLocalDetailsState;

final readonly class ItemLocalDetailsView
{
    public function __construct(
        private ?int $detailsVersion,
        private ItemLocalDetailsState $state
    ) {
    }

    public static function absent(): self
    {
        return new self(null, ItemLocalDetailsState::unknown());
    }

    public static function fromDetails(ItemLocalDetails $details): self
    {
        return new self($details->version()->value(), $details->state());
    }

    public function detailsVersion(): ?int { return $this->detailsVersion; }
    public function state(): ItemLocalDetailsState { return $this->state; }
}
