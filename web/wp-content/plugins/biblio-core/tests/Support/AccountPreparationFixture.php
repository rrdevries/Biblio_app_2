<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Support;

use Biblio\Core\Accounts\AccountDirectory;
use Biblio\Core\Application\Accounts\AccountPreparationService;
use Biblio\Core\Application\Library\{CreateLibraryService,ProvisionPersonalPrivateLibraryService};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{CoreTableNames,WpdbAccountPreparationRepository,WpdbLibraryRepository,WpdbLibraryMembershipRepository,WpdbPersonalLibraryRepository,WpdbTransactionManager,WpdbClassificationSeedEvolutionFactory};
use wpdb;

final class AccountPreparationFixture
{
    public static function service(wpdb $db, CoreTableNames $tables, string $actor = "operator"): AccountPreparationService
    {
        $directory = new class implements AccountDirectory {
            public function isActive(UserId $id): bool { return $id->value() !== "inactive"; }
            public function isRequested(UserId $id): bool { return str_starts_with($id->value(), "new-"); }
            public function canManageAccounts(UserId $id): bool { return $id->value() === "operator"; }
        };
        $libraries = new WpdbLibraryRepository($db, $tables);
        $memberships = new WpdbLibraryMembershipRepository($db, $tables);
        $personal = new WpdbPersonalLibraryRepository($db, $tables);
        $transaction = new WpdbTransactionManager($db);
        $provisioner = new ProvisionPersonalPrivateLibraryService($personal, new CreateLibraryService(
            $libraries, $memberships, WpdbClassificationSeedEvolutionFactory::create($db, $tables), $transaction
        ));
        return new AccountPreparationService(new ControllableAuthenticatedUser(new UserId($actor)), $directory,
            new WpdbAccountPreparationRepository($db, $tables), $personal, $libraries, $memberships, $provisioner, $transaction);
    }
}
