<?php

declare(strict_types=1);

use Biblio\Core\Application\Identity\PlatformUserDirectory;
use Biblio\Core\Application\Wishlist\{AddWishlistEntryService,RefineWishlistEntryService,WishlistRecorder};
use Biblio\Core\Catalog\{EditionId,WorkId};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{CoreTableNames,WpdbEditionRepository,WpdbTransactionManager,WpdbWishlistRepository,WpdbWorkRepository};
use Biblio\Core\Infrastructure\WordPress\{OpaqueWishlistEntryIdGenerator,SystemWishlistClock};
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use Biblio\Core\Wishlist\{WishlistEntryNotAvailable,WishlistIntentConflict};

if ($argc !== 7) {
    fwrite(STDERR, "Expected action, user, work, edition-or-dash, ready path and release path.\n");
    exit(2);
}

[, $wishlistWorkerAction, $wishlistWorkerUser, $wishlistWorkerWork, $wishlistWorkerEdition, $wishlistWorkerReady, $wishlistWorkerRelease] = $argv;
require dirname(__DIR__) . "/bootstrap.php";

if (file_put_contents($wishlistWorkerReady, "ready") === false) {
    throw new RuntimeException("Could not signal Wishlist worker readiness.");
}
$deadline = microtime(true) + 15;
while (!is_file($wishlistWorkerRelease)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException("Wishlist mutation barrier timed out.");
    }
    usleep(10_000);
}

$user = new UserId($wishlistWorkerUser);
$tables = new CoreTableNames($wpdb->prefix);
$transactions = new WpdbTransactionManager($wpdb);
$recorder = new WishlistRecorder(
    new class implements PlatformUserDirectory {
        public function isActive(UserId $userId): bool { return true; }
    },
    new WpdbWorkRepository($wpdb, $tables),
    new WpdbEditionRepository($wpdb, $tables),
    new WpdbWishlistRepository($wpdb, $tables),
    new OpaqueWishlistEntryIdGenerator(),
    new SystemWishlistClock()
);
$actor = new ControllableAuthenticatedUser($user);

try {
    if ($wishlistWorkerAction === "add_work") {
        $result = (new AddWishlistEntryService($actor, $recorder, $transactions))
            ->addWorkOnly(new WorkId($wishlistWorkerWork));
    } elseif ($wishlistWorkerAction === "add_edition") {
        $result = (new AddWishlistEntryService($actor, $recorder, $transactions))
            ->addEdition(new WorkId($wishlistWorkerWork), new EditionId($wishlistWorkerEdition));
    } elseif ($wishlistWorkerAction === "refine") {
        $result = (new RefineWishlistEntryService($actor, $recorder, $transactions))
            ->refineToEdition(new WorkId($wishlistWorkerWork), new EditionId($wishlistWorkerEdition));
    } else {
        throw new RuntimeException("Unknown Wishlist worker action.");
    }
    $status = $result->wasCreated()
        ? "created"
        : ($result->wasRefined() ? "refined" : "reused");
    $payload = [
        "status" => $status,
        "entry_id" => $result->entry()->id()->value(),
    ];
} catch (WishlistIntentConflict) {
    $payload = ["status" => "conflict"];
} catch (WishlistEntryNotAvailable) {
    $payload = ["status" => "not_available"];
}

fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR) . "\n");
