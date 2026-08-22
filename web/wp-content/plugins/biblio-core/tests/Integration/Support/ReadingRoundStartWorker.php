<?php

declare(strict_types=1);

use Biblio\Core\Application\Library\GetAccessibleLibraryItemService;
use Biblio\Core\Application\Library\LibraryAccessService;
use Biblio\Core\Application\Reading\CreateActiveReadingRoundService;
use Biblio\Core\Application\Reading\StartReadingFromLibraryItemService;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingRoundRepository;
use Biblio\Core\Infrastructure\WordPress\OpaqueReadingRoundIdGenerator;
use Biblio\Core\Infrastructure\WordPress\SystemReadingRoundClock;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Reading\ActiveReadingRoundAlreadyExists;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingRoundLifecycle;
use Biblio\Core\Reading\ReadingRoundVersion;
use Biblio\Core\Reading\ReadingSource;
use Biblio\Core\Reading\WritableReadingRoundRepository;
use Biblio\Core\Tests\Support\ControllableAuthenticatedUser;

if ($argc !== 6) {
    fwrite(
        STDERR,
        "Expected user, library, item, ready path and release path.\n"
    );
    exit(2);
}

[, $userValue, $libraryValue, $itemValue, $readyPath, $releasePath] = $argv;

require dirname(__DIR__) . "/bootstrap.php";

$tableNames = new CoreTableNames($wpdb->prefix);
$repository = new WpdbReadingRoundRepository($wpdb, $tableNames);
$barrierRepository = new class(
    $repository,
    $readyPath,
    $releasePath
) implements WritableReadingRoundRepository {
    public function __construct(
        private WritableReadingRoundRepository $repository,
        private string $readyPath,
        private string $releasePath
    ) {
    }

    public function addForUser(
        UserId $authenticatedUserId,
        ReadingRound $readingRound
    ): void {
        if (file_put_contents($this->readyPath, "ready") === false) {
            throw new RuntimeException("Could not signal race readiness.");
        }

        $deadline = microtime(true) + 15;

        while (!is_file($this->releasePath)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Race barrier timed out.");
            }

            usleep(10_000);
        }

        $this->repository->addForUser(
            $authenticatedUserId,
            $readingRound
        );
    }

    public function findForUser(
        ReadingRoundId $readingRoundId,
        UserId $userId
    ): ?ReadingRound {
        return $this->repository->findForUser($readingRoundId, $userId);
    }

    public function findActiveForUserAndSource(
        UserId $userId,
        ReadingSource $source
    ): ?ReadingRound {
        return $this->repository->findActiveForUserAndSource($userId, $source);
    }

    public function findForUserForUpdate(
        ReadingRoundId $readingRoundId,
        UserId $userId
    ): ?ReadingRound {
        return $this->repository->findForUserForUpdate($readingRoundId, $userId);
    }

    public function findAllForUserAndWork(UserId $userId, WorkId $workId): array
    {
        return $this->repository->findAllForUserAndWork($userId, $workId);
    }

    public function replaceIfVersionMatches(
        UserId $authenticatedUserId,
        ReadingRound $replacement,
        ReadingRoundVersion $expectedVersion,
        ReadingRoundLifecycle $expectedLifecycle
    ): bool {
        return $this->repository->replaceIfVersionMatches(
            $authenticatedUserId,
            $replacement,
            $expectedVersion,
            $expectedLifecycle
        );
    }

    public function deleteHistoricalIfVersionMatches(
        UserId $authenticatedUserId,
        ReadingRoundId $readingRoundId,
        ReadingRoundVersion $expectedVersion
    ): bool {
        return $this->repository->deleteHistoricalIfVersionMatches(
            $authenticatedUserId,
            $readingRoundId,
            $expectedVersion
        );
    }
};
$service = new StartReadingFromLibraryItemService(
    new GetAccessibleLibraryItemService(
        new ControllableAuthenticatedUser(new UserId($userValue)),
        new WpdbItemRepository($wpdb, $tableNames),
        new LibraryAccessService(
            new WpdbLibraryMembershipRepository($wpdb, $tableNames),
            new LibraryAuthorizationPolicy()
        )
    ),
    new WpdbEditionRepository($wpdb, $tableNames),
    new CreateActiveReadingRoundService(
        new ControllableAuthenticatedUser(new UserId($userValue)),
        $barrierRepository,
        new OpaqueReadingRoundIdGenerator(),
        new SystemReadingRoundClock()
    )
);
try {
    $round = $service->start(
        new LibraryId($libraryValue),
        new ItemId($itemValue),
        new DateTimeImmutable("2026-08-16T10:00:00.000000+00:00")
    );
    fwrite(STDOUT, json_encode([
        "status" => "created",
        "readingRoundId" => $round->id()->value(),
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (ActiveReadingRoundAlreadyExists) {
    fwrite(STDOUT, json_encode([
        "status" => "conflict",
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ": " . $exception->getMessage());
    exit(1);
}
