<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

interface CollectionIdGenerator
{
    public function next(): CollectionId;
}
