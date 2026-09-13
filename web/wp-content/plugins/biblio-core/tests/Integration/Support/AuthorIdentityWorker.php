<?php

declare(strict_types=1);

use Biblio\Core\Application\Metadata\Author\{
    AuthorContributorCredit,
    AuthorContributorCreditId,
    AuthorContributorCreditKey,
    AuthorContributorCreditRace,
    AuthorContributorCreditSourceIdentity,
    AuthorContributorCreditStatus,
    AuthorContributorCreditVersion,
    AuthorProviderClaimRace,
    AuthorProviderIdentityConflict
};
use Biblio\Core\Catalog\{
    AuthorId,
    ContributorPosition,
    ContributorRole,
    WorkId
};
use Biblio\Core\Infrastructure\Persistence\WordPress\{
    CoreTableNames,
    WpdbAuthorContributorCreditRepository,
    WpdbBibliographicProviderIdentityRepository,
    WpdbTransactionManager
};

if ($argc !== 7) {
    fwrite(
        STDERR,
        "Expected mode, record, target, credit Author, worker token and barrier.\n"
    );
    exit(2);
}

[
    , $mode, $recordId, $targetId, $creditAuthorId, $workerToken,
    $barrierDirectory,
] = $argv;
require dirname(__DIR__) . "/bootstrap.php";

$database = $GLOBALS["wpdb"];
$tables = new CoreTableNames($database->prefix);
$transactions = new WpdbTransactionManager($database);
$status = "success";

try {
    $transactions->run(function () use (
        $mode,
        $recordId,
        $targetId,
        $creditAuthorId,
        $workerToken,
        $barrierDirectory,
        $database,
        $tables
    ): void {
        if ($mode === "provider") {
            $repository = new WpdbBibliographicProviderIdentityRepository(
                $database,
                $tables
            );
            $repository->findAuthor("open_library", $recordId);
        } elseif ($mode === "credit") {
            $repository = new WpdbAuthorContributorCreditRepository(
                $database,
                $tables
            );
            $key = creditKey($recordId);
            $repository->findByKey($key);
        } else {
            throw new RuntimeException("Unknown Author identity worker mode.");
        }

        if (file_put_contents(
            $barrierDirectory . "/ready-" . $workerToken,
            "ready"
        ) === false) {
            throw new RuntimeException("Could not signal Author race readiness.");
        }
        $deadline = microtime(true) + 15;
        while (!is_file($barrierDirectory . "/release")) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Author race barrier timed out.");
            }
            usleep(10_000);
        }

        if ($mode === "provider") {
            $repository->claimAuthor(
                "open_library",
                $recordId,
                new AuthorId($targetId)
            );
            return;
        }

        $now = new DateTimeImmutable("2026-09-13T12:00:00+00:00");
        $repository->create(new AuthorContributorCredit(
            new AuthorContributorCreditId($targetId),
            creditKey($recordId),
            new WorkId("concurrency-work"),
            ContributorRole::Author,
            new ContributorPosition(1),
            "Peter King",
            new AuthorId($creditAuthorId),
            AuthorContributorCreditStatus::Linked,
            null,
            $now,
            $now,
            AuthorContributorCreditVersion::initial()
        ));
    });
} catch (AuthorProviderClaimRace|AuthorContributorCreditRace) {
    $status = "race";
} catch (AuthorProviderIdentityConflict) {
    $status = "conflict";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ": " . $exception->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, json_encode(["status" => $status], JSON_THROW_ON_ERROR));

function creditKey(string $sourceRecordId): AuthorContributorCreditKey
{
    return AuthorContributorCreditKey::fromSource(
        new WorkId("concurrency-work"),
        ContributorRole::Author,
        new ContributorPosition(1),
        "Peter King",
        AuthorContributorCreditSourceIdentity::provider(
            "open_library",
            "work",
            $sourceRecordId,
            1
        )
    );
}
