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
    AuthorCreditEvidence,
    AuthorProviderIdentityConflict
};
use Biblio\Core\Catalog\{
    Author,
    AuthorDisplayNameStatus,
    AuthorId,
    AuthorIdentityStatus,
    AuthorVersion,
    ContributorPosition,
    ContributorRole,
    Edition,
    EditionId,
    Work,
    WorkId
};
use Biblio\Core\Infrastructure\Persistence\WordPress\{
    WpdbAuthorContributorCreditRepository,
    WpdbAuthorRepository,
    WpdbBibliographicProviderIdentityRepository,
    WpdbEditionRepository,
    WpdbWorkRepository
};
use DateTimeImmutable;

final class AuthorIdentityPersistenceTest extends PersistenceIntegrationTestCase
{
    public function testAuthorStatesAndVersionedWritesRoundTripWithoutNameUniqueness(): void
    {
        $repository = new WpdbAuthorRepository($this->database, $this->tableNames);
        $repository->add(new Author(new AuthorId("author-1"), "Peter King"));
        $repository->add(new Author(
            new AuthorId("author-2"),
            "Peter King",
            AuthorIdentityStatus::Resolved
        ));
        try {
            $repository->add(new Author(
                new AuthorId("author-invalid-version"),
                "Invalid version",
                AuthorIdentityStatus::Provisional,
                AuthorDisplayNameStatus::Observed,
                new AuthorVersion(2)
            ));
            self::fail("A new Author started above version one.");
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        self::assertSame(
            AuthorIdentityStatus::Provisional,
            $repository->find(new AuthorId("author-1"))?->identityStatus()
        );
        self::assertSame(
            AuthorIdentityStatus::Resolved,
            $repository->find(new AuthorId("author-2"))?->identityStatus()
        );
        self::assertTrue($repository->replaceIfVersionMatches(
            new Author(
                new AuthorId("author-1"),
                "Peter A. King",
                AuthorIdentityStatus::Resolved,
                AuthorDisplayNameStatus::LibrarianConfirmed,
                new AuthorVersion(2)
            ),
            new AuthorVersion(1)
        ));
        self::assertFalse($repository->replaceIfVersionMatches(
            new Author(
                new AuthorId("author-1"),
                "Stale",
                AuthorIdentityStatus::Resolved,
                AuthorDisplayNameStatus::LibrarianConfirmed,
                new AuthorVersion(2)
            ),
            new AuthorVersion(1)
        ));
        self::assertFalse($repository->replaceIfVersionMatches(
            new Author(
                new AuthorId("author-1"),
                "Peter A. King",
                AuthorIdentityStatus::Provisional,
                AuthorDisplayNameStatus::Observed,
                new AuthorVersion(3)
            ),
            new AuthorVersion(2)
        ));
        $stored = $repository->find(new AuthorId("author-1"));
        self::assertSame("Peter A. King", $stored?->displayName());
        self::assertSame(2, $stored?->version()->value());
    }

    public function testProviderAuthorClaimsAreImmutableAndMatrixIsClosed(): void
    {
        [$work, $edition] = $this->seedBibliographicTargets();
        $authors = new WpdbAuthorRepository($this->database, $this->tableNames);
        $authors->add(new Author(new AuthorId("author-a"), "Author A"));
        $authors->add(new Author(new AuthorId("author-b"), "Author B"));
        $repository = new WpdbBibliographicProviderIdentityRepository(
            $this->database,
            $this->tableNames
        );

        $repository->claimAuthor(
            "open_library",
            "/authors/OL1A",
            new AuthorId("author-a")
        );
        $repository->claimAuthor(
            "open_library",
            "/authors/OL1A",
            new AuthorId("author-a")
        );
        self::assertSame(
            "author-a",
            $repository->findAuthor("open_library", "/authors/OL1A")?->value()
        );
        try {
            $repository->claimAuthor(
                "open_library",
                "/authors/OL1A",
                new AuthorId("author-b")
            );
            self::fail("Provider Author claim was reassigned.");
        } catch (AuthorProviderIdentityConflict) {
            self::addToAssertionCount(1);
        }

        $repository->claimWork("open_library", "work", "/works/OL1W", $work);
        $repository->claimWork(
            "open_library",
            "edition",
            "/books/OL1M",
            $work
        );
        $repository->claimEdition("open_library", "/books/OL1M", $edition);
        self::assertSame(4, $this->countRows(
            $this->tableNames->bibliographicProviderIdentities()
        ));

        foreach ([
            ["author", "work", $work->value(), null, null],
            ["author", "edition", null, $edition->value(), null],
            ["work", "author", null, null, "author-a"],
            ["work", "edition", null, $edition->value(), null],
        ] as $offset => [$source, $target, $workId, $editionId, $authorId]) {
            $previous = $this->database->suppress_errors(true);
            try {
                $result = $this->database->insert(
                    $this->tableNames->bibliographicProviderIdentities(),
                    [
                        "provider_key" => "open_library",
                        "source_entity_type" => $source,
                        "provider_record_id" => "/invalid/{$offset}",
                        "target_type" => $target,
                        "work_id" => $workId,
                        "edition_id" => $editionId,
                        "author_id" => $authorId,
                    ]
                );
            } finally {
                $this->database->suppress_errors($previous);
            }
            self::assertFalse($result);
        }
    }

    public function testCreditsUseWhitespaceOnlySourceScopedIdentityAndEvidenceReplay(): void
    {
        [$work] = $this->seedBibliographicTargets();
        $authors = new WpdbAuthorRepository($this->database, $this->tableNames);
        $authors->add(new Author(new AuthorId("credit-author-a"), "É. Sample"));
        $authors->add(new Author(new AuthorId("credit-author-b"), "É. Sample"));
        $repository = new WpdbAuthorContributorCreditRepository(
            $this->database,
            $this->tableNames
        );
        $position = new ContributorPosition(1);
        $source = AuthorContributorCreditSourceIdentity::provider(
            "open_library",
            "work",
            "/works/CREDIT-W",
            1
        );
        $key = AuthorContributorCreditKey::fromSource(
            $work,
            ContributorRole::Author,
            $position,
            "  É.\u{00A0}Sample  ",
            $source
        );
        $now = new DateTimeImmutable("2026-09-13T10:00:00+00:00");
        $first = $this->linkedCredit(
            "credit-1",
            $key,
            $work,
            "  É.\u{00A0}Sample  ",
            "credit-author-a",
            $now
        );

        self::assertSame("credit-1", $repository->create($first)->id()->value());
        $replay = $this->linkedCredit(
            "another-preallocated-id",
            AuthorContributorCreditKey::fromSource(
                $work,
                ContributorRole::Author,
                $position,
                "É. Sample",
                $source
            ),
            $work,
            "É. Sample",
            "credit-author-a",
            $now
        );
        self::assertSame("credit-1", $repository->create($replay)->id()->value());

        $independentSource = AuthorContributorCreditSourceIdentity::provider(
            "open_library",
            "work",
            "/works/OTHER-SOURCE-W",
            1
        );
        $independent = $this->linkedCredit(
            "credit-2",
            AuthorContributorCreditKey::fromSource(
                $work,
                ContributorRole::Author,
                $position,
                "É. Sample",
                $independentSource
            ),
            $work,
            "É. Sample",
            "credit-author-b",
            $now
        );
        self::assertSame("credit-2", $repository->create($independent)->id()->value());
        self::assertSame(2, $this->countRows(
            $this->tableNames->authorContributorCredits()
        ));
        try {
            $repository->create($this->linkedCredit(
                "credit-invalid-version",
                AuthorContributorCreditKey::fromSource(
                    $work,
                    ContributorRole::Author,
                    new ContributorPosition(2),
                    "É. Sample",
                    $source
                ),
                $work,
                "É. Sample",
                "credit-author-a",
                $now,
                2
            ));
            self::fail("A new Author credit started above version one.");
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        $evidence = AuthorCreditEvidence::provider(
            $first->id(),
            "open_library",
            "work",
            "/works/CREDIT-W",
            "/authors/OL1A",
            "  É.\u{00A0}Sample  ",
            ContributorRole::Author,
            $position,
            $now
        );
        $repository->observeEvidence($evidence);
        $repository->observeEvidence($evidence);
        $repository->observeEvidence(AuthorCreditEvidence::provider(
            $first->id(),
            "open_library",
            "work",
            "/works/CREDIT-W",
            "/authors/OL1A",
            "É. Sample",
            ContributorRole::Author,
            $position,
            $now
        ));
        $repository->observeEvidence(AuthorCreditEvidence::userObservation(
            $first->id(),
            "user-observation-1",
            "É. Sample",
            ContributorRole::Author,
            $position,
            $now
        ));
        $repository->observeEvidence(AuthorCreditEvidence::migration(
            $first->id(),
            "migration-observation-1",
            "É. Sample",
            ContributorRole::Author,
            $position,
            $now
        ));
        $observations = $repository->evidenceForCredit($first->id());
        self::assertCount(4, $observations);
        $counts = array_map(
            static fn ($value): int => $value->observationCount(),
            $observations
        );
        sort($counts);
        self::assertSame([1, 1, 1, 2], $counts);
        $traceIds = array_values(array_map(
            static fn (AuthorCreditEvidence $value): string =>
                (string) $value->sourceRecordId(),
            array_filter(
                $observations,
                static fn (AuthorCreditEvidence $value): bool =>
                    $value->sourceKind()
                        !== \Biblio\Core\Application\Metadata\Author\AuthorCreditEvidenceSourceKind::Provider
            )
        ));
        sort($traceIds);
        self::assertSame(
            ["migration-observation-1", "user-observation-1"],
            $traceIds
        );
    }

    /** @return array{WorkId, EditionId} */
    private function seedBibliographicTargets(): array
    {
        $work = new Work(new WorkId("identity-work"), "Identity Work");
        $edition = new Edition(
            new EditionId("identity-edition"),
            $work->id(),
            "Identity Edition"
        );
        (new WpdbWorkRepository($this->database, $this->tableNames))->add($work);
        (new WpdbEditionRepository($this->database, $this->tableNames))->add($edition);
        return [$work->id(), $edition->id()];
    }

    private function linkedCredit(
        string $creditId,
        AuthorContributorCreditKey $key,
        WorkId $workId,
        string $observedName,
        string $authorId,
        DateTimeImmutable $now,
        int $version = 1
    ): AuthorContributorCredit {
        return new AuthorContributorCredit(
            new AuthorContributorCreditId($creditId),
            $key,
            $workId,
            ContributorRole::Author,
            new ContributorPosition(1),
            $observedName,
            new AuthorId($authorId),
            AuthorContributorCreditStatus::Linked,
            null,
            $now,
            $now,
            new AuthorContributorCreditVersion($version)
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$table}`"
        );
    }
}
