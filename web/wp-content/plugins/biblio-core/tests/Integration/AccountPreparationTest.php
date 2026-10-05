<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Accounts\AccountPreparation;
use Biblio\Core\Exception\{AuthorizationException,ValidationException};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\{Library,LibraryId,LibraryName,LibraryMembership,LibraryMembershipAssignment};
use Biblio\Core\Infrastructure\Persistence\WordPress\{WpdbAccountPreparationRepository,WpdbLibraryRepository,WpdbLibraryMembershipRepository,WpdbPersonalLibraryRepository};
use Biblio\Core\Tests\Support\AccountPreparationFixture;

require_once dirname(__DIR__) . "/Support/AccountPreparationFixture.php";

final class AccountPreparationTest extends PersistenceIntegrationTestCase
{
    public function testPreparationAndNamingPreserveOneLibraryOnRetry(): void
    {
        $target = new UserId("new-test");
        $operator = AccountPreparationFixture::service($this->database, $this->tableNames);
        $id = $operator->prepare($target);
        self::assertTrue($id->equals($operator->prepare($target)));
        self::assertCount(1, (new WpdbLibraryRepository($this->database, $this->tableNames))->all());
        $owner = AccountPreparationFixture::service($this->database, $this->tableNames, "new-test");
        self::assertTrue($owner->myStatus()["needs_name"]);
        $this->denied(fn () => $owner->requireReady($target));
        $owner->saveName($id, "  Renées   boeken  ");
        $owner->requireReady($target);
        self::assertFalse($owner->myStatus()["needs_name"]);
        self::assertSame("Renées boeken", $owner->myStatus()["name"]);
        $owner->saveName($id, "Mijn Bibliotheek");
        self::assertFalse($owner->myStatus()["needs_name"]);
        self::assertSame($id->value(), $owner->myStatus()["library_id"]);
        self::assertTrue($id->equals($operator->prepare($target)));
    }

    public function testLegacyAccountsAreNeitherForcedToNameNorProvisionedByReads(): void
    {
        $target = new UserId("legacy-test");
        $id = $this->ownedLibrary($target, "existing", "Bestaande boeken");
        (new WpdbPersonalLibraryRepository($this->database, $this->tableNames))->designate($target, $id);
        $owner = AccountPreparationFixture::service($this->database, $this->tableNames, $target->value());
        self::assertSame("legacy", $owner->myStatus()["state"]);
        self::assertFalse($owner->myStatus()["needs_name"]);
        $owner->requireReady($target);
        self::assertNull((new WpdbAccountPreparationRepository($this->database, $this->tableNames))->find($target));
        $owner->saveName($id, "Zelf gekozen naam");
        self::assertSame("Zelf gekozen naam", $owner->myStatus()["name"]);
        $missing = AccountPreparationFixture::service($this->database, $this->tableNames, "legacy-empty");
        self::assertSame("requires_review", $missing->myStatus()["state"]);
        self::assertCount(1, (new WpdbLibraryRepository($this->database, $this->tableNames))->all());
    }

    public function testForeignRenameAndOrdinaryAccountCreationFailWithoutWrites(): void
    {
        $operator = AccountPreparationFixture::service($this->database, $this->tableNames);
        $id = $operator->prepare(new UserId("new-owner"));
        $other = AccountPreparationFixture::service($this->database, $this->tableNames, "ordinary");
        $this->denied(fn () => $other->saveName($id, "Gestolen"));
        $this->denied(fn () => $other->prepare(new UserId("new-other")));
        $this->denied(fn () => $other->repair(new UserId("legacy-other")));
        self::assertSame("Mijn Bibliotheek", (new WpdbLibraryRepository($this->database, $this->tableNames))->find($id)?->name()->value());
        self::assertCount(1, (new WpdbLibraryRepository($this->database, $this->tableNames))->all());
    }

    public function testInvalidNameDoesNotCompleteNaming(): void
    {
        $id = AccountPreparationFixture::service($this->database, $this->tableNames)->prepare(new UserId("new-invalid"));
        $owner = AccountPreparationFixture::service($this->database, $this->tableNames, "new-invalid");
        foreach (["  ", "\u{00a0}\u{2003}", str_repeat("é", 192), "\xff"] as $name) {
            try { $owner->saveName($id, $name); self::fail("Invalid name accepted."); }
            catch (ValidationException) { self::assertTrue($owner->myStatus()["needs_name"]); }
        }
    }

    public function testPartialFailureCanResumeWithoutASecondLibrary(): void
    {
        $target = new UserId("new-resume");
        $operator = AccountPreparationFixture::service($this->database, $this->tableNames);
        $table = $this->tableNames->accountPreparations();
        self::assertNotFalse($this->database->query("CREATE TRIGGER biblio_test_account_fail BEFORE UPDATE ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected preparation failure'"));
        try {
            try { $operator->prepare($target); self::fail("Injected failure did not fire."); }
            catch (\Biblio\Core\Infrastructure\Persistence\PersistenceException) {
                $state = (new WpdbAccountPreparationRepository($this->database, $this->tableNames))->find($target);
                self::assertNull($state?->libraryId);
                $this->denied(fn () => $operator->requireReady($target, false));
                self::assertCount(1, (new WpdbLibraryRepository($this->database, $this->tableNames))->all());
            }
        } finally { $this->database->query("DROP TRIGGER biblio_test_account_fail"); }
        $id = $operator->prepare($target);
        self::assertTrue($id->equals($operator->prepare($target)));
        self::assertCount(1, (new WpdbLibraryRepository($this->database, $this->tableNames))->all());
    }

    public function testNameAndCompletionRollBackTogether(): void
    {
        $id = AccountPreparationFixture::service($this->database, $this->tableNames)->prepare(new UserId("new-rollback"));
        $owner = AccountPreparationFixture::service($this->database, $this->tableNames, "new-rollback");
        $table = $this->tableNames->accountPreparations();
        $this->database->query("CREATE TRIGGER biblio_test_name_fail BEFORE UPDATE ON `{$table}` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected naming failure'");
        try {
            try { $owner->saveName($id, "Verloren write"); self::fail("Injected failure did not fire."); }
            catch (\Biblio\Core\Infrastructure\Persistence\PersistenceException) {
                self::assertTrue($owner->myStatus()["needs_name"]);
                self::assertSame("Mijn Bibliotheek", $owner->myStatus()["name"]);
            }
        } finally { $this->database->query("DROP TRIGGER biblio_test_name_fail"); }
    }

    public function testExistingOwnershipRequiresExplicitExactRepair(): void
    {
        $target = new UserId("legacy-unlinked");
        $id = $this->ownedLibrary($target, "old-personal", "Oude naam");
        $operator = AccountPreparationFixture::service($this->database, $this->tableNames);
        $this->denied(fn () => $operator->repair($target));
        self::assertTrue($id->equals($operator->repair($target, $id)));
        self::assertSame("Oude naam", AccountPreparationFixture::service($this->database, $this->tableNames, $target->value())->myStatus()["name"]);
        self::assertFalse(AccountPreparationFixture::service($this->database, $this->tableNames, $target->value())->myStatus()["needs_name"]);
        $this->denied(fn () => $operator->repair(new UserId("legacy-foreign"), $id));
        self::assertCount(1, (new WpdbLibraryRepository($this->database, $this->tableNames))->all());
    }

    public function testNotificationRetryIsIndependentAndIdempotent(): void
    {
        $target = new UserId("new-notify");
        $operator = AccountPreparationFixture::service($this->database, $this->tableNames);
        $this->denied(fn () => $operator->notify($target, static fn (): bool => true));
        $id = $operator->prepare($target);
        self::assertFalse($operator->notify($target, static fn (): bool => false));
        $sends = 0;
        $send = static function () use (&$sends): bool { $sends++; return true; };
        self::assertTrue($operator->notify($target, $send));
        self::assertTrue($operator->notify($target, $send));
        self::assertSame(1, $sends);
        self::assertTrue($id->equals($operator->prepare($target)));
    }

    public function testInactiveMembershipDeniesRename(): void
    {
        $target = new UserId("new-deactivated");
        $id = AccountPreparationFixture::service($this->database, $this->tableNames)->prepare($target);
        $memberships = $this->tableNames->memberships();
        $this->database->query($this->database->prepare("UPDATE `{$memberships}` SET membership_status='inactive' WHERE user_id=%s", $target->value()));
        $this->denied(fn () => AccountPreparationFixture::service($this->database, $this->tableNames, $target->value())->saveName($id, "Niet toegestaan"));
    }

    public function testTargetedRepairAndRenamePreserveBooksMembershipsAndPersonalLoans(): void
    {
        $target = new UserId("legacy-content");
        $id = $this->ownedLibrary($target, "existing-content", "Mijn bestaande boeken");
        $rows = [
            [$this->tableNames->works(), ["work_id" => "preserved-work", "work_title" => "Bestaand boek"]],
            [$this->tableNames->editions(), ["edition_id" => "preserved-edition", "work_id" => "preserved-work", "edition_title" => "Bestaande uitgave"]],
            [$this->tableNames->items(), ["item_id" => "preserved-item", "library_id" => $id->value(), "edition_id" => "preserved-edition", "item_status" => "active"]],
            [$this->tableNames->externalLoans(), ["external_loan_id" => "preserved-loan", "user_id" => $target->value(), "work_id" => "preserved-work", "loan_status" => "active", "borrowed_at" => "2026-08-17 08:00:00.000000", "due_at" => null]],
        ];
        foreach ($rows as [$table, $data]) { self::assertSame(1, $this->database->insert($table, $data)); }
        $tables = [$this->tableNames->memberships(), ...array_column($rows, 0)];
        $before = array_map(fn (string $table): array => $this->database->get_results("SELECT * FROM `{$table}`", ARRAY_A), $tables);
        AccountPreparationFixture::service($this->database, $this->tableNames)->repair($target, $id);
        $owner = AccountPreparationFixture::service($this->database, $this->tableNames, $target->value());
        $owner->saveName($id, str_repeat("é", 191));
        self::assertFalse($owner->myStatus()["needs_name"]);
        self::assertSame($id->value(), $owner->myStatus()["library_id"]);
        $after = array_map(fn (string $table): array => $this->database->get_results("SELECT * FROM `{$table}`", ARRAY_A), $tables);
        self::assertSame($before, $after);
    }

    private function denied(callable $operation): void
    {
        try { $operation(); self::fail("Unauthorized operation was accepted."); }
        catch (AuthorizationException) { self::assertTrue(true); }
    }

    private function ownedLibrary(UserId $target, string $id, string $name): LibraryId
    {
        $library = Library::privateLibrary(new LibraryId($id), new LibraryName($name));
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add($library);
        (new WpdbLibraryMembershipRepository($this->database, $this->tableNames))->add(new LibraryMembershipAssignment($library->id(), $target, LibraryMembership::owner()));
        return $library->id();
    }
}
