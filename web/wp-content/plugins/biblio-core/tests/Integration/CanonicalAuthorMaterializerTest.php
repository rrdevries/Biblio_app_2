<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Metadata\Author\{
    AuthorContributorCredit,
    AuthorContributorCreditId,
    AuthorContributorCreditKey,
    AuthorContributorCreditSourceIdentity,
    AuthorContributorCreditStatus,
    AuthorContributorCreditVersion,
    AuthorCreditProviderSourceType,
    AuthorCreditReviewReason,
    AuthorMaterializationStatus,
    AuthorMaterializationWriteDisposition,
    AuthorProviderClaimRace,
    AuthorProviderIdentityRepository,
    CanonicalAuthorMaterializationIdGenerator,
    CanonicalAuthorMaterializer,
    OpenLibraryAuthorId,
    StrongOpenLibraryAuthorCredit
};
use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Catalog\{
    Author,
    AuthorId,
    AuthorIdentityStatus,
    ContributorPosition,
    ContributorRole,
    Work,
    WorkContributor,
    WorkId,
    WritableAuthorRepository
};
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Infrastructure\Persistence\WordPress\{
    WpdbAuthorContributorCreditRepository,
    WpdbAuthorRepository,
    WpdbBibliographicProviderIdentityRepository,
    WpdbTransactionManager,
    WpdbWorkRepository
};
use DateTimeImmutable;

final class CanonicalAuthorMaterializerTest extends PersistenceIntegrationTestCase
{
    public function testStrongIdentityCreatesResolvedAuthorClaimCreditEvidenceAndEdge(): void
    {
        $this->seedWork("work-one");
        $service = $this->service(["author-one"], ["credit-one"]);

        $result = $this->transaction(fn () => $service
            ->materializeStrongOpenLibraryAuthor($this->input(
                "work-one",
                "/authors/OL111A",
                "/works/OL101W"
            )));

        self::assertSame(AuthorMaterializationStatus::Materialized, $result->status());
        self::assertSame("author-one", $result->authorId()?->value());
        self::assertSame("credit-one", $result->creditId()->value());
        self::assertSame(AuthorMaterializationWriteDisposition::Created, $result->author());
        self::assertSame(AuthorMaterializationWriteDisposition::Created, $result->providerClaim());
        self::assertSame(AuthorMaterializationWriteDisposition::Created, $result->credit());
        self::assertSame(AuthorMaterializationWriteDisposition::Created, $result->contributorEdge());

        $author = $this->authors()->find(new AuthorId("author-one"));
        self::assertSame(AuthorIdentityStatus::Resolved, $author?->identityStatus());
        self::assertSame("Peter King", $author?->displayName());
        self::assertSame(1, $this->rowCount($this->tableNames->authors()));
        self::assertSame(1, $this->rowCount($this->tableNames->bibliographicProviderIdentities()));
        self::assertSame(1, $this->rowCount($this->tableNames->authorContributorCredits()));
        self::assertSame(1, $this->rowCount($this->tableNames->authorCreditEvidence()));
        self::assertSame(1, $this->rowCount($this->tableNames->workContributors()));
    }

    public function testExactReplayReusesEverythingAndObservesEvidenceAgain(): void
    {
        $this->seedWork("work-one");
        $service = $this->service(["author-one"], ["credit-one"]);
        $input = $this->input("work-one", "OL111A", "/works/OL101W");
        $this->transaction(fn () => $service->materializeStrongOpenLibraryAuthor($input));
        $replay = $this->transaction(
            fn () => $service->materializeStrongOpenLibraryAuthor($input)
        );

        self::assertSame(AuthorMaterializationWriteDisposition::Reused, $replay->author());
        self::assertSame(AuthorMaterializationWriteDisposition::Reused, $replay->providerClaim());
        self::assertSame(AuthorMaterializationWriteDisposition::Reused, $replay->credit());
        self::assertSame(AuthorMaterializationWriteDisposition::Reused, $replay->contributorEdge());
        self::assertSame(1, $this->rowCount($this->tableNames->authors()));
        self::assertSame(1, $this->rowCount($this->tableNames->authorContributorCredits()));
        self::assertSame(1, $this->rowCount($this->tableNames->workContributors()));
        self::assertSame(2, (int) $this->database->get_var(
            "SELECT observation_count FROM `{$this->tableNames->authorCreditEvidence()}`"
        ));
    }

    public function testSameProviderAuthorOnSecondWorkReusesCanonicalAuthor(): void
    {
        $this->seedWork("work-one");
        $this->seedWork("work-two");
        $service = $this->service(["author-one"], ["credit-one", "credit-two"]);
        $this->transaction(fn () => $service->materializeStrongOpenLibraryAuthor(
            $this->input("work-one", "OL111A", "/works/OL101W")
        ));
        $second = $this->transaction(fn () => $service
            ->materializeStrongOpenLibraryAuthor($this->input(
                "work-two",
                "/authors/OL111A",
                "/works/OL202W"
            )));

        self::assertSame("author-one", $second->authorId()?->value());
        self::assertSame(AuthorMaterializationWriteDisposition::Reused, $second->author());
        self::assertSame(1, $this->rowCount($this->tableNames->authors()));
        self::assertSame(2, $this->rowCount($this->tableNames->authorContributorCredits()));
        self::assertSame(2, $this->rowCount($this->tableNames->workContributors()));
    }

    public function testSameNameWithDifferentProviderAuthorsCreatesSeparateAuthors(): void
    {
        $this->seedWork("work-one");
        $this->seedWork("work-two");
        $service = $this->service(
            ["author-one", "author-two"],
            ["credit-one", "credit-two"]
        );
        $this->transaction(fn () => $service->materializeStrongOpenLibraryAuthor(
            $this->input("work-one", "OL111A", "/works/OL101W")
        ));
        $this->transaction(fn () => $service->materializeStrongOpenLibraryAuthor(
            $this->input("work-two", "OL222A", "/works/OL202W")
        ));

        self::assertSame(2, $this->rowCount($this->tableNames->authors()));
        self::assertSame(2, $this->rowCount($this->tableNames->bibliographicProviderIdentities()));
    }

    public function testSameProviderAuthorWithDifferentObservedNameKeepsCanonicalName(): void
    {
        $this->seedWork("work-one");
        $service = $this->service(["author-one"], ["credit-one", "credit-two"]);
        $this->transaction(fn () => $service->materializeStrongOpenLibraryAuthor(
            $this->input("work-one", "OL111A", "/works/OL101W", "J. K. Rowling")
        ));
        $result = $this->transaction(fn () => $service
            ->materializeStrongOpenLibraryAuthor($this->input(
                "work-one",
                "OL111A",
                "/works/OL101W",
                "Joanne Rowling"
            )));

        self::assertSame("author-one", $result->authorId()?->value());
        self::assertSame("J. K. Rowling", $this->authors()
            ->find(new AuthorId("author-one"))?->displayName());
        self::assertSame(2, $this->rowCount($this->tableNames->authorContributorCredits()));
        self::assertSame(2, $this->rowCount($this->tableNames->authorCreditEvidence()));
        self::assertSame(1, $this->rowCount($this->tableNames->workContributors()));
    }

    public function testExplicitRolesAndSourcePositionsRemainOrdered(): void
    {
        $this->seedWork("work-one");
        $service = $this->service(
            ["author-two", "author-one"],
            ["credit-two", "credit-one"]
        );
        $this->transaction(fn () => $service->materializeStrongOpenLibraryAuthor(
            $this->input(
                "work-one",
                "OL222A",
                "/works/OL101W",
                "Zulu",
                ContributorRole::CoAuthor,
                2
            )
        ));
        $this->transaction(fn () => $service->materializeStrongOpenLibraryAuthor(
            $this->input(
                "work-one",
                "OL111A",
                "/works/OL101W",
                "Alpha",
                ContributorRole::Author,
                1
            )
        ));

        $edges = $this->authors()->contributorsForWorks([new WorkId("work-one")])[
            "work-one"
        ];
        self::assertSame([1, 2], array_map(
            static fn (WorkContributor $edge): int => $edge->position()->value(),
            $edges
        ));
        self::assertSame(
            [ContributorRole::Author, ContributorRole::CoAuthor],
            array_map(static fn (WorkContributor $edge) => $edge->role(), $edges)
        );
    }

    public function testEditionScopedOpenLibraryEvidenceRetainsExactSourceIdentity(): void
    {
        $this->seedWork("work-one");
        $input = new StrongOpenLibraryAuthorCredit(
            new WorkId("work-one"),
            ContributorRole::Author,
            new ContributorPosition(1),
            "Edition Author",
            new OpenLibraryAuthorId("OL333A"),
            AuthorCreditProviderSourceType::Edition,
            "/books/OL303M",
            new DateTimeImmutable("2026-09-13T11:00:00+00:00")
        );

        $this->transaction(fn () => $this->service(["author-one"], ["credit-one"])
            ->materializeStrongOpenLibraryAuthor($input));
        $evidence = $this->credits()->evidenceForCredit(
            new AuthorContributorCreditId("credit-one")
        );

        self::assertCount(1, $evidence);
        self::assertSame("edition", $evidence[0]->sourceEntityType());
        self::assertSame("/books/OL303M", $evidence[0]->sourceRecordId());
        self::assertSame("/authors/OL333A", $evidence[0]->strongProviderAuthorId());
    }

    public function testOccupiedPositionPreservesUnresolvedEvidenceWithoutNewAuthor(): void
    {
        $this->seedWork("work-one");
        $service = $this->service(
            ["author-one", "must-not-be-used"],
            ["credit-one", "credit-conflict"]
        );
        $this->transaction(fn () => $service->materializeStrongOpenLibraryAuthor(
            $this->input("work-one", "OL111A", "/works/OL101W")
        ));
        $conflict = $this->transaction(fn () => $service
            ->materializeStrongOpenLibraryAuthor($this->input(
                "work-one",
                "OL222A",
                "/works/OL202W",
                "Another Author"
            )));

        self::assertSame(AuthorMaterializationStatus::PositionConflict, $conflict->status());
        self::assertNull($conflict->authorId());
        self::assertSame(1, $this->rowCount($this->tableNames->authors()));
        self::assertSame(1, $this->rowCount($this->tableNames->bibliographicProviderIdentities()));
        self::assertSame(2, $this->rowCount($this->tableNames->authorContributorCredits()));
        self::assertSame("unresolved", $this->database->get_var($this->database->prepare(
            "SELECT materialization_status FROM `{$this->tableNames->authorContributorCredits()}` WHERE credit_id=%s",
            "credit-conflict"
        )));
        self::assertSame("structural_ambiguity", $this->database->get_var(
            $this->database->prepare(
                "SELECT review_reason FROM `{$this->tableNames->authorContributorCredits()}` WHERE credit_id=%s",
                "credit-conflict"
            )
        ));
        self::assertSame(2, $this->rowCount($this->tableNames->authorCreditEvidence()));
    }

    public function testConflictingClaimAndExactCreditPreservesEvidenceAndFailsClosed(): void
    {
        $this->seedWork("work-one");
        $authors = $this->authors();
        $authors->add(new Author(new AuthorId("claimed-author"), "Claimed"));
        $authors->add(new Author(new AuthorId("credit-author"), "Credit"));
        $claims = $this->claims();
        $claims->claimAuthor("open_library", "/authors/OL111A", new AuthorId("claimed-author"));
        $input = $this->input("work-one", "OL111A", "/works/OL101W");
        $key = AuthorContributorCreditKey::fromSource(
            $input->workId(),
            $input->role(),
            $input->position(),
            $input->observedDisplayName(),
            $input->sourceIdentity()
        );
        $now = new DateTimeImmutable("2026-09-13T12:00:00+00:00");
        $this->credits()->create(new AuthorContributorCredit(
            new AuthorContributorCreditId("credit-one"),
            $key,
            new WorkId("work-one"),
            ContributorRole::Author,
            new ContributorPosition(1),
            "Peter King",
            new AuthorId("credit-author"),
            AuthorContributorCreditStatus::Linked,
            null,
            $now,
            $now,
            AuthorContributorCreditVersion::initial()
        ));
        $authors->addContributor(new WorkContributor(
            new WorkId("work-one"),
            new AuthorId("credit-author"),
            ContributorRole::Author,
            new ContributorPosition(1)
        ));

        $result = $this->transaction(fn () => $this->service([], [])
            ->materializeStrongOpenLibraryAuthor($input));

        self::assertSame(AuthorMaterializationStatus::IdentityConflict, $result->status());
        self::assertSame("claimed-author", $result->authorId()?->value());
        self::assertSame(2, $this->rowCount($this->tableNames->authors()));
        self::assertSame(1, $this->rowCount($this->tableNames->authorCreditEvidence()));
        self::assertSame("identity_conflict", $this->database->get_var(
            "SELECT review_reason FROM `{$this->tableNames->authorContributorCredits()}`"
        ));
    }

    public function testStaleClaimReadForNewAuthorBecomesCompleteRetryAndRollsBack(): void
    {
        $this->seedWork("work-one");
        $authors = $this->authors();
        $authors->add(new Author(
            new AuthorId("race-winner"),
            "Winner",
            AuthorIdentityStatus::Resolved
        ));
        $claims = $this->claims();
        $claims->claimAuthor(
            "open_library",
            "/authors/OL111A",
            new AuthorId("race-winner")
        );
        $service = new CanonicalAuthorMaterializer(
            $authors,
            new StaleFirstAuthorClaimRead($claims),
            $this->credits(),
            new SequenceCanonicalAuthorIds(["race-loser"], ["credit-loser"]),
            new FixedAuthorMaterializationClock()
        );

        try {
            $this->transaction(fn () => $service->materializeStrongOpenLibraryAuthor(
                $this->input("work-one", "OL111A", "/works/OL101W")
            ));
            self::fail("Stale claim read did not request a complete retry.");
        } catch (AuthorProviderClaimRace) {
            self::addToAssertionCount(1);
        }

        self::assertSame(1, $this->rowCount($this->tableNames->authors()));
        self::assertNull($authors->find(new AuthorId("race-loser")));
        self::assertSame(0, $this->rowCount($this->tableNames->authorContributorCredits()));
        self::assertSame(0, $this->rowCount($this->tableNames->workContributors()));
    }

    public function testStaleClaimReadForExistingCreditRetainsTypedIdentityConflict(): void
    {
        $this->seedWork("work-one");
        $authors = $this->authors();
        $authors->add(new Author(
            new AuthorId("race-winner"),
            "Winner",
            AuthorIdentityStatus::Resolved
        ));
        $authors->add(new Author(new AuthorId("credit-author"), "Credit"));
        $claims = $this->claims();
        $claims->claimAuthor(
            "open_library",
            "/authors/OL111A",
            new AuthorId("race-winner")
        );
        $input = $this->input("work-one", "OL111A", "/works/OL101W");
        $key = AuthorContributorCreditKey::fromSource(
            $input->workId(),
            $input->role(),
            $input->position(),
            $input->observedDisplayName(),
            $input->sourceIdentity()
        );
        $now = new DateTimeImmutable("2026-09-13T12:00:00+00:00");
        $this->credits()->create(new AuthorContributorCredit(
            new AuthorContributorCreditId("credit-one"),
            $key,
            new WorkId("work-one"),
            ContributorRole::Author,
            new ContributorPosition(1),
            "Peter King",
            new AuthorId("credit-author"),
            AuthorContributorCreditStatus::Linked,
            null,
            $now,
            $now,
            AuthorContributorCreditVersion::initial()
        ));
        $authors->addContributor(new WorkContributor(
            new WorkId("work-one"),
            new AuthorId("credit-author"),
            ContributorRole::Author,
            new ContributorPosition(1)
        ));
        $service = new CanonicalAuthorMaterializer(
            $authors,
            new StaleFirstAuthorClaimRead($claims),
            $this->credits(),
            new SequenceCanonicalAuthorIds([], []),
            new FixedAuthorMaterializationClock()
        );

        $result = $this->transaction(
            fn () => $service->materializeStrongOpenLibraryAuthor($input)
        );

        self::assertSame(AuthorMaterializationStatus::IdentityConflict, $result->status());
        self::assertSame("race-winner", $result->authorId()?->value());
        self::assertSame(1, $this->rowCount($this->tableNames->authorCreditEvidence()));
        self::assertSame("identity_conflict", $this->database->get_var(
            "SELECT review_reason FROM `{$this->tableNames->authorContributorCredits()}`"
        ));
    }

    public function testExactStrongCreditPromotesProvisionalAuthorWithoutRenaming(): void
    {
        $this->seedWork("work-one");
        $authors = $this->authors();
        $authors->add(new Author(new AuthorId("provisional-author"), "Original Name"));
        $input = $this->input(
            "work-one",
            "OL111A",
            "/works/OL101W",
            "Different Observation"
        );
        $key = AuthorContributorCreditKey::fromSource(
            $input->workId(),
            $input->role(),
            $input->position(),
            $input->observedDisplayName(),
            $input->sourceIdentity()
        );
        $now = new DateTimeImmutable("2026-09-13T12:00:00+00:00");
        $this->credits()->create(new AuthorContributorCredit(
            new AuthorContributorCreditId("credit-one"),
            $key,
            new WorkId("work-one"),
            ContributorRole::Author,
            new ContributorPosition(1),
            "Different Observation",
            new AuthorId("provisional-author"),
            AuthorContributorCreditStatus::Linked,
            null,
            $now,
            $now,
            AuthorContributorCreditVersion::initial()
        ));
        $authors->addContributor(new WorkContributor(
            new WorkId("work-one"),
            new AuthorId("provisional-author"),
            ContributorRole::Author,
            new ContributorPosition(1)
        ));

        $this->transaction(fn () => $this->service([], [])
            ->materializeStrongOpenLibraryAuthor($input));

        $stored = $authors->find(new AuthorId("provisional-author"));
        self::assertSame(AuthorIdentityStatus::Resolved, $stored?->identityStatus());
        self::assertSame("Original Name", $stored?->displayName());
        self::assertSame("provisional-author", $this->claims()
            ->findAuthor("open_library", "/authors/OL111A")?->value());
    }

    public function testInvalidOrMissingStrongIdentityCannotReachPersistence(): void
    {
        $this->seedWork("work-one");
        foreach (["", "OL1W", "/books/OL1M", "https://openlibrary.org/authors/OL1A"] as $invalid) {
            try {
                new OpenLibraryAuthorId($invalid);
                self::fail("Invalid Open Library Author identity was accepted.");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(0, $this->rowCount($this->tableNames->authors()));
        self::assertSame(0, $this->rowCount($this->tableNames->authorContributorCredits()));
    }

    public function testUnknownFailureRollsBackWholeOperationWithoutOrphans(): void
    {
        $this->seedWork("work-one");
        $failing = new FailingContributorAuthorRepository($this->authors());
        $service = $this->service(["author-one"], ["credit-one"], $failing);

        try {
            $this->transaction(fn () => $service->materializeStrongOpenLibraryAuthor(
                $this->input("work-one", "OL111A", "/works/OL101W")
            ));
            self::fail("Injected persistence failure was swallowed.");
        } catch (PersistenceException) {
            self::addToAssertionCount(1);
        }

        self::assertSame(0, $this->rowCount($this->tableNames->authors()));
        self::assertSame(0, $this->rowCount($this->tableNames->bibliographicProviderIdentities()));
        self::assertSame(0, $this->rowCount($this->tableNames->authorContributorCredits()));
        self::assertSame(0, $this->rowCount($this->tableNames->authorCreditEvidence()));
        self::assertSame(0, $this->rowCount($this->tableNames->workContributors()));
    }

    private function input(
        string $workId,
        string $authorId,
        string $sourceRecordId,
        string $name = "Peter King",
        ContributorRole $role = ContributorRole::Author,
        int $position = 1
    ): StrongOpenLibraryAuthorCredit {
        return new StrongOpenLibraryAuthorCredit(
            new WorkId($workId),
            $role,
            new ContributorPosition($position),
            $name,
            new OpenLibraryAuthorId($authorId),
            AuthorCreditProviderSourceType::Work,
            $sourceRecordId,
            new DateTimeImmutable("2026-09-13T11:00:00+00:00")
        );
    }

    /** @param list<string> $authorIds @param list<string> $creditIds */
    private function service(
        array $authorIds,
        array $creditIds,
        ?WritableAuthorRepository $authors = null
    ): CanonicalAuthorMaterializer {
        return new CanonicalAuthorMaterializer(
            $authors ?? $this->authors(),
            $this->claims(),
            $this->credits(),
            new SequenceCanonicalAuthorIds($authorIds, $creditIds),
            new FixedAuthorMaterializationClock()
        );
    }

    private function seedWork(string $id): void
    {
        (new WpdbWorkRepository($this->database, $this->tableNames))->add(
            new Work(new WorkId($id), "Fixture {$id}")
        );
    }

    private function authors(): WpdbAuthorRepository
    {
        return new WpdbAuthorRepository($this->database, $this->tableNames);
    }

    private function claims(): WpdbBibliographicProviderIdentityRepository
    {
        return new WpdbBibliographicProviderIdentityRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function credits(): WpdbAuthorContributorCreditRepository
    {
        return new WpdbAuthorContributorCreditRepository(
            $this->database,
            $this->tableNames
        );
    }

    private function transaction(callable $operation): mixed
    {
        return (new WpdbTransactionManager($this->database))->run($operation);
    }

    private function rowCount(string $table): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM `{$table}`");
    }
}

final class SequenceCanonicalAuthorIds implements CanonicalAuthorMaterializationIdGenerator
{
    /** @param list<string> $authors @param list<string> $credits */
    public function __construct(private array $authors, private array $credits) {}

    public function nextAuthorId(): AuthorId
    {
        $value = array_shift($this->authors);
        if (!is_string($value)) { throw new \RuntimeException("Unexpected Author ID request."); }
        return new AuthorId($value);
    }

    public function nextCreditId(): AuthorContributorCreditId
    {
        $value = array_shift($this->credits);
        if (!is_string($value)) { throw new \RuntimeException("Unexpected credit ID request."); }
        return new AuthorContributorCreditId($value);
    }
}

final readonly class FixedAuthorMaterializationClock implements MetadataClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-09-13T12:00:00+00:00");
    }
}

final class StaleFirstAuthorClaimRead implements AuthorProviderIdentityRepository
{
    private bool $firstRead = true;
    public function __construct(private AuthorProviderIdentityRepository $inner) {}
    public function findAuthor(string $provider, string $recordId): ?AuthorId
    {
        if ($this->firstRead) {
            $this->firstRead = false;
            return null;
        }
        return $this->inner->findAuthor($provider, $recordId);
    }
    public function claimAuthor(string $provider, string $recordId, AuthorId $authorId): void
    {
        $this->inner->claimAuthor($provider, $recordId, $authorId);
    }
}

final readonly class FailingContributorAuthorRepository implements WritableAuthorRepository
{
    public function __construct(private WritableAuthorRepository $inner) {}
    public function add(Author $author): void { $this->inner->add($author); }
    public function replaceIfVersionMatches(Author $replacement, \Biblio\Core\Catalog\AuthorVersion $expectedVersion): bool
    {
        return $this->inner->replaceIfVersionMatches($replacement, $expectedVersion);
    }
    public function addContributor(WorkContributor $contributor): void
    {
        throw new PersistenceException("Injected contributor failure.");
    }
    public function find(AuthorId $authorId): ?Author { return $this->inner->find($authorId); }
    public function findMany(array $authorIds): array { return $this->inner->findMany($authorIds); }
    public function contributorsForWorks(array $workIds): array
    {
        return $this->inner->contributorsForWorks($workIds);
    }
    public function workIdsForAuthors(array $authorIds): array
    {
        return $this->inner->workIdsForAuthors($authorIds);
    }
}
