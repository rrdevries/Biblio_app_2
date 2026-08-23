<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Library;

use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryName;
use Biblio\Core\Library\LibraryStatus;
use Biblio\Core\Library\LibraryType;

final readonly class LibraryContextView
{
    public function __construct(
        private LibraryId $libraryId,
        private LibraryName $name,
        private LibraryType $type,
        private LibraryStatus $status,
        private bool $designatedPersonal,
        private LibraryCapabilities $capabilities
    ) {
    }

    public function libraryId(): LibraryId { return $this->libraryId; }
    public function name(): LibraryName { return $this->name; }
    public function type(): LibraryType { return $this->type; }
    public function status(): LibraryStatus { return $this->status; }
    public function isDesignatedPersonal(): bool { return $this->designatedPersonal; }
    public function capabilities(): LibraryCapabilities { return $this->capabilities; }
}
