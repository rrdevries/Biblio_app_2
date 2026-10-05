<?php

declare(strict_types=1);

namespace Biblio\Core\Accounts;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryName;

interface AccountPreparationRepository
{
    public function find(UserId $userId): ?AccountPreparation;
    public function save(AccountPreparation $preparation): void;
    public function exclusively(UserId $userId, callable $operation): mixed;
    /** @return list<LibraryId> */
    public function ownedLibraries(UserId $userId): array;
    public function lockContext(UserId $userId, LibraryId $libraryId): void;
    public function rename(LibraryId $libraryId, LibraryName $name): void;
}
