<?php

declare(strict_types=1);

namespace Biblio\Core\Accounts;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Exception\ValidationException;

final readonly class AccountPreparation
{
    public function __construct(
        public UserId $userId,
        public ?LibraryId $libraryId = null,
        public bool $named = false,
        public bool $notified = false
    ) {
        if ($libraryId === null && ($named || $notified)) {
            throw new ValidationException("An incomplete account cannot be named or notified.");
        }
    }
}
