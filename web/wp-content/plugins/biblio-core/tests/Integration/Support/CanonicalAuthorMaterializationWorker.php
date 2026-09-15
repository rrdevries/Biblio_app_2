<?php

declare(strict_types=1);

use Biblio\Core\Application\Metadata\Author\{
    AuthorContributorCreditId,
    AuthorContributorCreditRace,
    AuthorContributorCreditRepository,
    AuthorCreditProviderSourceType,
    AuthorIdentityPromotionRace,
    AuthorProviderClaimRace,
    AuthorProviderIdentityRepository,
    AuthorContributorPositionRace,
    CanonicalAuthorMaterializationIdGenerator,
    CanonicalAuthorMaterializer,
    NameOnlyAuthorCredit,
    OpenLibraryAuthorId,
    StrongOpenLibraryAuthorCredit
};
use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Catalog\{
    Author,
    AuthorId,
    AuthorVersion,
    ContributorPosition,
    ContributorRole,
    WorkContributor,
    WorkId,
    WritableAuthorRepository
};
use Biblio\Core\Infrastructure\Persistence\WordPress\{
    CoreTableNames,
    WpdbAuthorContributorCreditRepository,
    WpdbAuthorRepository,
    WpdbBibliographicProviderIdentityRepository,
    WpdbTransactionManager
};

require dirname(__DIR__) . "/bootstrap.php";

/** @param list<string> $arguments */
function runCanonicalAuthorMaterializationWorker(int $argumentCount, array $arguments): void
{
    if ($argumentCount !== 9) {
        fwrite(STDERR, "Expected mode, Work, provider Author, source Work, name, token, barrier and position.\n");
        exit(2);
    }
    [
        , $mode, $workId, $providerAuthorId, $sourceWorkId, $name, $token,
        $barrierDirectory, $position,
    ] = $arguments;
    $database = $GLOBALS["wpdb"];
    $tables = new CoreTableNames($database->prefix);
    $baseAuthors = new WpdbAuthorRepository($database, $tables);
    $baseClaims = new WpdbBibliographicProviderIdentityRepository($database, $tables);
    $baseCredits = new WpdbAuthorContributorCreditRepository($database, $tables);
    $barrier = new OneShotAuthorMaterializationBarrier($barrierDirectory, $token);

    $authors = in_array($mode, ["edge", "name_edge"], true)
        ? new BarrierAuthorRepository($baseAuthors, $barrier)
        : $baseAuthors;
    $claims = $mode === "provider"
        ? new BarrierAuthorProviderRepository($baseClaims, $barrier)
        : $baseClaims;
    $credits = in_array($mode, ["credit", "name_credit"], true)
        ? new BarrierAuthorCreditRepository($baseCredits, $barrier)
        : $baseCredits;
    $service = new CanonicalAuthorMaterializer(
        $authors,
        $claims,
        $credits,
        new FixedWorkerAuthorIds($token),
        new FixedWorkerAuthorClock()
    );
    $input = str_starts_with($mode, "name_")
        ? new NameOnlyAuthorCredit(
            new WorkId($workId),
            ContributorRole::Author,
            new ContributorPosition((int) $position),
            $name,
            "google_books",
            AuthorCreditProviderSourceType::Work,
            $sourceWorkId,
            new \DateTimeImmutable("2026-09-13T11:00:00+00:00")
        )
        : new StrongOpenLibraryAuthorCredit(
            new WorkId($workId),
            ContributorRole::Author,
            new ContributorPosition((int) $position),
            $name,
            new OpenLibraryAuthorId($providerAuthorId),
            AuthorCreditProviderSourceType::Work,
            $sourceWorkId,
            new \DateTimeImmutable("2026-09-13T11:00:00+00:00")
        );
    $transactions = new WpdbTransactionManager($database);
    $races = 0;

    try {
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $result = $transactions->run(fn () => $input instanceof
                    NameOnlyAuthorCredit
                    ? $service->materializeNameOnlyAuthor($input)
                    : $service->materializeStrongOpenLibraryAuthor($input));
                fwrite(STDOUT, json_encode([
                    "status" => $result->status()->value,
                    "author_id" => $result->authorId()?->value(),
                    "races" => $races,
                ], JSON_THROW_ON_ERROR));
                exit(0);
            } catch (
                AuthorProviderClaimRace
                |AuthorContributorCreditRace
                |AuthorContributorPositionRace
                |AuthorIdentityPromotionRace
            ) {
                ++$races;
                if ($attempt === 1) {
                    throw new RuntimeException("Race retry exhausted.");
                }
            }
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception::class . ": " . $exception->getMessage() . "\n");
        exit(1);
    }
}

final class OneShotAuthorMaterializationBarrier
{
    private bool $used = false;
    public function __construct(private string $directory, private string $token) {}
    public function wait(): void
    {
        if ($this->used) { return; }
        $this->used = true;
        if (file_put_contents($this->directory . "/ready-" . $this->token, "ready") === false) {
            throw new RuntimeException("Could not signal materialization race readiness.");
        }
        $deadline = microtime(true) + 15;
        while (!is_file($this->directory . "/release")) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException("Materialization race barrier timed out.");
            }
            usleep(10_000);
        }
    }
}

final readonly class BarrierAuthorProviderRepository implements AuthorProviderIdentityRepository
{
    public function __construct(
        private AuthorProviderIdentityRepository $inner,
        private OneShotAuthorMaterializationBarrier $barrier
    ) {}
    public function findAuthor(string $provider, string $recordId): ?AuthorId
    {
        $result = $this->inner->findAuthor($provider, $recordId);
        $this->barrier->wait();
        return $result;
    }
    public function claimAuthor(string $provider, string $recordId, AuthorId $authorId): void
    {
        $this->inner->claimAuthor($provider, $recordId, $authorId);
    }
}

final readonly class BarrierAuthorCreditRepository implements AuthorContributorCreditRepository
{
    public function __construct(
        private AuthorContributorCreditRepository $inner,
        private OneShotAuthorMaterializationBarrier $barrier
    ) {}
    public function find(AuthorContributorCreditId $creditId): ?\Biblio\Core\Application\Metadata\Author\AuthorContributorCredit
    {
        return $this->inner->find($creditId);
    }
    public function findByKey(\Biblio\Core\Application\Metadata\Author\AuthorContributorCreditKey $key): ?\Biblio\Core\Application\Metadata\Author\AuthorContributorCredit
    {
        $result = $this->inner->findByKey($key);
        $this->barrier->wait();
        return $result;
    }
    public function create(\Biblio\Core\Application\Metadata\Author\AuthorContributorCredit $credit): \Biblio\Core\Application\Metadata\Author\AuthorContributorCredit
    {
        return $this->inner->create($credit);
    }
    public function observeEvidence(\Biblio\Core\Application\Metadata\Author\AuthorCreditEvidence $evidence): \Biblio\Core\Application\Metadata\Author\AuthorMaterializationWriteDisposition
    {
        return $this->inner->observeEvidence($evidence);
    }
    public function setReviewReasonIfVersionMatches(
        AuthorContributorCreditId $creditId,
        \Biblio\Core\Application\Metadata\Author\AuthorContributorCreditVersion $expectedVersion,
        \Biblio\Core\Application\Metadata\Author\AuthorCreditReviewReason $reason,
        \DateTimeImmutable $updatedAt
    ): bool {
        return $this->inner->setReviewReasonIfVersionMatches(
            $creditId,
            $expectedVersion,
            $reason,
            $updatedAt
        );
    }
    public function evidenceForCredit(AuthorContributorCreditId $creditId): array
    {
        return $this->inner->evidenceForCredit($creditId);
    }
}

final readonly class BarrierAuthorRepository implements WritableAuthorRepository
{
    public function __construct(
        private WritableAuthorRepository $inner,
        private OneShotAuthorMaterializationBarrier $barrier
    ) {}
    public function add(Author $author): void { $this->inner->add($author); }
    public function replaceIfVersionMatches(Author $replacement, AuthorVersion $expectedVersion): bool
    {
        return $this->inner->replaceIfVersionMatches($replacement, $expectedVersion);
    }
    public function addContributor(WorkContributor $contributor): void
    {
        $this->inner->addContributor($contributor);
    }
    public function find(AuthorId $authorId): ?Author { return $this->inner->find($authorId); }
    public function findMany(array $authorIds): array { return $this->inner->findMany($authorIds); }
    public function contributorsForWorks(array $workIds): array
    {
        $result = $this->inner->contributorsForWorks($workIds);
        $this->barrier->wait();
        return $result;
    }
    public function workIdsForAuthors(array $authorIds): array
    {
        return $this->inner->workIdsForAuthors($authorIds);
    }
}

final readonly class FixedWorkerAuthorIds implements CanonicalAuthorMaterializationIdGenerator
{
    public function __construct(private string $token) {}
    public function nextAuthorId(): AuthorId { return new AuthorId("author-worker-" . $this->token); }
    public function nextCreditId(): AuthorContributorCreditId
    {
        return new AuthorContributorCreditId("credit-worker-" . $this->token);
    }
}

final readonly class FixedWorkerAuthorClock implements MetadataClock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable("2026-09-13T12:00:00+00:00");
    }
}

runCanonicalAuthorMaterializationWorker($argc, $argv);
