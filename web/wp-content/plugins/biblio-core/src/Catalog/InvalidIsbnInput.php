<?php

declare(strict_types=1);

namespace Biblio\Core\Catalog;

use Biblio\Core\Exception\CoreFailure;
use Biblio\Core\Exception\FailureReason;
use InvalidArgumentException;

final class InvalidIsbnInput extends InvalidArgumentException implements CoreFailure
{
    public function __construct(
        private readonly IsbnInputError $inputError,
        string $message
    ) {
        parent::__construct($message);
    }

    public function inputError(): IsbnInputError
    {
        return $this->inputError;
    }

    public function reason(): FailureReason
    {
        return FailureReason::ValidationFailed;
    }
}
