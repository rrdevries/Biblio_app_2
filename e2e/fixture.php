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
use Biblio\Core\Library\LibraryName;
use Biblio\Core\Reading\ReadingDate;

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
        "primary_edition" => "e2e-edition-primary",
        "missing_edition" => "e2e-edition-missing-metadata",
        "conflict_edition" => "e2e-edition-active-conflict",
        "foreign_edition" => "e2e-edition-foreign",
        "primary_item" => BIBLIO_E2E_PRIMARY_ITEM,
        "missing_item" => BIBLIO_E2E_MISSING_ITEM,
        "conflict_item" => BIBLIO_E2E_CONFLICT_ITEM,
        "foreign_item" => BIBLIO_E2E_FOREIGN_ITEM,
    ];
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
    $works = [
        $ids["primary_work"], $ids["missing_work"],
        $ids["conflict_work"], $ids["foreign_work"],
    ];
    $editions = [
        $ids["primary_edition"], $ids["missing_edition"],
        $ids["conflict_edition"], $ids["foreign_edition"],
    ];
    $items = [
        $ids["primary_item"], $ids["missing_item"],
        $ids["conflict_item"], $ids["foreign_item"],
    ];

    if ($database->query("START TRANSACTION") === false) {
        throw new RuntimeException("Could not start exact fixture cleanup.");
    }

    try {
        biblioE2eDeleteIn($database, $tables->contributionPublications(), "library_id", $libraries);
        biblioE2eDeleteIn($database, $tables->libraryActivityEvents(), "library_id", $libraries);
        biblioE2eDeleteIn($database, $tables->nextReadingEntries(), "item_id", $items);
        biblioE2eDeleteIn($database, $tables->readingRounds(), "item_id", $items);
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

        if (
            get_user_meta($user->ID, BIBLIO_E2E_MARKER_KEY, true)
            !== BIBLIO_E2E_MARKER_VALUE
        ) {
            biblioE2eFail("refusing to delete an unmarked username collision.");
        }

        if (!wp_delete_user($user->ID)) {
            throw new RuntimeException("Could not delete exact fixture user.");
        }
    }
}

function biblioE2eCleanup(): void
{
    global $wpdb;
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

function biblioE2eActivateConflict(wpdb $database): void
{
    [$actor] = biblioE2eUsernames();
    $user = get_user_by("login", $actor);

    if (!$user instanceof WP_User) {
        biblioE2eFail("actor fixture user does not exist.");
    }

    wp_set_current_user($user->ID);
    (new ProductionComposition($database))->application()->libraryItemReading()->start(
        new LibraryId(BIBLIO_E2E_ACTOR_LIBRARY),
        new ItemId(BIBLIO_E2E_CONFLICT_ITEM),
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
    $items = [
        $ids["primary_item"], $ids["missing_item"],
        $ids["conflict_item"], $ids["foreign_item"],
    ];
    $librarySql = implode(",", array_fill(0, count($libraries), "%s"));
    $itemSql = implode(",", array_fill(0, count($items), "%s"));
    $workValues = [
        $ids["primary_work"], $ids["missing_work"],
        $ids["conflict_work"], $ids["foreign_work"],
    ];
    $editionValues = [
        $ids["primary_edition"], $ids["missing_edition"],
        $ids["conflict_edition"], $ids["foreign_edition"],
    ];
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
        "rounds" => (int) $database->get_var($database->prepare(
            "SELECT COUNT(*) FROM `{$tables->readingRounds()}` WHERE item_id IN ({$itemSql})",
            ...$items
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

    $composition = new ProductionComposition($database);
    wp_set_current_user($actor);
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_ACTOR_LIBRARY, BIBLIO_E2E_PRIMARY_ITEM, "e2e-work-primary", "Dagboek van een slecht jaar", "e2e-edition-primary");
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_ACTOR_LIBRARY, BIBLIO_E2E_MISSING_ITEM, "e2e-work-missing-metadata", "The Secret Commonwealth", "e2e-edition-missing-metadata");
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_ACTOR_LIBRARY, BIBLIO_E2E_CONFLICT_ITEM, "e2e-work-active-conflict", "Utopia Avenue", "e2e-edition-active-conflict");

    wp_set_current_user($other);
    biblioE2eAddItem($database, $composition, BIBLIO_E2E_OTHER_LIBRARY, BIBLIO_E2E_FOREIGN_ITEM, "e2e-work-foreign", "Ripper", "e2e-edition-foreign");
    biblioE2eActivateConflict($database);
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
        default:
            biblioE2eFail("unknown action.");
    }

    echo wp_json_encode([
        "action" => $action,
        "host" => BIBLIO_E2E_HOST,
        "ids" => biblioE2eIds(),
        "counts" => biblioE2eCounts($wpdb),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, "Biblio E2E fixture failed: {$exception->getMessage()}\n");
    exit(1);
}
