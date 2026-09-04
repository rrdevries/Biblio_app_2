<?php

declare(strict_types=1);

namespace Biblio\Core\Collections;

interface CollectionMembershipIdGenerator
{
    public function next(): CollectionMembershipId;
}
