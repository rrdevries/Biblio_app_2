<?php

declare(strict_types=1);

namespace Biblio\Core\Exception;

interface CoreFailure
{
    public function reason(): FailureReason;
}
