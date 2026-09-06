<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata;

use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\Work;

final readonly class AddBookExistingEdition
{
    public function __construct(
        private Work $work,
        private Edition $edition
    ) {
    }

    public function work(): Work { return $this->work; }
    public function edition(): Edition { return $this->edition; }
}
