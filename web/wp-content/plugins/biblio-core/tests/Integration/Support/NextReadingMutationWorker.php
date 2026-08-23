<?php

declare(strict_types=1);

use Biblio\Core\Application\Library\{GetAccessibleLibraryItemService,LibraryAccessService};
use Biblio\Core\Application\NextReading\{AddLibraryItemToNextReadingService,AddWorkToNextReadingService,NextReadingMutation,RemoveNextReadingEntryService,ReorderNextReadingListService};
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\{ItemId,WorkId};
use Biblio\Core\Infrastructure\Persistence\WordPress\{CoreTableNames,WpdbEditionRepository,WpdbItemRepository,WpdbLibraryMembershipRepository,WpdbNextReadingRepository,WpdbTransactionManager,WpdbWorkRepository};
use Biblio\Core\Infrastructure\WordPress\{OpaqueNextReadingEntryIdGenerator,SystemNextReadingClock};
use Biblio\Core\Library\LibraryId;
use Biblio\Core\NextReading\{NextReadingEntryId,NextReadingEntryNotAvailable,NextReadingListStale,NextReadingListVersion,NextReadingTargetDuplicate,NextReadingTargetUnavailable};
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Exception\ValidationException;

if ($argc !== 7) {
    fwrite(STDERR, "Expected action, payload, user, expected version, ready path and release path.\n");
    exit(2);
}

[, $nextWorkerAction, $nextWorkerPayload, $nextWorkerUser, $nextWorkerExpected, $readyPath, $releasePath] = $argv;
require dirname(__DIR__) . "/bootstrap.php";

if (file_put_contents($readyPath, "ready") === false) {
    throw new RuntimeException("Could not signal Next Reading worker readiness.");
}
$deadline = microtime(true) + 15;
while (!is_file($releasePath)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException("Next Reading mutation barrier timed out.");
    }
    usleep(10_000);
}

$actor = new ControllableAuthenticatedUser(new UserId($nextWorkerUser));
$tables = new CoreTableNames($wpdb->prefix);
$repository = new WpdbNextReadingRepository($wpdb, $tables);
$transactions = new WpdbTransactionManager($wpdb);
$clock = new SystemNextReadingClock();
$mutation = new NextReadingMutation(
    $repository,
    new OpaqueNextReadingEntryIdGenerator(),
    $clock,
    $transactions
);

try {
    if ($nextWorkerAction === "add_work") {
        $list = (new AddWorkToNextReadingService(
            $actor,
            new WpdbWorkRepository($wpdb, $tables),
            $mutation
        ))->add(new WorkId($nextWorkerPayload));
        $result = ["status" => "added", "version" => $list->version()->value()];
    } elseif ($nextWorkerAction === "add_item") {
        [$libraryId, $itemId] = explode(",", $nextWorkerPayload, 2);
        $memberships = new WpdbLibraryMembershipRepository($wpdb, $tables);
        $accessible = new GetAccessibleLibraryItemService(
            $actor,
            new WpdbItemRepository($wpdb, $tables),
            new LibraryAccessService($memberships, new LibraryAuthorizationPolicy())
        );
        $list = (new AddLibraryItemToNextReadingService(
            $actor,
            $accessible,
            new WpdbEditionRepository($wpdb, $tables),
            $mutation
        ))->add(new LibraryId($libraryId), new ItemId($itemId));
        $result = ["status" => "added", "version" => $list->version()->value()];
    } elseif ($nextWorkerAction === "reorder") {
        $ids = $nextWorkerPayload === "" ? [] : array_map(
            static fn (string $id): NextReadingEntryId => new NextReadingEntryId($id),
            explode(",", $nextWorkerPayload)
        );
        $list = (new ReorderNextReadingListService(
            $actor,
            $repository,
            $clock,
            $transactions
        ))->reorder(new NextReadingListVersion((int) $nextWorkerExpected), $ids);
        $result = ["status" => "reordered", "version" => $list->version()->value()];
    } elseif ($nextWorkerAction === "remove") {
        $list = (new RemoveNextReadingEntryService(
            $actor,
            $repository,
            $clock,
            $transactions
        ))->remove(
            new NextReadingEntryId($nextWorkerPayload),
            new NextReadingListVersion((int) $nextWorkerExpected)
        );
        $result = ["status" => "removed", "version" => $list->version()->value()];
    } elseif ($nextWorkerAction === "delete_item") {
        $deleted = $wpdb->delete($tables->items(), ["item_id" => $nextWorkerPayload]);
        $result = ["status" => $deleted === 1 ? "source_deleted" : "source_missing"];
    } else {
        throw new RuntimeException("Unknown Next Reading worker action.");
    }
} catch (NextReadingTargetDuplicate) {
    $result = ["status" => "duplicate"];
} catch (NextReadingListStale) {
    $result = ["status" => "stale"];
} catch (NextReadingEntryNotAvailable) {
    $result = ["status" => "not_available"];
} catch (NextReadingTargetUnavailable) {
    $result = ["status" => "target_unavailable"];
} catch (ValidationException) {
    $result = ["status" => "invalid"];
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
