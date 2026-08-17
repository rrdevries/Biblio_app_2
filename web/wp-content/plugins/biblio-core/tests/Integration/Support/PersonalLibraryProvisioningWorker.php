<?php

declare(strict_types=1);

use Biblio\Core\Application\Library\CreateLibraryService;
use Biblio\Core\Application\Library\EnsurePersonalPrivateLibraryService;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPersonalLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbTransactionManager;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\PersonalLibraryRepository;

if ($argc !== 4) {
    fwrite(STDERR, "Expected user, ready path and release path.\n");
    exit(2);
}

[, $userValue, $readyPath, $releasePath] = $argv;

require dirname(__DIR__) . "/bootstrap.php";

$tableNames = new CoreTableNames($wpdb->prefix);
$personalLibraryRepository = new WpdbPersonalLibraryRepository(
    $wpdb,
    $tableNames
);
$barrierRepository = new class(
    $personalLibraryRepository,
    $readyPath,
    $releasePath
) implements PersonalLibraryRepository {
    public function __construct(
        private PersonalLibraryRepository $repository,
        private string $readyPath,
        private string $releasePath
    ) {
    }

    public function findForUser(UserId $userId): ?LibraryId
    {
        return $this->repository->findForUser($userId);
    }

    public function designate(UserId $userId, LibraryId $libraryId): void
    {
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

        $this->repository->designate($userId, $libraryId);
    }
};
$createLibraryService = new CreateLibraryService(
    new WpdbLibraryRepository($wpdb, $tableNames),
    new WpdbLibraryMembershipRepository($wpdb, $tableNames),
    new WpdbTransactionManager($wpdb)
);
$service = new EnsurePersonalPrivateLibraryService(
    $barrierRepository,
    $createLibraryService
);

try {
    fwrite(STDOUT, $service->ensure(new UserId($userValue))->value());
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ": " . $exception->getMessage());
    exit(1);
}
