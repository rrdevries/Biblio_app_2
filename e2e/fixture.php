<?php

use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitialization;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Catalog\Classification\ClassificationSeedKey;
use Biblio\Core\Catalog\Classification\LibraryCatalogSelection;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbClassificationSeedEvolutionFactory;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryBookTypeRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPersonalLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\LibraryName;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use Biblio\Core\Notes\PrivateNoteId;
use Biblio\Core\Notes\PrivateNoteVersion;
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundVersion;

defined("WP_CLI") || exit(1);

const BIBLIO_E2E_HOST = "biblio-v2.ddev.site";
const BIBLIO_E2E_PROJECT = "biblio-v2";
const BIBLIO_E2E_MARKER_KEY = "biblio_e2e_fixture";
const BIBLIO_E2E_MARKER_VALUE = "vertical-slice-1a-step-11";
const BIBLIO_E2E_ACTOR_LIBRARY = "e2e-library-actor";
const BIBLIO_E2E_OTHER_LIBRARY = "e2e-library-other";
const BIBLIO_E2E_PRIMARY_ITEM = "e2e-item-primary";
const BIBLIO_E2E_MISSING_ITEM = "e2e-item-missing-metadata";
const BIBLIO_E2E_CONFLICT_ITEM = "e2e-item-active-conflict";
const BIBLIO_E2E_FOREIGN_ITEM = "e2e-item-foreign";
const BIBLIO_E2E_END_COMPLETED_ITEM = "e2e-item-end-completed";
const BIBLIO_E2E_END_STOPPED_ITEM = "e2e-item-end-stopped";
const BIBLIO_E2E_END_STALE_ITEM = "e2e-item-end-stale";
const BIBLIO_E2E_END_NONCE_ITEM = "e2e-item-end-nonce";
const BIBLIO_E2E_END_IDEMPOTENT_ITEM = "e2e-item-end-idempotent";
const BIBLIO_E2E_END_LIFECYCLE_ITEM = "e2e-item-end-lifecycle";
const BIBLIO_E2E_HISTORY_ITEM = "e2e-item-history";
const BIBLIO_E2E_HISTORY_SAME_EDITION_ITEM = "e2e-item-history-same-edition";
const BIBLIO_E2E_HISTORY_OTHER_EDITION_ITEM = "e2e-item-history-other-edition";
const BIBLIO_E2E_HISTORY_ZERO_ITEM = "e2e-item-history-zero";
const BIBLIO_E2E_HISTORY_ACTIVE_ITEM = "e2e-item-history-active-only";
const BIBLIO_E2E_HISTORY_END_ITEM = "e2e-item-history-end";
const BIBLIO_E2E_HISTORY_REFRESH_ITEM = "e2e-item-history-refresh";
const BIBLIO_E2E_HISTORY_RAPID_ITEM = "e2e-item-history-rapid";
const BIBLIO_E2E_HISTORY_EXTERNAL_LOAN = "e2e-external-loan-history";
const BIBLIO_E2E_NOTE_EDIT = "e2e-private-note-edit";
const BIBLIO_E2E_NOTE_DELETE = "e2e-private-note-delete";
const BIBLIO_E2E_NOTE_STALE_UPDATE = "e2e-private-note-stale-update";
const BIBLIO_E2E_NOTE_STALE_DELETE = "e2e-private-note-stale-delete";
const BIBLIO_E2E_NOTE_UNAVAILABLE = "e2e-private-note-unavailable";
const BIBLIO_E2E_NOTE_REFRESH = "e2e-private-note-refresh";
const BIBLIO_E2E_NOTE_REFLOW = "e2e-private-note-reflow";
const BIBLIO_E2E_NOTE_FOREIGN = "e2e-private-note-foreign";

/** @return never */
function biblioE2eFail(string $message): void
{
    fwrite(STDERR, "Biblio E2E fixture refused: {$message}\n");
    exit(1);
}

function biblioE2eGuard(): void
{
    if (getenv("BIBLIO_E2E_ALLOW_FIXTURES") !== "1") {
        biblioE2eFail("explicit fixture opt-in is missing.");
    }

    if (wp_get_environment_type() !== "local") {
        biblioE2eFail("WordPress environment must be exactly local.");
    }

    if (
        getenv("IS_DDEV_PROJECT") !== "true"
        || getenv("DDEV_PROJECT") !== BIBLIO_E2E_PROJECT
    ) {
        biblioE2eFail("runtime is not the exact DDEV project.");
    }

    $home = wp_parse_url(home_url("/"));
    $site = wp_parse_url(site_url("/"));
    $primary = wp_parse_url((string) getenv("DDEV_PRIMARY_URL"));

    foreach ([$home, $site, $primary] as $url) {
        if (
            !is_array($url)
            || ($url["scheme"] ?? null) !== "https"
            || ($url["host"] ?? null) !== BIBLIO_E2E_HOST
        ) {
            biblioE2eFail("runtime URL is not the exact local DDEV host.");
        }
    }
}

/** @return array<string, string> */
function biblioE2eIds(): array
{
    return [
        "actor_library" => BIBLIO_E2E_ACTOR_LIBRARY,
        "other_library" => BIBLIO_E2E_OTHER_LIBRARY,
        "primary_work" => "e2e-work-primary",
        "missing_work" => "e2e-work-missing-metadata",
        "conflict_work" => "e2e-work-active-conflict",
        "foreign_work" => "e2e-work-foreign",
        "end_completed_work" => "e2e-work-end-completed",
        "end_stopped_work" => "e2e-work-end-stopped",
        "end_stale_work" => "e2e-work-end-stale",
        "end_nonce_work" => "e2e-work-end-nonce",
        "end_idempotent_work" => "e2e-work-end-idempotent",
        "end_lifecycle_work" => "e2e-work-end-lifecycle",
        "history_work" => "e2e-work-history",
        "history_zero_work" => "e2e-work-history-zero",
        "history_active_work" => "e2e-work-history-active-only",
        "history_end_work" => "e2e-work-history-end",
        "history_refresh_work" => "e2e-work-history-refresh",
        "history_rapid_work" => "e2e-work-history-rapid",
        "primary_edition" => "e2e-edition-primary",
        "missing_edition" => "e2e-edition-missing-metadata",
        "conflict_edition" => "e2e-edition-active-conflict",
        "foreign_edition" => "e2e-edition-foreign",
        "end_completed_edition" => "e2e-edition-end-completed",
        "end_stopped_edition" => "e2e-edition-end-stopped",
        "end_stale_edition" => "e2e-edition-end-stale",
        "end_nonce_edition" => "e2e-edition-end-nonce",
        "end_idempotent_edition" => "e2e-edition-end-idempotent",
        "end_lifecycle_edition" => "e2e-edition-end-lifecycle",
        "history_edition" => "e2e-edition-history",
        "history_other_edition" => "e2e-edition-history-other",
        "history_zero_edition" => "e2e-edition-history-zero",
        "history_active_edition" => "e2e-edition-history-active-only",
        "history_end_edition" => "e2e-edition-history-end",
        "history_refresh_edition" => "e2e-edition-history-refresh",
        "history_rapid_edition" => "e2e-edition-history-rapid",
        "primary_item" => BIBLIO_E2E_PRIMARY_ITEM,
        "missing_item" => BIBLIO_E2E_MISSING_ITEM,
        "conflict_item" => BIBLIO_E2E_CONFLICT_ITEM,
        "foreign_item" => BIBLIO_E2E_FOREIGN_ITEM,
        "end_completed_item" => BIBLIO_E2E_END_COMPLETED_ITEM,
        "end_stopped_item" => BIBLIO_E2E_END_STOPPED_ITEM,
        "end_stale_item" => BIBLIO_E2E_END_STALE_ITEM,
        "end_nonce_item" => BIBLIO_E2E_END_NONCE_ITEM,
        "end_idempotent_item" => BIBLIO_E2E_END_IDEMPOTENT_ITEM,
        "end_lifecycle_item" => BIBLIO_E2E_END_LIFECYCLE_ITEM,
        "history_item" => BIBLIO_E2E_HISTORY_ITEM,
        "history_same_edition_item" => BIBLIO_E2E_HISTORY_SAME_EDITION_ITEM,
        "history_other_edition_item" => BIBLIO_E2E_HISTORY_OTHER_EDITION_ITEM,
        "history_zero_item" => BIBLIO_E2E_HISTORY_ZERO_ITEM,
        "history_active_item" => BIBLIO_E2E_HISTORY_ACTIVE_ITEM,
        "history_end_item" => BIBLIO_E2E_HISTORY_END_ITEM,
        "history_refresh_item" => BIBLIO_E2E_HISTORY_REFRESH_ITEM,
        "history_rapid_item" => BIBLIO_E2E_HISTORY_RAPID_ITEM,
        "history_external_loan" => BIBLIO_E2E_HISTORY_EXTERNAL_LOAN,
    ];
}

/** @return list<string> */
function biblioE2eWorks(): array
{
    $ids = biblioE2eIds();

    return array_values(array_filter(
        $ids,
        static fn (string $value, string $key): bool => str_ends_with($key, "_work"),
        ARRAY_FILTER_USE_BOTH
    ));
}

/** @return list<string> */
function biblioE2eEditions(): array
{
    $ids = biblioE2eIds();

    return array_values(array_filter(
        $ids,
        static fn (string $value, string $key): bool => str_ends_with($key, "_edition"),
        ARRAY_FILTER_USE_BOTH
    ));
}

/** @return list<string> */
function biblioE2eItems(): array
{
    $ids = biblioE2eIds();

    return array_values(array_filter(
        $ids,
        static fn (string $value, string $key): bool => str_ends_with($key, "_item"),
        ARRAY_FILTER_USE_BOTH
    ));
}

/** @return list<string> */
function biblioE2ePrivateNoteIds(): array
{
    $ids = [
        BIBLIO_E2E_NOTE_EDIT,
        BIBLIO_E2E_NOTE_DELETE,
        BIBLIO_E2E_NOTE_STALE_UPDATE,
        BIBLIO_E2E_NOTE_STALE_DELETE,
        BIBLIO_E2E_NOTE_UNAVAILABLE,
        BIBLIO_E2E_NOTE_REFRESH,
        BIBLIO_E2E_NOTE_REFLOW,
        BIBLIO_E2E_NOTE_FOREIGN,
    ];

    for ($number = 1; $number <= 13; $number++) {
        $ids[] = sprintf("e2e-private-note-page-%02d", $number);
    }

    return $ids;
}

/** @return list<string> */
function biblioE2eUsernames(): array
{
    $actor = (string) getenv("BIBLIO_E2E_ACTOR_USERNAME");
    $other = (string) getenv("BIBLIO_E2E_OTHER_USERNAME");

    if ($actor !== "biblio_e2e_actor" || $other !== "biblio_e2e_other") {
        biblioE2eFail("formal fixture usernames must remain exact.");
    }

    return [$actor, $other];
}

/** @param list<string> $values */
function biblioE2eDeleteIn(
    wpdb $database,
    string $table,
    string $column,
    array $values
): void {
    $placeholders = implode(", ", array_fill(0, count($values), "%s"));
    $sql = $database->prepare(
        "DELETE FROM `{$table}` WHERE `{$column}` IN ({$placeholders})",
        ...$values
    );

    if ($database->query($sql) === false) {
        throw new RuntimeException("Exact fixture cleanup failed for {$table}.");
    }
}

function biblioE2eCleanupCore(wpdb $database): void
{
    $tables = new CoreTableNames($database->prefix);
    $ids = biblioE2eIds();
    $libraries = [$ids["actor_library"], $ids["other_library"]];
    $works = biblioE2eWorks();
    $editions = biblioE2eEditions();
    $items = biblioE2eItems();

    if ($database->query("START TRANSACTION") === false) {
        throw new RuntimeException("Could not start exact fixture cleanup.");
    }

    try {
        biblioE2eDeleteIn($database, $tables->contributionPublications(), "library_id", $libraries);
        biblioE2eDeleteIn($database, $tables->libraryActivityEvents(), "library_id", $libraries);
        biblioE2eDeleteIn($database, $tables->nextReadingEntries(), "item_id", $items);
        biblioE2eDeleteIn($database, $tables->readingRounds(), "work_id", $works);
        biblioE2eDeleteIn(
            $database,
            $tables->externalLoans(),
            "external_loan_id",
            [BIBLIO_E2E_HISTORY_EXTERNAL_LOAN]
        );
        biblioE2eDeleteIn($database, $tables->privateNotes(), "work_id", $works);
        biblioE2eDeleteIn($database, $tables->ratings(), "work_id", $works);
        biblioE2eDeleteIn($database, $tables->reviews(), "work_id", $works);
        biblioE2eDeleteIn($database, $tables->libraryCatalogContextGenres(), "library_id", $libraries);
        biblioE2eDeleteIn($database, $tables->libraryCatalogContextSubjects(), "library_id", $libraries);
        biblioE2eDeleteIn($database, $tables->libraryCatalogContexts(), "library_id", $libraries);
        biblioE2eDeleteIn($database, $tables->items(), "item_id", $items);
        biblioE2eDeleteIn($database, $tables->editions(), "edition_id", $editions);
        biblioE2eDeleteIn($database, $tables->works(), "work_id", $works);
        biblioE2eDeleteIn($database, $tables->libraryBookTypes(), "library_id", $libraries);
        biblioE2eDeleteIn($database, $tables->libraryGenres(), "library_id", $libraries);
        biblioE2eDeleteIn($database, $tables->librarySubjects(), "library_id", $libraries);
        biblioE2eDeleteIn($database, $tables->personalLibraryDesignations(), "library_id", $libraries);
        biblioE2eDeleteIn($database, $tables->memberships(), "library_id", $libraries);
        biblioE2eDeleteIn($database, $tables->libraries(), "library_id", $libraries);

        if ($database->query("COMMIT") === false) {
            throw new RuntimeException("Could not commit exact fixture cleanup.");
        }
    } catch (Throwable $exception) {
        $database->query("ROLLBACK");
        throw $exception;
    }
}

function biblioE2eCleanupUsers(): void
{
    require_once ABSPATH . "wp-admin/includes/user.php";

    foreach (biblioE2eUsernames() as $username) {
        $user = get_user_by("login", $username);

        if (!$user instanceof WP_User) {
            continue;
        }

        if (!wp_delete_user($user->ID)) {
            throw new RuntimeException("Could not delete exact fixture user.");
        }
    }
}

function biblioE2eValidateCleanupUsers(): void
{
    foreach (biblioE2eUsernames() as $username) {
        $user = get_user_by("login", $username);

        if (!$user instanceof WP_User) {
            continue;
        }

        if (
            get_user_meta($user->ID, BIBLIO_E2E_MARKER_KEY, true)
            !== BIBLIO_E2E_MARKER_VALUE
        ) {
            biblioE2eFail("refusing to delete an unmarked username collision.");
        }
    }
}

function biblioE2eCleanup(): void
{
    global $wpdb;
    biblioE2eValidateCleanupUsers();
    biblioE2eCleanupCore($wpdb);
    biblioE2eCleanupUsers();
    wp_set_current_user(0);
}

function biblioE2eCreateUser(
    string $username,
    string $password,
    string $email
): int {
    if (strlen($password) < 32) {
        biblioE2eFail("fixture password is missing or too short.");
    }

    if (get_user_by("login", $username) !== false) {
        biblioE2eFail("fixture username already exists after cleanup.");
    }

    $result = wp_insert_user([
        "user_login" => $username,
        "user_pass" => $password,
        "user_email" => $email,
        "display_name" => $username,
        "role" => "subscriber",
    ]);

    if (is_wp_error($result)) {
        throw new RuntimeException("Could not create exact fixture user.");
    }

    add_user_meta(
        (int) $result,
        BIBLIO_E2E_MARKER_KEY,
        BIBLIO_E2E_MARKER_VALUE,
        true
    );
    return (int) $result;
}

function biblioE2eCreateLibrary(
    wpdb $database,
    string $libraryId,
    string $name,
    int $ownerId
): void {
    $tables = new CoreTableNames($database->prefix);
    $service = new CreateLibraryService(
        new WpdbLibraryRepository($database, $tables),
        new WpdbLibraryMembershipRepository($database, $tables),
        WpdbClassificationSeedEvolutionFactory::create($database, $tables),
        new WpdbTransactionManager($database)
    );
    $library = Library::privateLibrary(
        new LibraryId($libraryId),
        new LibraryName($name)
    );
    $service->create($library, new UserId((string) $ownerId));
    (new WpdbPersonalLibraryRepository($database, $tables))->designate(
        new UserId((string) $ownerId),
        $library->id()
    );
}

function biblioE2eAddItem(
    wpdb $database,
    ProductionComposition $composition,
    string $libraryId,
    string $itemId,
    string $workId,
    string $title,
    string $editionId
): void {
    $library = new LibraryId($libraryId);
    $bookType = (new WpdbLibraryBookTypeRepository(
        $database,
        new CoreTableNames($database->prefix)
    ))->findBySeedKey(
        $library,
        new ClassificationSeedKey("book_type.reading_book")
    );

    if ($bookType === null) {
        throw new RuntimeException("Required reading-book seed is missing.");
    }

    $composition->application()->libraryItemCreation()->addWithNewWorkAndEdition(
        $library,
        new ItemId($itemId),
        new WorkId($workId),
        $title,
        new EditionId($editionId),
        new LibraryCatalogContextInitialization(
            new LibraryCatalogSelection($bookType->id())
        )
    );
}

function biblioE2eStartRound(
    wpdb $database,
    string $username,
    string $libraryId,
    string $itemId,
    ReadingDate $startedOn
): void
{
    $user = get_user_by("login", $username);

    if (!$user instanceof WP_User) {
        biblioE2eFail("required fixture user does not exist.");
    }

    wp_set_current_user($user->ID);
    (new ProductionComposition($database))->application()->libraryItemReading()->start(
        new LibraryId($libraryId),
        new ItemId($itemId),
        $startedOn
    );
}

function biblioE2eSeedExternalLoan(
    wpdb $database,
    string $userId,
    string $workId
): void {
    $tables = new CoreTableNames($database->prefix);
    $inserted = $database->insert($tables->externalLoans(), [
        "external_loan_id" => BIBLIO_E2E_HISTORY_EXTERNAL_LOAN,
        "user_id" => $userId,
        "work_id" => $workId,
        "loan_status" => "active",
        "borrowed_at" => "2025-01-02 10:00:00.000000",
        "due_at" => null,
    ]);

    if ($inserted !== 1) {
        throw new RuntimeException(
            "Could not create exact history ExternalLoan fixture."
        );
    }
}

function biblioE2eSeedRound(
    wpdb $database,
    string $roundId,
    string $userId,
    string $workId,
    ?string $itemId,
    ?string $externalLoanId,
    ?string $outcome,
    string $provenance,
    ?ReadingDate $startedOn,
    ?ReadingDate $finishedOn,
    ?string $legacyStartedAt = null
): void {
    $legacy = $provenance === "legacy_source_started";
    $inserted = $database->insert(
        (new CoreTableNames($database->prefix))->readingRounds(),
        [
            "reading_round_id" => $roundId,
            "user_id" => $userId,
            "work_id" => $workId,
            "item_id" => $itemId,
            "external_loan_id" => $externalLoanId,
            "started_at" => $legacyStartedAt,
            "round_outcome" => $outcome,
            "provenance" => $provenance,
            "reading_started_year" => $startedOn?->yearValue(),
            "reading_started_month" => $startedOn?->monthValue(),
            "reading_started_day" => $startedOn?->dayValue(),
            "reading_finished_year" => $finishedOn?->yearValue(),
            "reading_finished_month" => $finishedOn?->monthValue(),
            "reading_finished_day" => $finishedOn?->dayValue(),
            "created_at" => $legacy ? null : "2025-01-02 10:00:00.000000",
            "updated_at" => $legacy ? null : "2025-01-03 10:00:00.000000",
            "ended_at" => $outcome === null
                ? null
                : "2025-01-03 10:00:00.000000",
            "round_version" => 1,
        ]
    );

    if ($inserted !== 1) {
        throw new RuntimeException(
            "Could not create exact Reading History round {$roundId}."
        );
    }
}

function biblioE2eSeedHistoryRounds(
    wpdb $database,
    string $actorId,
    string $otherId
): void {
    $workId = "e2e-work-history";
    biblioE2eSeedExternalLoan($database, $actorId, $workId);

    $exactRounds = [
        ["13", "completed", BIBLIO_E2E_HISTORY_ITEM, null, ReadingDate::exact(2025, 3, 12), ReadingDate::exact(2025, 12, 13)],
        ["12", "stopped", BIBLIO_E2E_HISTORY_ITEM, null, ReadingDate::exact(2025, 12, 1), ReadingDate::exact(2025, 12, 12)],
        ["11", "completed", BIBLIO_E2E_HISTORY_SAME_EDITION_ITEM, null, ReadingDate::exact(2025, 11, 1), ReadingDate::exact(2025, 12, 11)],
        ["10", "completed", BIBLIO_E2E_HISTORY_OTHER_EDITION_ITEM, null, ReadingDate::exact(2025, 10, 1), ReadingDate::exact(2025, 12, 10)],
        ["09", "completed", null, BIBLIO_E2E_HISTORY_EXTERNAL_LOAN, ReadingDate::exact(2025, 9, 1), ReadingDate::exact(2025, 12, 9)],
        ["08", "completed", BIBLIO_E2E_HISTORY_ITEM, null, ReadingDate::exact(2025, 8, 1), ReadingDate::exact(2025, 12, 8)],
        ["07", "stopped", BIBLIO_E2E_HISTORY_ITEM, null, ReadingDate::exact(2025, 7, 1), ReadingDate::exact(2025, 12, 7)],
        ["06", "completed", BIBLIO_E2E_HISTORY_ITEM, null, ReadingDate::exact(2025, 6, 1), ReadingDate::exact(2025, 12, 6)],
        ["05", "completed", BIBLIO_E2E_HISTORY_ITEM, null, ReadingDate::exact(2025, 5, 1), ReadingDate::exact(2025, 12, 5)],
        ["04", "completed", BIBLIO_E2E_HISTORY_ITEM, null, ReadingDate::exact(2025, 4, 1), ReadingDate::exact(2025, 12, 4)],
    ];

    foreach ($exactRounds as [$suffix, $outcome, $itemId, $loanId, $start, $finish]) {
        biblioE2eSeedRound(
            $database,
            "e2e-reading-round-history-{$suffix}",
            $actorId,
            $workId,
            $itemId,
            $loanId,
            $outcome,
            "source_started",
            $start,
            $finish
        );
    }

    biblioE2eSeedRound(
        $database,
        "e2e-reading-round-history-month",
        $actorId,
        $workId,
        null,
        null,
        "completed",
        "historical_manual",
        ReadingDate::month(2025, 3),
        ReadingDate::month(2025, 3)
    );
    biblioE2eSeedRound(
        $database,
        "e2e-reading-round-history-legacy",
        $actorId,
        $workId,
        BIBLIO_E2E_HISTORY_ITEM,
        null,
        "completed",
        "legacy_source_started",
        null,
        ReadingDate::exact(2025, 2, 2),
        "2024-12-31 23:30:00.000000"
    );
    biblioE2eSeedRound(
        $database,
        "e2e-reading-round-history-year",
        $actorId,
        $workId,
        null,
        null,
        "completed",
        "historical_manual",
        null,
        ReadingDate::year(2024)
    );
    biblioE2eSeedRound(
        $database,
        "e2e-reading-round-history-foreign",
        $otherId,
        $workId,
        null,
        null,
        "completed",
        "historical_manual",
        null,
        ReadingDate::exact(2026, 1, 1)
    );
    biblioE2eSeedRound(
        $database,
        "e2e-reading-round-history-end-old",
        $actorId,
        "e2e-work-history-end",
        null,
        null,
        "completed",
        "historical_manual",
        null,
        ReadingDate::year(2023)
    );
    biblioE2eSeedRound(
        $database,
        "e2e-reading-round-history-refresh-old",
        $actorId,
        "e2e-work-history-refresh",
        null,
        null,
        "stopped",
        "historical_manual",
        null,
        ReadingDate::year(2022)
    );
    biblioE2eSeedRound(
        $database,
        "e2e-reading-round-history-rapid",
        $actorId,
        "e2e-work-history-rapid",
        BIBLIO_E2E_HISTORY_RAPID_ITEM,
        null,
        "stopped",
        "source_started",
        ReadingDate::exact(2023, 1, 1),
        ReadingDate::exact(2023, 1, 2)
    );
}

function biblioE2eActivateConflict(wpdb $database): void
{
    [$actor] = biblioE2eUsernames();
    biblioE2eStartRound(
        $database,
        $actor,
        BIBLIO_E2E_ACTOR_LIBRARY,
        BIBLIO_E2E_CONFLICT_ITEM,
        ReadingDate::exact(2026, 8, 1)
    );
}

function biblioE2eResetConflict(wpdb $database): void
{
    $tables = new CoreTableNames($database->prefix);
    biblioE2eDeleteIn(
        $database,
        $tables->readingRounds(),
        "item_id",
        [BIBLIO_E2E_CONFLICT_ITEM]
    );
}

/** @return array<string, int> */
function biblioE2eCounts(wpdb $database): array
{
    $tables = new CoreTableNames($database->prefix);
    $ids = biblioE2eIds();
    $libraries = [$ids["actor_library"], $ids["other_library"]];
    $items = biblioE2eItems();
    $librarySql = implode(",", array_fill(0, count($libraries), "%s"));
    $itemSql = implode(",", array_fill(0, count($items), "%s"));
    $workValues = biblioE2eWorks();
    $editionValues = biblioE2eEditions();
    $workSql = implode(",", array_fill(0, count($workValues), "%s"));
    $editionSql = implode(",", array_fill(0, count($editionValues), "%s"));

    return [
        "libraries" => (int) $database->get_var($database->prepare(
            "SELECT COUNT(*) FROM `{$tables->libraries()}` WHERE library_id IN ({$librarySql})",
            ...$libraries
        )),
        "items" => (int) $database->get_var($database->prepare(
            "SELECT COUNT(*) FROM `{$tables->items()}` WHERE item_id IN ({$itemSql})",
            ...$items
        )),
        "works" => (int) $database->get_var($database->prepare(
            "SELECT COUNT(*) FROM `{$tables->works()}` WHERE work_id IN ({$workSql})",
            ...$workValues
        )),
        "editions" => (int) $database->get_var($database->prepare(
            "SELECT COUNT(*) FROM `{$tables->editions()}` WHERE edition_id IN ({$editionSql})",
            ...$editionValues
        )),
        "external_loans" => (int) $database->get_var($database->prepare(
            "SELECT COUNT(*) FROM `{$tables->externalLoans()}` WHERE external_loan_id = %s",
            BIBLIO_E2E_HISTORY_EXTERNAL_LOAN
        )),
        "rounds" => (int) $database->get_var($database->prepare(
            "SELECT COUNT(*) FROM `{$tables->readingRounds()}` WHERE work_id IN ({$workSql})",
            ...$workValues
        )),
        "private_notes" => (int) $database->get_var($database->prepare(
            "SELECT COUNT(*) FROM `{$tables->privateNotes()}` WHERE work_id IN ({$workSql})",
            ...$workValues
        )),
        "memberships" => (int) $database->get_var($database->prepare(
            "SELECT COUNT(*) FROM `{$tables->memberships()}` WHERE library_id IN ({$librarySql})",
            ...$libraries
        )),
        "designations" => (int) $database->get_var($database->prepare(
            "SELECT COUNT(*) FROM `{$tables->personalLibraryDesignations()}` WHERE library_id IN ({$librarySql})",
            ...$libraries
        )),
        "catalog_contexts" => (int) $database->get_var($database->prepare(
            "SELECT COUNT(*) FROM `{$tables->libraryCatalogContexts()}` WHERE library_id IN ({$librarySql})",
            ...$libraries
        )),
        "classification_terms" => array_sum(array_map(
            static fn (string $table): int => (int) $database->get_var(
                $database->prepare(
                    "SELECT COUNT(*) FROM `{$table}` WHERE library_id IN ({$librarySql})",
                    ...$libraries
                )
            ),
            [
                $tables->libraryBookTypes(),
                $tables->libraryGenres(),
                $tables->librarySubjects(),
            ]
        )),
        "activity_events" => (int) $database->get_var($database->prepare(
            "SELECT COUNT(*) FROM `{$tables->libraryActivityEvents()}` WHERE library_id IN ({$librarySql})",
            ...$libraries
        )),
        "users" => count(array_filter(
            biblioE2eUsernames(),
            static fn (string $username): bool =>
                get_user_by("login", $username) instanceof WP_User
        )),
    ];
}

/** @return array<string, array{work_id: string, owner: string, version: int}> */
function biblioE2ePrivateNoteState(wpdb $database): array
{
    $noteIds = biblioE2ePrivateNoteIds();
    $noteSql = implode(",", array_fill(0, count($noteIds), "%s"));
    $table = (new CoreTableNames($database->prefix))->privateNotes();
    $rows = $database->get_results($database->prepare(
        "SELECT private_note_id, user_id, work_id, note_version "
        . "FROM `{$table}` WHERE private_note_id IN ({$noteSql}) "
        . "ORDER BY private_note_id",
        ...$noteIds
    ), ARRAY_A);
    [$actorName, $otherName] = biblioE2eUsernames();
    $actor = get_user_by("login", $actorName);
    $other = get_user_by("login", $otherName);
    $state = [];

    foreach ($rows as $row) {
        $owner = "unexpected";

        if ($actor instanceof WP_User && (string) $row["user_id"] === (string) $actor->ID) {
            $owner = "actor";
        } elseif ($other instanceof WP_User && (string) $row["user_id"] === (string) $other->ID) {
            $owner = "other";
        }

        $state[(string) $row["private_note_id"]] = [
            "work_id" => (string) $row["work_id"],
            "owner" => $owner,
            "version" => (int) $row["note_version"],
        ];
    }

    return $state;
}

function biblioE2eSeedPrivateNote(
    wpdb $database,
    string $privateNoteId,
    string $userId,
    string $workId,
    string $content,
    string $instant
): void {
    if (!in_array($privateNoteId, biblioE2ePrivateNoteIds(), true)) {
        biblioE2eFail("Private Note seed ID is outside the exact allowlist.");
    }

    $inserted = $database->insert(
        (new CoreTableNames($database->prefix))->privateNotes(),
        [
            "private_note_id" => $privateNoteId,
            "user_id" => $userId,
            "work_id" => $workId,
            "reading_round_id" => null,
            "note_content" => $content,
            "created_at" => $instant,
            "updated_at" => $instant,
            "note_version" => 1,
        ],
        ["%s", "%s", "%s", "%s", "%s", "%s", "%s", "%d"]
    );

    if ($inserted !== 1) {
        throw new RuntimeException("Could not create exact Private Note fixture.");
    }
}

function biblioE2eSeedPrivateNotes(
    wpdb $database,
    string $actorId,
    string $otherId
): void {
    $rich = "<p>E2E bewerkbare notitie met <strong>vet</strong> en "
        . "<em>cursief</em>.</p><ul><li>E2E lijstpunt</li></ul>"
        . "<blockquote>E2E citaat</blockquote>";
    biblioE2eSeedPrivateNote($database, BIBLIO_E2E_NOTE_EDIT, $actorId, "e2e-work-missing-metadata", $rich, "2026-08-30 10:01:00.000000");
    biblioE2eSeedPrivateNote($database, BIBLIO_E2E_NOTE_DELETE, $actorId, "e2e-work-active-conflict", "<p>E2E notitie voor verwijderen.</p>", "2026-08-30 10:02:00.000000");
    biblioE2eSeedPrivateNote($database, BIBLIO_E2E_NOTE_STALE_UPDATE, $actorId, "e2e-work-end-completed", "<p>E2E notitie voor stale update.</p>", "2026-08-30 10:03:00.000000");
    biblioE2eSeedPrivateNote($database, BIBLIO_E2E_NOTE_STALE_DELETE, $actorId, "e2e-work-end-stopped", "<p>E2E notitie voor stale delete.</p>", "2026-08-30 10:04:00.000000");
    biblioE2eSeedPrivateNote($database, BIBLIO_E2E_NOTE_UNAVAILABLE, $actorId, "e2e-work-end-stale", "<p>E2E notitie die extern verdwijnt.</p>", "2026-08-30 10:05:00.000000");
    biblioE2eSeedPrivateNote($database, BIBLIO_E2E_NOTE_REFRESH, $actorId, "e2e-work-end-nonce", "<p>E2E notitie voor refresh failure.</p>", "2026-08-30 10:06:00.000000");
    biblioE2eSeedPrivateNote(
        $database,
        BIBLIO_E2E_NOTE_REFLOW,
        $actorId,
        "e2e-work-end-idempotent",
        "<p>E2E reflow met <strong>vet</strong>, <em>cursief</em> en een zeerlangonafgebrokene2etestwoorddatveiligmoetafbrekenzonderhorizontaleoverflow.</p><ol><li>E2E eerste punt</li><li>E2E tweede punt</li></ol><blockquote>E2E responsive citaat.</blockquote>",
        "2026-08-30 10:07:00.000000"
    );

    for ($number = 1; $number <= 13; $number++) {
        $suffix = sprintf("%02d", $number);
        $content = $number === 13
            ? "<p>E2E paginanotitie 13 met <strong>opmaak</strong> en een zeerlangonafgebrokenpaginatokenvoorreflowacceptance.</p>"
            : "<p>E2E paginanotitie {$suffix}.</p>";
        biblioE2eSeedPrivateNote(
            $database,
            "e2e-private-note-page-{$suffix}",
            $actorId,
            "e2e-work-history",
            $content,
            sprintf("2026-08-31 %02d:00:00.000000", $number)
        );
    }

    biblioE2eSeedPrivateNote(
        $database,
        BIBLIO_E2E_NOTE_FOREIGN,
        $otherId,
        "e2e-work-history",
        "<p>E2E FOREIGN PRIVATE NOTE MUST NEVER LEAK.</p>",
        "2026-08-31 23:00:00.000000"
    );
}

function biblioE2eActorApplication(wpdb $database): \Biblio\Core\Application\CoreApplication
{
    [$actorName] = biblioE2eUsernames();
    $actor = get_user_by("login", $actorName);

    if (!$actor instanceof WP_User) {
        biblioE2eFail("actor fixture user does not exist.");
    }

    wp_set_current_user($actor->ID);
    return (new ProductionComposition($database))->application();
}

function biblioE2eAdvancePrivateNote(
    wpdb $database,
    string $privateNoteId,
    string $content
): void {
    $application = biblioE2eActorApplication($database);
    $id = new PrivateNoteId($privateNoteId);
    $note = $application->privateNotes()->get($id);

    if ($note === null) {
        throw new RuntimeException("Exact Private Note fixture is missing.");
    }

    if ($note->version()->value() === 2 && $note->content()->value() === $content) {
        return;
    }

    if ($note->version()->value() !== 1) {
        throw new RuntimeException("Exact Private Note fixture has drifted.");
    }

    $application->privateNoteContentUpdate()->update(
        $id,
        PrivateNoteVersion::initial(),
        $content
    );
}

function biblioE2eDeleteUnavailablePrivateNote(wpdb $database): void
{
    $application = biblioE2eActorApplication($database);
    $id = new PrivateNoteId(BIBLIO_E2E_NOTE_UNAVAILABLE);
    $note = $application->privateNotes()->get($id);

    if ($note === null) {
        return;
    }

    if ($note->version()->value() !== 1) {
        throw new RuntimeException("Exact unavailable Private Note fixture has drifted.");
    }

    $application->privateNoteDeletion()->delete($id, PrivateNoteVersion::initial());
}

/** @return array<string, mixed> */
function biblioE2eRoundState(wpdb $database): array
{
    $tables = new CoreTableNames($database->prefix);
    $ids = biblioE2eIds();
    $items = [
        $ids["end_completed_item"],
        $ids["end_stopped_item"],
        $ids["end_stale_item"],
        $ids["end_nonce_item"],
        $ids["end_idempotent_item"],
        $ids["end_lifecycle_item"],
        $ids["foreign_item"],
        $ids["history_end_item"],
        $ids["history_refresh_item"],
    ];
    $itemSql = implode(",", array_fill(0, count($items), "%s"));
    $rows = $database->get_results($database->prepare(
        "SELECT reading_round_id, item_id, round_outcome, round_version, "
        . "reading_finished_year, reading_finished_month, reading_finished_day "
        . "FROM `{$tables->readingRounds()}` WHERE item_id IN ({$itemSql}) "
        . "ORDER BY item_id, reading_round_id",
        ...$items
    ), ARRAY_A);

    $rounds = [];
    foreach ($rows as $row) {
        $outcome = $row["round_outcome"] === null
            ? null
            : (string) $row["round_outcome"];
        $itemId = (string) $row["item_id"];
        $previous = $rounds[$itemId] ?? null;
        $rounds[$itemId] = [
            "reading_round_id" => (string) $row["reading_round_id"],
            "lifecycle" => $outcome === null ? "active" : "ended",
            "outcome" => $outcome,
            "version" => (int) $row["round_version"],
            "row_count" => is_array($previous)
                ? (int) $previous["row_count"] + 1
                : 1,
            "active_rounds" => (is_array($previous)
                ? (int) $previous["active_rounds"]
                : 0) + ($outcome === null ? 1 : 0),
            "finished_on" => $outcome === null ? null : [
                "year" => (int) $row["reading_finished_year"],
                "month" => (int) $row["reading_finished_month"],
                "day" => (int) $row["reading_finished_day"],
            ],
        ];
    }

    [$actorName] = biblioE2eUsernames();
    $actor = get_user_by("login", $actorName);
    $membership = $actor instanceof WP_User
        ? (new WpdbLibraryMembershipRepository(
            $database,
            $tables
        ))->findFor(
            new LibraryId(BIBLIO_E2E_OTHER_LIBRARY),
            new UserId((string) $actor->ID)
        )
        : null;

    return [
        "rounds" => $rounds,
        "actor_manages_other_library" => $membership !== null
            && $membership->membership()->managementRole() === ManagementRole::Manager
            && $membership->membership()->useAccess() === UseAccess::Direct
            && $membership->membership()->status() === MembershipStatus::Active,
    ];
}

/** @return array<string, int|string|bool> */
function biblioE2eFingerprint(wpdb $database): array
{
    $payload = [];
    $rowCount = 0;

    foreach ((new CoreTableNames($database->prefix))->schema1006() as $table) {
        $rows = $database->get_results("SELECT * FROM `{$table}`", ARRAY_A);
        $serialized = [];

        foreach ($rows as $row) {
            ksort($row);
            $serialized[] = wp_json_encode(
                $row,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }

        sort($serialized, SORT_STRING);
        $payload[$table] = $serialized;
        $rowCount += count($serialized);
    }

    ksort($payload);
    [$actor, $other] = biblioE2eUsernames();
    $users = $database->get_results($database->prepare(
        "SELECT ID, user_login FROM `{$database->users}` "
        . "WHERE user_login NOT IN (%s, %s) ORDER BY ID",
        $actor,
        $other
    ), ARRAY_A);

    return [
        "core_rows" => $rowCount,
        "core_sha256" => hash("sha256", wp_json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        )),
        "non_e2e_users" => count($users),
        "non_e2e_users_sha256" => hash("sha256", wp_json_encode(
            $users,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        )),
        "biblio_dev_present" => get_user_by("login", "biblio_dev") instanceof WP_User,
    ];
}

function biblioE2eAdvanceStaleRound(wpdb $database): void
{
    [$actorName] = biblioE2eUsernames();
    $actor = get_user_by("login", $actorName);

    if (!$actor instanceof WP_User) {
        biblioE2eFail("actor fixture user does not exist.");
    }

    wp_set_current_user($actor->ID);
    $application = (new ProductionComposition($database))->application();
    $state = biblioE2eRoundState($database);
    $row = $state["rounds"][BIBLIO_E2E_END_STALE_ITEM] ?? null;

    if (!is_array($row)) {
        throw new RuntimeException("Exact stale fixture ReadingRound is missing.");
    }

    if (
        ($row["lifecycle"] ?? null) === "ended"
        && ($row["outcome"] ?? null) === "completed"
        && ($row["version"] ?? null) === 2
    ) {
        return;
    }

    if (($row["lifecycle"] ?? null) !== "active" || ($row["version"] ?? null) !== 1) {
        throw new RuntimeException("Exact stale fixture ReadingRound has drifted.");
    }

    $application->finishReadingRound()->finish(
        new ReadingRoundId((string) $row["reading_round_id"]),
        new ReadingRoundVersion(1),
        ReadingDate::exact(2026, 8, 18)
    );
}

function biblioE2eSetup(wpdb $database): void
{
    biblioE2eCleanup();
    [$actorName, $otherName] = biblioE2eUsernames();
    $actor = biblioE2eCreateUser(
        $actorName,
        (string) getenv("BIBLIO_E2E_ACTOR_PASSWORD"),
        "biblio-e2e-actor@biblio-v2.invalid"
    );
    $other = biblioE2eCreateUser(
        $otherName,
        (string) getenv("BIBLIO_E2E_OTHER_PASSWORD"),
        "biblio-e2e-other@biblio-v2.invalid"
    );

    biblioE2eCreateLibrary(
        $database,
        BIBLIO_E2E_ACTOR_LIBRARY,
        "E2E Privébibliotheek",
        $actor
    );
    biblioE2eCreateLibrary(
        $database,
        BIBLIO_E2E_OTHER_LIBRARY,
        "E2E Andere bibliotheek",
        $other
    );

    (new WpdbLibraryMembershipRepository(
        $database,
        new CoreTableNames($database->prefix)
    ))->add(new LibraryMembershipAssignment(
        new LibraryId(BIBLIO_E2E_OTHER_LIBRARY),
        new UserId((string) $actor),
        new LibraryMembership(
            ManagementRole::Manager,
            UseAccess::Direct,
            MembershipStatus::Active
        )
    ));

    $composition = new ProductionComposition($database);
    wp_set_current_user($actor);
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_ACTOR_LIBRARY, BIBLIO_E2E_PRIMARY_ITEM, "e2e-work-primary", "Dagboek van een slecht jaar", "e2e-edition-primary");
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_ACTOR_LIBRARY, BIBLIO_E2E_MISSING_ITEM, "e2e-work-missing-metadata", "The Secret Commonwealth", "e2e-edition-missing-metadata");
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_ACTOR_LIBRARY, BIBLIO_E2E_CONFLICT_ITEM, "e2e-work-active-conflict", "Utopia Avenue", "e2e-edition-active-conflict");
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_ACTOR_LIBRARY, BIBLIO_E2E_END_COMPLETED_ITEM, "e2e-work-end-completed", "E2E Completed Flow", "e2e-edition-end-completed");
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_ACTOR_LIBRARY, BIBLIO_E2E_END_STOPPED_ITEM, "e2e-work-end-stopped", "E2E Stopped Flow", "e2e-edition-end-stopped");
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_ACTOR_LIBRARY, BIBLIO_E2E_END_STALE_ITEM, "e2e-work-end-stale", "E2E Stale Flow", "e2e-edition-end-stale");
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_ACTOR_LIBRARY, BIBLIO_E2E_END_NONCE_ITEM, "e2e-work-end-nonce", "E2E Nonce Flow", "e2e-edition-end-nonce");
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_ACTOR_LIBRARY, BIBLIO_E2E_END_IDEMPOTENT_ITEM, "e2e-work-end-idempotent", "E2E Idempotent Flow", "e2e-edition-end-idempotent");
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_ACTOR_LIBRARY, BIBLIO_E2E_END_LIFECYCLE_ITEM, "e2e-work-end-lifecycle", "E2E Lifecycle Flow", "e2e-edition-end-lifecycle");

    wp_set_current_user($other);
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_OTHER_LIBRARY, BIBLIO_E2E_HISTORY_ITEM, "e2e-work-history", "E2E Leesgeschiedenis", "e2e-edition-history");
    $composition->application()->libraryItemCreation()->addForExistingEdition(
        new LibraryId(BIBLIO_E2E_OTHER_LIBRARY),
        new ItemId(BIBLIO_E2E_HISTORY_SAME_EDITION_ITEM),
        new EditionId("e2e-edition-history")
    );
    $composition->application()->libraryItemCreation()->addWithNewEditionForExistingWork(
        new LibraryId(BIBLIO_E2E_OTHER_LIBRARY),
        new ItemId(BIBLIO_E2E_HISTORY_OTHER_EDITION_ITEM),
        new EditionId("e2e-edition-history-other"),
        new WorkId("e2e-work-history")
    );
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_OTHER_LIBRARY, BIBLIO_E2E_HISTORY_ZERO_ITEM, "e2e-work-history-zero", "E2E Geen Leesgeschiedenis", "e2e-edition-history-zero");
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_OTHER_LIBRARY, BIBLIO_E2E_HISTORY_ACTIVE_ITEM, "e2e-work-history-active-only", "E2E Alleen Actief", "e2e-edition-history-active-only");
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_OTHER_LIBRARY, BIBLIO_E2E_HISTORY_END_ITEM, "e2e-work-history-end", "E2E History End Flow", "e2e-edition-history-end");
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_OTHER_LIBRARY, BIBLIO_E2E_HISTORY_REFRESH_ITEM, "e2e-work-history-refresh", "E2E History Refresh Failure", "e2e-edition-history-refresh");
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_OTHER_LIBRARY, BIBLIO_E2E_HISTORY_RAPID_ITEM, "e2e-work-history-rapid", "E2E Andere Geschiedenis", "e2e-edition-history-rapid");
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_OTHER_LIBRARY, BIBLIO_E2E_FOREIGN_ITEM, "e2e-work-foreign", "Ripper", "e2e-edition-foreign");

    biblioE2eStartRound($database, $actorName, BIBLIO_E2E_ACTOR_LIBRARY, BIBLIO_E2E_END_COMPLETED_ITEM, ReadingDate::exact(2026, 8, 2));
    biblioE2eStartRound($database, $actorName, BIBLIO_E2E_ACTOR_LIBRARY, BIBLIO_E2E_END_STOPPED_ITEM, ReadingDate::exact(2026, 8, 3));
    biblioE2eStartRound($database, $actorName, BIBLIO_E2E_ACTOR_LIBRARY, BIBLIO_E2E_END_STALE_ITEM, ReadingDate::exact(2026, 8, 4));
    biblioE2eStartRound($database, $actorName, BIBLIO_E2E_ACTOR_LIBRARY, BIBLIO_E2E_END_NONCE_ITEM, ReadingDate::exact(2026, 8, 5));
    biblioE2eStartRound($database, $actorName, BIBLIO_E2E_ACTOR_LIBRARY, BIBLIO_E2E_END_IDEMPOTENT_ITEM, ReadingDate::exact(2026, 8, 6));
    biblioE2eStartRound($database, $actorName, BIBLIO_E2E_ACTOR_LIBRARY, BIBLIO_E2E_END_LIFECYCLE_ITEM, ReadingDate::exact(2026, 8, 7));
    biblioE2eStartRound($database, $otherName, BIBLIO_E2E_OTHER_LIBRARY, BIBLIO_E2E_FOREIGN_ITEM, ReadingDate::exact(2026, 8, 8));
    biblioE2eActivateConflict($database);
    biblioE2eStartRound($database, $actorName, BIBLIO_E2E_OTHER_LIBRARY, BIBLIO_E2E_HISTORY_ITEM, ReadingDate::exact(2026, 1, 2));
    biblioE2eStartRound($database, $actorName, BIBLIO_E2E_OTHER_LIBRARY, BIBLIO_E2E_HISTORY_ACTIVE_ITEM, ReadingDate::exact(2026, 1, 3));
    biblioE2eStartRound($database, $actorName, BIBLIO_E2E_OTHER_LIBRARY, BIBLIO_E2E_HISTORY_END_ITEM, ReadingDate::exact(2026, 1, 4));
    biblioE2eStartRound($database, $actorName, BIBLIO_E2E_OTHER_LIBRARY, BIBLIO_E2E_HISTORY_REFRESH_ITEM, ReadingDate::exact(2026, 1, 5));
    biblioE2eSeedHistoryRounds(
        $database,
        (string) $actor,
        (string) $other
    );
    biblioE2eSeedPrivateNotes(
        $database,
        (string) $actor,
        (string) $other
    );
}

biblioE2eGuard();
global $wpdb;
$action = $args[0] ?? "";

try {
    switch ($action) {
        case "setup":
            biblioE2eSetup($wpdb);
            break;
        case "cleanup":
            biblioE2eCleanup();
            break;
        case "verify-clean":
            $counts = biblioE2eCounts($wpdb);
            if (array_sum($counts) !== 0) {
                throw new RuntimeException("Exact fixture residue remains.");
            }
            break;
        case "conflict-reset":
            biblioE2eResetConflict($wpdb);
            break;
        case "conflict-activate":
            biblioE2eActivateConflict($wpdb);
            break;
        case "stale-end":
            biblioE2eAdvanceStaleRound($wpdb);
            break;
        case "note-stale-update":
            biblioE2eAdvancePrivateNote(
                $wpdb,
                BIBLIO_E2E_NOTE_STALE_UPDATE,
                "<p>E2E serverstate na stale update.</p>"
            );
            break;
        case "note-stale-delete":
            biblioE2eAdvancePrivateNote(
                $wpdb,
                BIBLIO_E2E_NOTE_STALE_DELETE,
                "<p>E2E serverstate na stale delete.</p>"
            );
            break;
        case "note-unavailable-delete":
            biblioE2eDeleteUnavailablePrivateNote($wpdb);
            break;
        case "state":
        case "fingerprint":
            break;
        default:
            biblioE2eFail("unknown action.");
    }

    $response = [
        "action" => $action,
        "host" => BIBLIO_E2E_HOST,
        "ids" => biblioE2eIds(),
        "counts" => biblioE2eCounts($wpdb),
    ];

    if (in_array($action, [
        "setup",
        "state",
        "stale-end",
        "note-stale-update",
        "note-stale-delete",
        "note-unavailable-delete",
    ], true)) {
        $response["state"] = biblioE2eRoundState($wpdb);
        $response["state"]["private_notes"] = biblioE2ePrivateNoteState($wpdb);
    }

    if ($action === "fingerprint") {
        $response["fingerprint"] = biblioE2eFingerprint($wpdb);
    }

    echo wp_json_encode(
        $response,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, "Biblio E2E fixture failed: {$exception->getMessage()}\n");
    exit(1);
}
