<?php

declare(strict_types=1);

use Biblio\Core\Application\Borrowing\GetOwnedExternalLoanService;
use Biblio\Core\Application\Library\{GetAccessibleLibraryItemService,LibraryAccessService};
use Biblio\Core\Application\NextReading\{AddNextReadingEntryService,ConsumeNextReadingAfterStartService,NextReadingMutation,RemoveNextReadingEntryService,ReorderNextReadingListService,SetNextReadingPreferredSourceService,UndoNextReadingRemovalService};
use Biblio\Core\Application\Reading\{CreateActiveReadingRoundService,StartReadingFromExternalLoanService,StartReadingFromLibraryItemService};
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\{ItemId,WorkId};
use Biblio\Core\Infrastructure\Persistence\WordPress\{CoreTableNames,WpdbEditionRepository,WpdbExternalLoanRepository,WpdbItemRepository,WpdbLibraryMembershipRepository,WpdbNextReadingRepository,WpdbReadingRoundRepository,WpdbTransactionManager,WpdbWorkRepository};
use Biblio\Core\Infrastructure\WordPress\{OpaqueNextReadingEntryIdGenerator,OpaqueNextReadingUndoTokenGenerator,OpaqueReadingRoundIdGenerator,SystemNextReadingClock,SystemReadingRoundClock};
use Biblio\Core\Library\LibraryId;
use Biblio\Core\NextReading\{NextReadingEntryId,NextReadingEntryNotAvailable,NextReadingListStale,NextReadingListVersion,NextReadingUndoToken,NextReadingUndoUnavailable,PreferredReadingSourceUnavailable};
use Biblio\Core\Reading\ReadingDate;
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
$memberships = new WpdbLibraryMembershipRepository($wpdb, $tables);
$accessible = new GetAccessibleLibraryItemService(
    $actor,
    new WpdbItemRepository($wpdb, $tables),
    new LibraryAccessService($memberships, new LibraryAuthorizationPolicy())
);
$add = new AddNextReadingEntryService(
    $actor,
    new WpdbWorkRepository($wpdb, $tables),
    $accessible,
    new WpdbEditionRepository($wpdb, $tables),
    new GetOwnedExternalLoanService($actor, new WpdbExternalLoanRepository($wpdb, $tables)),
    $mutation
);
$readingCreator = new CreateActiveReadingRoundService(
    $actor,
    new WpdbReadingRoundRepository($wpdb, $tables),
    new OpaqueReadingRoundIdGenerator(),
    new SystemReadingRoundClock(),
    $transactions,
    new ConsumeNextReadingAfterStartService($repository, $clock)
);

try {
    if ($nextWorkerAction === "add_work") {
        $list = $add->add(new WorkId($nextWorkerPayload));
        $result = ["status" => "added", "version" => $list->version()->value()];
    } elseif ($nextWorkerAction === "add_item") {
        [$libraryId, $itemId] = explode(",", $nextWorkerPayload, 2);
        $list = $add->addWithLibraryItem(
            new WorkId("race-work-1"),
            new LibraryId($libraryId),
            new ItemId($itemId)
        );
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
            new OpaqueNextReadingUndoTokenGenerator(),
            $transactions
        ))->remove(
            new NextReadingEntryId($nextWorkerPayload),
            new NextReadingListVersion((int) $nextWorkerExpected)
        );
        $result = ["status" => "removed", "version" => $list->list()->version()->value()];
    } elseif ($nextWorkerAction === "start_item" || $nextWorkerAction === "start_entry") {
        $parts = explode(",", $nextWorkerPayload);
        $entryId = $nextWorkerAction === "start_entry"
            ? new NextReadingEntryId((string) array_shift($parts))
            : null;
        [$libraryId, $itemId] = $parts;
        $service = new StartReadingFromLibraryItemService(
            $accessible,
            new WpdbEditionRepository($wpdb, $tables),
            $readingCreator
        );
        $entryId === null
            ? $service->start(new LibraryId($libraryId), new ItemId($itemId), ReadingDate::exact(2026, 9, 2))
            : $service->startForNextReadingEntry($entryId, new LibraryId($libraryId), new ItemId($itemId), ReadingDate::exact(2026, 9, 2));
        $result = ["status" => "started"];
    } elseif ($nextWorkerAction === "start_external") {
        $loanRepository = new WpdbExternalLoanRepository($wpdb, $tables);
        $loanId = new \Biblio\Core\Borrowing\ExternalLoanId($nextWorkerPayload);
        (new StartReadingFromExternalLoanService(
            new GetOwnedExternalLoanService(
                $actor,
                $loanRepository
            ),
            $readingCreator
        ))->start($loanId, ReadingDate::exact(2026, 9, 2));
        $result = ["status" => "started"];
    } elseif ($nextWorkerAction === "set_item") {
        [$entryId, $libraryId, $itemId] = explode(",", $nextWorkerPayload, 3);
        $list = (new SetNextReadingPreferredSourceService(
            $actor,
            $repository,
            $accessible,
            new WpdbEditionRepository($wpdb, $tables),
            new GetOwnedExternalLoanService($actor, new WpdbExternalLoanRepository($wpdb, $tables)),
            $clock,
            $transactions
        ))->setLibraryItem(
            new NextReadingEntryId($entryId),
            new NextReadingListVersion((int) $nextWorkerExpected),
            new LibraryId($libraryId),
            new ItemId($itemId)
        );
        $result = ["status" => "preferred", "version" => $list->version()->value()];
    } elseif ($nextWorkerAction === "undo") {
        $list = (new UndoNextReadingRemovalService(
            $actor,
            $repository,
            $clock,
            $transactions
        ))->undo(new NextReadingUndoToken($nextWorkerPayload));
        $result = ["status" => "undone", "version" => $list->version()->value()];
    } elseif ($nextWorkerAction === "delete_item") {
        $deleted = $wpdb->delete($tables->items(), ["item_id" => $nextWorkerPayload]);
        $result = ["status" => $deleted === 1 ? "source_deleted" : "source_missing"];
    } else {
        throw new RuntimeException("Unknown Next Reading worker action.");
    }
} catch (NextReadingListStale) {
    $result = ["status" => "stale"];
} catch (NextReadingEntryNotAvailable) {
    $result = ["status" => "not_available"];
} catch (NextReadingUndoUnavailable) {
    $result = ["status" => "undo_unavailable"];
} catch (PreferredReadingSourceUnavailable) {
    $result = ["status" => "target_unavailable"];
} catch (ValidationException) {
    $result = ["status" => "invalid"];
}

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR) . "\n");
