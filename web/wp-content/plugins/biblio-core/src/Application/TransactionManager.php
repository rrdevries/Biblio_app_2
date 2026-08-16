<?php

declare(strict_types=1);

namespace Biblio\Core\Application;

interface TransactionManager
{
    public function run(callable $operation): mixed;
}
