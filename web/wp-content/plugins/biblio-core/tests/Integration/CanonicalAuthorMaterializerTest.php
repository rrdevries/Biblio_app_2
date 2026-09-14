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
    ManualAuthorAttempt,
    ManualAuthorCredit,
    NameOnlyAuthorCredit,
    OpenLibraryAuthorId,
    StrongOpenLibraryAuthorCredit
};
use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Catalog\{
    Author,
    AuthorDisplayNameStatus,
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
    public function testNameOnlyCreditCreatesProvisionalAuthorEvidenceAndOrderedEdge(): void
    {
        $this->seedWork("work-one");
        $result = $this->transaction(fn () => $this->service(
            ["author-one"],
            ["credit-one"]
        )->materializeNameOnlyAuthor($this->nameOnlyInput(
            "work-one",
            "  Peter\tKing  "
        )));

        self::assertSame(AuthorMaterializationStatus::Materialized, $result->status());
        self::assertSame("author-one", $result->authorId()?->value());
        self::assertSame(AuthorIdentityStatus::Provisional, $result->authorIdentityStatus());
        self::assertSame(AuthorMaterializationWriteDisposition::Created, $result->author());
        self::assertSame(AuthorMaterializationWriteDisposition::NotWritten, $result->providerClaim());
        self::assertSame(AuthorMaterializationWriteDisposition::Created, $result->credit());
        self::assertSame(AuthorMaterializationWriteDisposition::Created, $result->evidence());
        self::assertSame(AuthorMaterializationWriteDisposition::Created, $result->contributorEdge());

        $author = $this->authors()->find(new AuthorId("author-one"));
        self::assertSame("Peter King", $author?->displayName());
        self::assertSame(AuthorIdentityStatus::Provisional, $author?->identityStatus());
        self::assertSame(AuthorDisplayNameStatus::Observed, $author?->displayNameStatus());
        self::assertSame(0, $this->rowCount($this->tableNames->bibliographicProviderIdentities()));
        $edge = $this->authors()->contributorsForWorks([new WorkId("work-one")])[
            "work-one"
        ][0];
        self::assertSame(ContributorRole::Author, $edge->role());
        self::assertSame(1, $edge->position()->value());
    }

    public function testNameOnlyExplicitCoAuthorRoleAndPositionArePreserved(): void
    {
        $this->seedWork("work-one");
        $this->transaction(fn () => $this->service(
            ["author-one"],
            ["credit-one"]
        )->materializeNameOnlyAuthor($this->nameOnlyInput(
            "work-one",
            "Co Author",
            "volume-coauthor",
            ContributorRole::CoAuthor,
            2
        )));

        $edge = $this->authors()->contributorsForWorks([new WorkId("work-one")])[
            "work-one"
        ][0];
        self::assertSame(ContributorRole::CoAuthor, $edge->role());
        self::assertSame(2, $edge->position()->value());
    }

    public function testNameOnlyExactReplayReusesAuthorCreditEdgeAndEvidence(): void
    {
        $this->seedWork("work-one");
        $service = $this->service(["author-one"], ["credit-one"]);
        $input = $this->nameOnlyInput("work-one");
        $first = $this->transaction(
            fn () => $service->materializeNameOnlyAuthor($input)
        );
        $replay = $this->transaction(
            fn () => $service->materializeNameOnlyAuthor($input)
        );

        self::assertSame($first->authorId()?->value(), $replay->authorId()?->value());
        self::assertSame($first->creditId()->value(), $replay->creditId()->value());
        self::assertSame(AuthorMaterializationWriteDisposition::Reused, $replay->author());
        self::assertSame(AuthorMaterializationWriteDisposition::Reused, $replay->credit());
        self::assertSame(AuthorMaterializationWriteDisposition::Reused, $replay->evidence());
        self::assertSame(AuthorMaterializationWriteDisposition::Reused, $replay->contributorEdge());
        self::assertSame(1, $this->rowCount($this->tableNames->authors()));
        self::assertSame(1, $this->rowCount($this->tableNames->authorContributorCredits()));
        self::assertSame(1, $this->rowCount($this->tableNames->authorCreditEvidence()));
        self::assertSame(2, (int) $this->database->get_var(
            "SELECT observation_count FROM `{$this->tableNames->authorCreditEvidence()}`"
        ));
    }

    public function testManualObservationExactReplayReusesAuthorCreditAndEdge(): void
    {
        $this->seedWork("work-manual");
        $service = $this->service(["author-manual"], ["credit-manual"]);
        $attempt = new ManualAuthorAttempt(
            "manual-observation-stable",
            "Manual Author",
            ContributorRole::Author,
            new ContributorPosition(1)
        );
        $input = new ManualAuthorCredit(
            new WorkId("work-manual"),
            $attempt,
            new DateTimeImmutable("2026-09-14T10:00:00+00:00")
        );

        $first = $this->transaction(
            fn () => $service->materializeNameOnlyAuthor($input)
        );
        $replay = $this->transaction(
            fn () => $service->materializeNameOnlyAuthor($input)
        );

        self::assertSame($first->authorId()?->value(), $replay->authorId()?->value());
        self::assertSame($first->creditId()->value(), $replay->creditId()->value());
        self::assertSame(1, $this->rowCount($this->tableNames->authors()));
        self::assertSame(1, $this->rowCount(
            $this->tableNames->authorContributorCredits()
        ));
        self::assertSame(1, $this->rowCount(
            $this->tableNames->authorCreditEvidence()
        ));
        self::assertSame(1, $this->rowCount(
            $this->tableNames->workContributors()
        ));
        self::assertSame(2, (int) $this->database->get_var(
            "SELECT observation_count FROM `{$this->tableNames->authorCreditEvidence()}`"
        ));
        self::assertSame("user_observation", $this->database->get_var(
            "SELECT source_kind FROM `{$this->tableNames->authorCreditEvidence()}`"
        ));
    }

    public function testNameOnlyWhitespaceNormalizationReusesCreditWithoutRewritingEvidence(): void
    {
        $this->seedWork("work-one");
        $service = $this->service(["author-one"], ["credit-one"]);
        $this->transaction(fn () => $service->materializeNameOnlyAuthor(
            $this->nameOnlyInput("work-one", "Peter King")
        ));
        $replay = $this->transaction(fn () => $service->materializeNameOnlyAuthor(
            $this->nameOnlyInput("work-one", "  Peter\n King ")
        ));

        self::assertSame("author-one", $replay->authorId()?->value());
        self::assertSame("credit-one", $replay->creditId()->value());
        self::assertSame(1, $this->rowCount($this->tableNames->authors()));
        self::assertSame(1, $this->rowCount($this->tableNames->authorContributorCredits()));
        self::assertSame(2, $this->rowCount($this->tableNames->authorCreditEvidence()));
        self::assertSame("Peter King", $this->authors()
            ->find(new AuthorId("author-one"))?->displayName());
    }

    public function testNameOnlySameNameOnDifferentWorksCreatesIndependentAuthors(): void
    {
        $this->seedWork("work-one");
        $this->seedWork("work-two");
        $service = $this->service(
            ["author-one", "author-two"],
            ["credit-one", "credit-two"]
        );
        $first = $this->transaction(fn () => $service->materializeNameOnlyAuthor(
            $this->nameOnlyInput("work-one", "Peter King", "volume-shared")
        ));
        $second = $this->transaction(fn () => $service->materializeNameOnlyAuthor(
            $this->nameOnlyInput("work-two", "Peter King", "volume-shared")
        ));

        self::assertNotSame($first->authorId()?->value(), $second->authorId()?->value());
        self::assertSame(2, $this->rowCount($this->tableNames->authors()));
        self::assertSame(2, $this->rowCount($this->tableNames->authorContributorCredits()));
        self::assertSame(2, $this->rowCount($this->tableNames->workContributors()));
    }

    public function testIndependentSameNameSourceAtOccupiedPositionDoesNotReuseAuthor(): void
    {
        $this->seedWork("work-one");
        $service = $this->service(
            ["author-one", "must-not-be-used"],
            ["credit-one", "credit-two"]
        );
        $this->transaction(fn () => $service->materializeNameOnlyAuthor(
            $this->nameOnlyInput("work-one", "Peter King", "volume-one")
        ));
        $conflict = $this->transaction(fn () => $service->materializeNameOnlyAuthor(
            $this->nameOnlyInput("work-one", "Peter King", "volume-two")
        ));

        self::assertSame(AuthorMaterializationStatus::PositionConflict, $conflict->status());
        self::assertNull($conflict->authorId());
        self::assertSame(1, $this->rowCount($this->tableNames->authors()));
        self::assertSame(2, $this->rowCount($this->tableNames->authorContributorCredits()));
        self::assertSame(2, $this->rowCount($this->tableNames->authorCreditEvidence()));
        self::assertSame(1, $this->rowCount($this->tableNames->workContributors()));
    }

    public function testExistingCreditWithAuthorAtAnotherPositionFailsClosed(): void
    {
        $this->seedWork("work-one");
        $authors = $this->authors();
        $authors->add(new Author(new AuthorId("author-one"), "Peter King"));
        $input = $this->nameOnlyInput("work-one", position: 2);
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
            new ContributorPosition(2),
            "Peter King",
            new AuthorId("author-one"),
            AuthorContributorCreditStatus::Linked,
            null,
            $now,
            $now,
            AuthorContributorCreditVersion::initial()
        ));
        $authors->addContributor(new WorkContributor(
            new WorkId("work-one"),
            new AuthorId("author-one"),
            ContributorRole::Author,
            new ContributorPosition(1)
        ));

        $result = $this->transaction(fn () => $this->service([], [])
            ->materializeNameOnlyAuthor($input));

        self::assertSame(AuthorMaterializationStatus::PositionConflict, $result->status());
        self::assertSame("author-one", $result->authorId()?->value());
        self::assertSame(AuthorIdentityStatus::Provisional, $result->authorIdentityStatus());
        self::assertSame("structural_ambiguity", $this->database->get_var(
            "SELECT review_reason FROM `{$this->tableNames->authorContributorCredits()}`"
        ));
        self::assertSame(1, $this->rowCount($this->tableNames->authors()));
        self::assertSame(1, $this->rowCount($this->tableNames->workContributors()));
    }

    public function testSameNameAtDifferentPositionCreatesDistinctCreditAndAuthor(): void
    {
        $this->seedWork("work-one");
        $service = $this->service(
            ["author-one", "author-two"],
            ["credit-one", "credit-two"]
        );
        $this->transaction(fn () => $service->materializeNameOnlyAuthor(
            $this->nameOnlyInput("work-one", "Peter King", "volume-one", position: 1)
        ));
        $this->transaction(fn () => $service->materializeNameOnlyAuthor(
            $this->nameOnlyInput("work-one", "Peter King", "volume-one", position: 2)
        ));

        self::assertSame(2, $this->rowCount($this->tableNames->authors()));
        self::assertSame(2, $this->rowCount($this->tableNames->authorContributorCredits()));
        self::assertSame(2, $this->rowCount($this->tableNames->workContributors()));
    }

    public function testNameDifferencesRemainCreditSignificantWithoutNameReuse(): void
    {
        $this->seedWork("work-one");
        $service = $this->service(
            ["author-one"],
            ["credit-one", "credit-two", "credit-three", "credit-four"]
        );
        foreach (["Peter King", "peter king", "Péter King", "Peter-King"] as $name) {
            $this->transaction(fn () => $service->materializeNameOnlyAuthor(
                $this->nameOnlyInput("work-one", $name, "volume-one")
            ));
        }

        self::assertSame(1, $this->rowCount($this->tableNames->authors()));
        self::assertSame(4, $this->rowCount($this->tableNames->authorContributorCredits()));
        self::assertSame(4, $this->rowCount($this->tableNames->authorCreditEvidence()));
        self::assertSame(3, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->authorContributorCredits()}` "
                . "WHERE materialization_status='unresolved'"
        ));
    }

    public function testNameOnlyReplayOfStrongCreditReusesResolvedAuthorAndClaim(): void
    {
        $this->seedWork("work-one");
        $service = $this->service(["author-one"], ["credit-one"]);
        $this->transaction(fn () => $service->materializeStrongOpenLibraryAuthor(
            $this->input("work-one", "OL111A", "/works/OL101W")
        ));
        $replay = $this->transaction(fn () => $service->materializeNameOnlyAuthor(
            $this->nameOnlyInput(
                "work-one",
                "Peter King",
                "/works/OL101W",
                provider: "open_library"
            )
        ));

        self::assertSame("author-one", $replay->authorId()?->value());
        self::assertSame(AuthorIdentityStatus::Resolved, $replay->authorIdentityStatus());
        self::assertSame(AuthorMaterializationWriteDisposition::NotWritten, $replay->providerClaim());
        self::assertSame(AuthorIdentityStatus::Resolved, $this->authors()
            ->find(new AuthorId("author-one"))?->identityStatus());
        self::assertSame("author-one", $this->claims()
            ->findAuthor("open_library", "/authors/OL111A")?->value());
        self::assertSame(1, $this->rowCount($this->tableNames->authors()));
        self::assertSame(1, $this->rowCount($this->tableNames->bibliographicProviderIdentities()));
    }

    public function testExistingStrongPathPromotesExactNameOnlyCreditInPlace(): void
    {
        $this->seedWork("work-one");
        $service = $this->service(["author-one"], ["credit-one"]);
        $nameOnly = $this->transaction(fn () => $service->materializeNameOnlyAuthor(
            $this->nameOnlyInput(
                "work-one",
                "Peter King",
                "/works/OL101W",
                provider: "open_library"
            )
        ));
        self::assertSame(AuthorIdentityStatus::Provisional, $nameOnly->authorIdentityStatus());

        $strong = $this->transaction(fn () => $service
            ->materializeStrongOpenLibraryAuthor($this->input(
                "work-one",
                "OL111A",
                "/works/OL101W"
            )));

        self::assertSame("author-one", $strong->authorId()?->value());
        self::assertSame("credit-one", $strong->creditId()->value());
        self::assertSame(AuthorIdentityStatus::Resolved, $strong->authorIdentityStatus());
        self::assertSame(AuthorMaterializationWriteDisposition::Created, $strong->providerClaim());
        self::assertSame(1, $this->rowCount($this->tableNames->authors()));
        self::assertSame(1, $this->rowCount($this->tableNames->authorContributorCredits()));
        self::assertSame(1, $this->rowCount($this->tableNames->workContributors()));
    }

    public function testNameOnlyPathDoesNotConsultOrWriteProviderAuthorClaims(): void
    {
        $this->seedWork("work-one");
        $service = new CanonicalAuthorMaterializer(
            $this->authors(),
            new RejectingAuthorClaimRepository(),
            $this->credits(),
            new SequenceCanonicalAuthorIds(["author-one"], ["credit-one"]),
            new FixedAuthorMaterializationClock()
        );

        $this->transaction(fn () => $service->materializeNameOnlyAuthor(
            $this->nameOnlyInput("work-one")
        ));
        self::assertSame(1, $this->rowCount($this->tableNames->authors()));
        self::assertSame(0, $this->rowCount($this->tableNames->bibliographicProviderIdentities()));
    }

    public function testNameOnlyStaleCreditReadRequestsWholeOperationRetry(): void
    {
        $this->seedWork("work-one");
        $authors = $this->authors();
        $authors->add(new Author(new AuthorId("race-winner"), "Peter King"));
        $input = $this->nameOnlyInput("work-one");
        $key = AuthorContributorCreditKey::fromSource(
            $input->workId(),
            $input->role(),
            $input->position(),
            $input->observedDisplayName(),
            $input->sourceIdentity()
        );
        $now = new DateTimeImmutable("2026-09-13T12:00:00+00:00");
        $credits = $this->credits();
        $credits->create(new AuthorContributorCredit(
            new AuthorContributorCreditId("credit-winner"),
            $key,
            new WorkId("work-one"),
            ContributorRole::Author,
            new ContributorPosition(1),
            "Peter King",
            new AuthorId("race-winner"),
            AuthorContributorCreditStatus::Linked,
            null,
            $now,
            $now,
            AuthorContributorCreditVersion::initial()
        ));
        $service = new CanonicalAuthorMaterializer(
            $authors,
            $this->claims(),
            new StaleFirstAuthorCreditRead($credits),
            new SequenceCanonicalAuthorIds(["race-loser"], ["credit-loser"]),
            new FixedAuthorMaterializationClock()
        );

        try {
            $this->transaction(fn () => $service->materializeNameOnlyAuthor($input));
            self::fail("Stale credit read did not request a complete retry.");
        } catch (\Biblio\Core\Application\Metadata\Author\AuthorContributorCreditRace) {
            self::addToAssertionCount(1);
        }

        self::assertNull($authors->find(new AuthorId("race-loser")));
        self::assertSame(1, $this->rowCount($this->tableNames->authors()));
        self::assertSame(1, $this->rowCount($this->tableNames->authorContributorCredits()));
        self::assertSame(0, $this->rowCount($this->tableNames->authorCreditEvidence()));
        self::assertSame(0, $this->rowCount($this->tableNames->workContributors()));

        $retry = $this->transaction(fn () => $this->service([], [])
            ->materializeNameOnlyAuthor($input));
        self::assertSame("race-winner", $retry->authorId()?->value());
        self::assertSame(1, $this->rowCount($this->tableNames->workContributors()));
    }

    public function testInvalidNameOnlyInputCannotCreateAuthor(): void
    {
        $this->seedWork("work-one");
        foreach (["", " \t\n "] as $name) {
            try {
                $this->nameOnlyInput("work-one", $name);
                self::fail("Invalid name-only Author credit was accepted.");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
        try {
            ContributorRole::from("editor");
            self::fail("Invalid contributor role was accepted.");
        } catch (\ValueError) {
            self::addToAssertionCount(1);
        }
        try {
            $this->nameOnlyInput("work-one", position: 0);
            self::fail("Invalid contributor position was accepted.");
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }
        try {
            $this->nameOnlyInput("work-one", sourceRecordId: "");
            self::fail("Invalid source identity was accepted.");
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, $this->rowCount($this->tableNames->authors()));
        self::assertSame(0, $this->rowCount($this->tableNames->authorContributorCredits()));
    }

    public function testNameOnlyUnknownFailureRollsBackWithoutOrphanAuthor(): void
    {
        $this->seedWork("work-one");
        $service = $this->service(
            ["author-one"],
            ["credit-one"],
            new FailingContributorAuthorRepository($this->authors())
        );

        try {
            $this->transaction(fn () => $service->materializeNameOnlyAuthor(
                $this->nameOnlyInput("work-one")
            ));
            self::fail("Injected persistence failure was swallowed.");
        } catch (PersistenceException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(0, $this->rowCount($this->tableNames->authors()));
        self::assertSame(0, $this->rowCount($this->tableNames->authorContributorCredits()));
        self::assertSame(0, $this->rowCount($this->tableNames->authorCreditEvidence()));
        self::assertSame(0, $this->rowCount($this->tableNames->workContributors()));
    }

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

    private function nameOnlyInput(
        string $workId,
        string $name = "Peter King",
        string $sourceRecordId = "volume-one",
        ContributorRole $role = ContributorRole::Author,
        int $position = 1,
        string $provider = "google_books"
    ): NameOnlyAuthorCredit {
        return new NameOnlyAuthorCredit(
            new WorkId($workId),
            $role,
            new ContributorPosition($position),
            $name,
            $provider,
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

final readonly class RejectingAuthorClaimRepository implements AuthorProviderIdentityRepository
{
    public function findAuthor(string $provider, string $recordId): ?AuthorId
    {
        throw new \LogicException("Name-only materialization consulted provider claims.");
    }

    public function claimAuthor(
        string $provider,
        string $recordId,
        AuthorId $authorId
    ): void {
        throw new \LogicException("Name-only materialization wrote a provider claim.");
    }
}

final class StaleFirstAuthorCreditRead implements
    \Biblio\Core\Application\Metadata\Author\AuthorContributorCreditRepository
{
    private bool $firstRead = true;

    public function __construct(
        private \Biblio\Core\Application\Metadata\Author\AuthorContributorCreditRepository $inner
    ) {
    }

    public function findByKey(
        AuthorContributorCreditKey $key
    ): ?AuthorContributorCredit {
        if ($this->firstRead) {
            $this->firstRead = false;
            return null;
        }
        return $this->inner->findByKey($key);
    }

    public function create(
        AuthorContributorCredit $credit
    ): AuthorContributorCredit {
        return $this->inner->create($credit);
    }

    public function observeEvidence(
        \Biblio\Core\Application\Metadata\Author\AuthorCreditEvidence $evidence
    ): AuthorMaterializationWriteDisposition {
        return $this->inner->observeEvidence($evidence);
    }

    public function setReviewReasonIfVersionMatches(
        AuthorContributorCreditId $creditId,
        AuthorContributorCreditVersion $expectedVersion,
        AuthorCreditReviewReason $reason,
        DateTimeImmutable $updatedAt
    ): bool {
        return $this->inner->setReviewReasonIfVersionMatches(
            $creditId,
            $expectedVersion,
            $reason,
            $updatedAt
        );
    }

    public function evidenceForCredit(
        AuthorContributorCreditId $creditId
    ): array {
        return $this->inner->evidenceForCredit($creditId);
    }
}
