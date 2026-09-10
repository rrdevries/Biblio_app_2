<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Metadata\Discovery;

use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\WorkId;

interface BibliographicRecordIdGenerator
{
    public function nextWorkId(): WorkId;
    public function nextEditionId(): EditionId;
}
