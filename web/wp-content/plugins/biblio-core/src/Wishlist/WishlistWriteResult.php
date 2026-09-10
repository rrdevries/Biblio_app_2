<?php

declare(strict_types=1);

namespace Biblio\Core\Wishlist;

final readonly class WishlistWriteResult
{
    private function __construct(
        private WishlistEntry $entry,
        private bool $created,
        private bool $refined
    ) {
    }

    public static function created(WishlistEntry $entry): self
    {
        return new self($entry, true, false);
    }

    public static function reused(WishlistEntry $entry): self
    {
        return new self($entry, false, false);
    }

    public static function refined(WishlistEntry $entry): self
    {
        return new self($entry, false, true);
    }

    public function entry(): WishlistEntry { return $this->entry; }
    public function wasCreated(): bool { return $this->created; }
    public function wasRefined(): bool { return $this->refined; }
}
