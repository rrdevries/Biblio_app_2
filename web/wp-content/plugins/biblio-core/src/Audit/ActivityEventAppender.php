<?php

declare(strict_types=1);

namespace Biblio\Core\Audit;

interface ActivityEventAppender
{
    public function append(ActivityEvent $event): void;
}
