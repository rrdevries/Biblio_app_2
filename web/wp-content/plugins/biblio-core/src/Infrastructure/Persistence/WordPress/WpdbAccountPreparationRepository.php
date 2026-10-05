<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Accounts\{AccountPreparation,AccountPreparationRepository};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\{LibraryId,LibraryName};
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use wpdb;

final readonly class WpdbAccountPreparationRepository implements AccountPreparationRepository
{
    public function __construct(private wpdb $database, private CoreTableNames $tables) {}

    public function find(UserId $userId): ?AccountPreparation
    {
        $table = $this->tables->accountPreparations();
        $row = $this->database->get_row($this->database->prepare(
            "SELECT user_id,library_id,naming_complete,notification_sent FROM `{$table}` WHERE user_id=%s",
            $userId->value()
        ));
        if ($this->database->last_error !== "") { $this->fail("Read account preparation"); }
        return $row === null ? null : new AccountPreparation(
            $userId, $row->library_id === null ? null : new LibraryId((string) $row->library_id),
            (bool) $row->naming_complete, (bool) $row->notification_sent
        );
    }

    public function save(AccountPreparation $state): void
    {
        $table = $this->tables->accountPreparations();
        $data = ["library_id" => $state->libraryId?->value(), "naming_complete" => (int) $state->named, "notification_sent" => (int) $state->notified];
        if ($this->find($state->userId) === null) {
            $result = $this->database->insert($table, ["user_id" => $state->userId->value()] + $data, ["%s", "%s", "%d", "%d"]);
        } else {
            $result = $this->database->update($table, $data, ["user_id" => $state->userId->value()], ["%s", "%d", "%d"], ["%s"]);
        }
        if ($result === false) { $this->fail("Save account preparation"); }
    }

    public function exclusively(UserId $userId, callable $operation): mixed
    {
        // Connection-scoped lock spans the separate Core transactions without nesting them.
        $name = "biblio-account-" . substr(hash("sha256", $this->tables->accountPreparations() . ":" . $userId->value()), 0, 40);
        if ((string) $this->database->get_var($this->database->prepare("SELECT GET_LOCK(%s,10)", $name)) !== "1") {
            $this->fail("Lock account preparation");
        }
        try { return $operation(); }
        finally { $this->database->get_var($this->database->prepare("SELECT RELEASE_LOCK(%s)", $name)); }
    }

    public function ownedLibraries(UserId $userId): array
    {
        $table = $this->tables->memberships();
        $ids = $this->database->get_col($this->database->prepare(
            "SELECT library_id FROM `{$table}` WHERE user_id=%s AND management_role='owner' ORDER BY library_id", $userId->value()
        ));
        if ($this->database->last_error !== "") { $this->fail("Read existing ownership"); }
        return array_map(static fn (string $id): LibraryId => new LibraryId($id), $ids);
    }

    public function lockContext(UserId $userId, LibraryId $libraryId): void
    {
        $library = $this->tables->libraries();
        $memberships = $this->tables->memberships();
        $this->database->get_var($this->database->prepare("SELECT library_id FROM `{$library}` WHERE library_id=%s FOR UPDATE", $libraryId->value()));
        if ($this->database->last_error !== "") { $this->fail("Lock Library"); }
        $this->database->get_var($this->database->prepare("SELECT user_id FROM `{$memberships}` WHERE library_id=%s AND user_id=%s FOR UPDATE", $libraryId->value(), $userId->value()));
        if ($this->database->last_error !== "") { $this->fail("Lock membership"); }
    }

    public function rename(LibraryId $libraryId, LibraryName $name): void
    {
        if ($this->database->update($this->tables->libraries(), ["library_name" => $name->value()], ["library_id" => $libraryId->value()], ["%s"], ["%s"]) === false) {
            $this->fail("Rename Library");
        }
    }

    private function fail(string $operation): never
    {
        throw new PersistenceException($operation . " failed.");
    }
}
