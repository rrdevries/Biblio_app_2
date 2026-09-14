<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitialization;
use Biblio\Core\Application\Metadata\Author\{
    AuthorContributorCreditId,
    AuthorCreditProviderSourceType,
    CanonicalAuthorMaterializationIdGenerator,
    CanonicalAuthorMaterializer,
    ManualAuthorAttempt,
    ManualAuthorAttemptPlan,
    OpenLibraryAuthorId
};
use Biblio\Core\Application\Metadata\Discovery\{
    BibliographicAuthorCredit,
    BibliographicCandidateType,
    BibliographicDiscoveryCandidate,
    BibliographicDiscoveryQuery,
    BibliographicDiscoverySnapshot,
    BibliographicMaterializationIntent
};
use Biblio\Core\Application\Metadata\Search\{
    BibliographicAuthorReference,
    BibliographicTextSearchQuery
};
use Biblio\Core\Application\Metadata\{
    AddBookCommitEvidenceWriter,
    AddBookCommitRequest,
    AddBookCommitSelection,
    AddBookObservedMetadata,
    MetadataCandidate,
    MetadataCandidateId,
    MetadataClock,
    MetadataFieldValue,
    MetadataLookupId,
    MetadataLookupSnapshot,
    MetadataMatchMethod,
    ManualAuthorInput,
    UserObservedMetadataField
};
use Biblio\Core\Catalog\{
    Author,
    AuthorId,
    AuthorVersion,
    CanonicalIsbnIdentity,
    ContributorPosition,
    ContributorRole,
    Isbn13,
    WorkContributor,
    WorkId,
    WritableAuthorRepository
};
use Biblio\Core\Catalog\Classification\{
    LibraryBookTypeId,
    LibraryCatalogSelection
};
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Infrastructure\Persistence\WordPress\{
    WpdbAuthorContributorCreditRepository,
    WpdbBibliographicAuthorWorkSearchProvider,
    WpdbBibliographicProviderIdentityRepository,
    WpdbBibliographicSearchProvider,
    WpdbEditionMetadataProvenanceRepository,
    WpdbMetadataFieldReviewRepository,
    WpdbMetadataLookupSnapshotRepository,
    WpdbUserObservedMetadataEvidenceRepository
};
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Library\LibraryId;
use DateTimeImmutable;
use RuntimeException;
use WP_Error;

final class AddBookAuthorMaterializationTest extends PersistenceIntegrationTestCase
{
    private int $wordpressUserId;
    private LibraryId $libraryId;
    private LibraryBookTypeId $bookTypeId;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(6));
        $user = wp_insert_user([
            "user_login" => "add-book-author-{$suffix}",
            "user_pass" => "integration-test-only",
            "user_email" => "add-book-author-{$suffix}@example.invalid",
        ]);
        if ($user instanceof WP_Error) {
            throw new RuntimeException($user->get_error_message());
        }
        $this->wordpressUserId = $user;
        wp_set_current_user($user);
        $this->libraryId = (new ProductionComposition($this->database))
            ->application()->personalLibraries()->ensure();
        $bookTypeId = $this->database->get_var($this->database->prepare(
            "SELECT book_type_id FROM `{$this->tableNames->libraryBookTypes()}` "
                . "WHERE library_id=%s AND term_status='active' "
                . "ORDER BY display_name,book_type_id LIMIT 1",
            $this->libraryId->value()
        ));
        if (!is_string($bookTypeId)) {
            throw new RuntimeException("Personal Library has no active Book type.");
        }
        $this->bookTypeId = new LibraryBookTypeId($bookTypeId);
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        if (isset($this->wordpressUserId)) {
            $this->database->delete(
                $this->database->usermeta,
                ["user_id" => $this->wordpressUserId]
            );
            $this->database->delete(
                $this->database->users,
                ["ID" => $this->wordpressUserId]
            );
        }
        parent::tearDown();
    }

    public function testProviderCommitMaterializesOrderedStrongAndNameOnlyAuthorsAndReplays(): void
    {
        $identity = $this->identity("9780306406157");
        $credits = [
            $this->credit("Strong Author", 1, "/books/OL101M", "OL101A"),
            $this->credit("Name Only", 3, "/books/OL101M"),
        ];
        $candidate = $this->candidate(
            "open_library",
            "/books/OL101M",
            "Integrated Work",
            $identity,
            $credits
        );
        $lookup = new MetadataLookupId(
            "lookup-10101010101010101010101010101010"
        );
        $this->saveAddBookSnapshot($lookup, $identity, $candidate);
        $application = (new ProductionComposition($this->database))->application();
        $request = $this->candidateRequest($lookup, $candidate, $identity);

        $first = $application->addBookCommit()->commit($this->libraryId, $request);
        $second = $application->addBookCommit()->commit($this->libraryId, $request);

        self::assertSame($first->work()->id()->value(), $second->work()->id()->value());
        self::assertSame($first->edition()->id()->value(), $second->edition()->id()->value());
        self::assertFalse($first->existingEdition());
        self::assertTrue($second->existingEdition());
        self::assertSame(2, $this->tableCount($this->tableNames->items()));
        self::assertSame(2, $this->tableCount($this->tableNames->authors()));
        self::assertSame(2, $this->tableCount($this->tableNames->authorContributorCredits()));
        self::assertSame(2, $this->tableCount($this->tableNames->authorCreditEvidence()));
        self::assertSame(2, $this->tableCount($this->tableNames->workContributors()));
        self::assertSame(1, $this->providerAuthorClaimCount());
        self::assertSame([1, 3], array_map(
            "intval",
            $this->database->get_col(
                "SELECT contributor_position FROM `{$this->tableNames->workContributors()}` "
                    . "ORDER BY contributor_position"
            )
        ));
        self::assertSame([2, 2], array_map(
            "intval",
            $this->database->get_col(
                "SELECT observation_count FROM `{$this->tableNames->authorCreditEvidence()}` "
                    . "ORDER BY credit_id"
            )
        ));
        self::assertSame(
            ["provisional", "resolved"],
            $this->database->get_col(
                "SELECT identity_status FROM `{$this->tableNames->authors()}` "
                    . "ORDER BY identity_status"
            )
        );

        $localSearch = new WpdbBibliographicSearchProvider(
            $this->database,
            $this->tableNames
        );
        $authorPage = $localSearch->searchAuthors(
            new BibliographicTextSearchQuery("Strong Author")
        );
        self::assertCount(1, $authorPage->items());
        $authorId = $authorPage->items()[0]->reference()->authorId();
        self::assertNotNull($authorId);
        $works = (new WpdbBibliographicAuthorWorkSearchProvider(
            $this->database,
            $this->tableNames
        ))->searchWorksForAuthor(
            BibliographicAuthorReference::canonical($authorId),
            0,
            10
        );
        self::assertCount(1, $works->items());
        self::assertSame(
            $first->work()->id()->value(),
            $works->items()[0]->reference()->workId()?->value()
        );

        $observationCounts = $this->database->get_col(
            "SELECT observation_count FROM `{$this->tableNames->authorCreditEvidence()}` "
                . "ORDER BY credit_id"
        );
        $third = $application->addBookCommit()->commit(
            $this->libraryId,
            new AddBookCommitRequest(
                $identity->isbn13()->value(),
                AddBookCommitSelection::existingEdition($first->edition()->id()),
                new AddBookObservedMetadata([]),
                $this->classification()
            )
        );
        self::assertTrue($third->existingEdition());
        self::assertSame(3, $this->tableCount($this->tableNames->items()));
        self::assertSame($observationCounts, $this->database->get_col(
            "SELECT observation_count FROM `{$this->tableNames->authorCreditEvidence()}` "
                . "ORDER BY credit_id"
        ));
    }

    public function testGoogleSameNameOnIndependentWorksCreatesIndependentProvisionalAuthors(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        foreach ([
            ["9780306406157", "volume-one", "lookup-20202020202020202020202020202020"],
            ["9780441172719", "volume-two", "lookup-30303030303030303030303030303030"],
        ] as [$isbn, $record, $lookupValue]) {
            $identity = $this->identity($isbn);
            $candidate = $this->candidate(
                "google_books",
                $record,
                "Work {$record}",
                $identity,
                [$this->credit("Peter King", 1, $record)]
            );
            $lookup = new MetadataLookupId($lookupValue);
            $this->saveAddBookSnapshot($lookup, $identity, $candidate);
            $application->addBookCommit()->commit(
                $this->libraryId,
                $this->candidateRequest($lookup, $candidate, $identity)
            );
        }

        self::assertSame(2, $this->tableCount($this->tableNames->works()));
        self::assertSame(2, $this->tableCount($this->tableNames->authors()));
        self::assertSame(2, $this->tableCount($this->tableNames->workContributors()));
        self::assertSame(0, $this->providerAuthorClaimCount());
        self::assertSame(
            ["provisional", "provisional"],
            $this->database->get_col(
                "SELECT identity_status FROM `{$this->tableNames->authors()}` "
                    . "ORDER BY author_id"
            )
        );
    }

    public function testManualContributorObservationDoesNotFabricateAuthor(): void
    {
        $result = (new ProductionComposition($this->database))->application()
            ->addBookCommit()->commit(
                $this->libraryId,
                new AddBookCommitRequest(
                    null,
                    AddBookCommitSelection::manual(),
                    new AddBookObservedMetadata([
                        UserObservedMetadataField::Title->value =>
                            new MetadataFieldValue("Manual Work"),
                        UserObservedMetadataField::Contributors->value =>
                            new MetadataFieldValue(["Untyped Manual Person"]),
                    ]),
                    $this->classification()
                )
            );

        self::assertSame("Manual Work", $result->edition()->title());
        self::assertSame(1, $this->tableCount($this->tableNames->items()));
        self::assertSame(0, $this->tableCount($this->tableNames->authors()));
        self::assertSame(0, $this->tableCount($this->tableNames->authorContributorCredits()));
        self::assertSame(0, $this->tableCount($this->tableNames->workContributors()));
    }

    public function testManualNewWorkMaterializesOrderedProvisionalAuthorsAndSearchReadsThem(): void
    {
        $beforeSearch = null;
        $result = (new ProductionComposition($this->database))->application()
            ->addBookCommit()->commit(
                $this->libraryId,
                new AddBookCommitRequest(
                    null,
                    AddBookCommitSelection::manual(),
                    new AddBookObservedMetadata([
                        UserObservedMetadataField::Title->value =>
                            new MetadataFieldValue("Manual Author Work"),
                        UserObservedMetadataField::Contributors->value =>
                            new MetadataFieldValue(["Edition Translator"]),
                    ]),
                    $this->classification(),
                    authors: [
                        $this->manualAuthor("  Alpha\u{00A0}Author "),
                        $this->manualAuthor("Béta Author Jr."),
                        $this->manualAuthor("Gamma Author"),
                    ]
                )
            );

        self::assertSame(3, $this->tableCount($this->tableNames->authors()));
        self::assertSame(3, $this->tableCount(
            $this->tableNames->authorContributorCredits()
        ));
        self::assertSame(3, $this->tableCount(
            $this->tableNames->authorCreditEvidence()
        ));
        self::assertSame(3, $this->tableCount(
            $this->tableNames->workContributors()
        ));
        self::assertSame(0, $this->providerAuthorClaimCount());
        self::assertSame(
            ["Alpha Author", "Béta Author Jr.", "Gamma Author"],
            $this->database->get_col(
                "SELECT a.display_name FROM `{$this->tableNames->authors()}` a "
                    . "INNER JOIN `{$this->tableNames->workContributors()}` wc "
                    . "ON wc.author_id=a.author_id "
                    . "ORDER BY wc.contributor_position"
            )
        );
        self::assertSame(
            ["author", "author", "author"],
            $this->database->get_col(
                "SELECT contributor_role FROM `{$this->tableNames->workContributors()}` "
                    . "ORDER BY contributor_position"
            )
        );
        self::assertSame([1, 2, 3], array_map(
            "intval",
            $this->database->get_col(
                "SELECT contributor_position FROM `{$this->tableNames->workContributors()}` "
                    . "ORDER BY contributor_position"
            )
        ));
        self::assertSame(
            ["user_observation", "user_observation", "user_observation"],
            $this->database->get_col(
                "SELECT source_kind FROM `{$this->tableNames->authorCreditEvidence()}` "
                    . "ORDER BY evidence_id"
            )
        );
        self::assertSame(1, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->metadataUserObservations()}` "
                . "WHERE field_key='contributors'"
        ));

        $beforeSearch = $this->authorGraphCounts();
        $authorPage = (new WpdbBibliographicSearchProvider(
            $this->database,
            $this->tableNames
        ))->searchAuthors(new BibliographicTextSearchQuery("Alpha Author"));
        self::assertCount(1, $authorPage->items());
        $authorId = $authorPage->items()[0]->reference()->authorId();
        self::assertNotNull($authorId);
        $works = (new WpdbBibliographicAuthorWorkSearchProvider(
            $this->database,
            $this->tableNames
        ))->searchWorksForAuthor(
            BibliographicAuthorReference::canonical($authorId),
            0,
            10
        );
        self::assertCount(1, $works->items());
        self::assertSame(
            $result->work()->id()->value(),
            $works->items()[0]->reference()->workId()?->value()
        );
        self::assertSame($beforeSearch, $this->authorGraphCounts());
    }

    public function testManualDuplicateNamesRemainIndependentWithinAndAcrossWorks(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        foreach (["First", "Second"] as $title) {
            $application->addBookCommit()->commit(
                $this->libraryId,
                new AddBookCommitRequest(
                    null,
                    AddBookCommitSelection::manual(),
                    new AddBookObservedMetadata([
                        UserObservedMetadataField::Title->value =>
                            new MetadataFieldValue("{$title} Work"),
                    ]),
                    $this->classification(),
                    authors: $title === "First"
                        ? [
                            $this->manualAuthor("Alex Smith"),
                            $this->manualAuthor("Alex Smith"),
                        ]
                        : [$this->manualAuthor("Alex Smith")]
                )
            );
        }

        self::assertSame(2, $this->tableCount($this->tableNames->works()));
        self::assertSame(3, $this->tableCount($this->tableNames->authors()));
        self::assertSame(3, $this->tableCount(
            $this->tableNames->authorContributorCredits()
        ));
        self::assertSame(3, $this->tableCount(
            $this->tableNames->workContributors()
        ));
        self::assertSame(
            ["1", "1,2"],
            $this->database->get_col(
                "SELECT GROUP_CONCAT(contributor_position "
                    . "ORDER BY contributor_position SEPARATOR ',') "
                    . "FROM `{$this->tableNames->workContributors()}` "
                    . "GROUP BY work_id ORDER BY COUNT(*),MIN(contributor_position)"
            )
        );
    }

    public function testManualAuthorsNeverMutateAnExplicitExistingWork(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        $first = $application->addBookCommit()->commit(
            $this->libraryId,
            new AddBookCommitRequest(
                null,
                AddBookCommitSelection::manual(),
                new AddBookObservedMetadata([
                    UserObservedMetadataField::Title->value =>
                        new MetadataFieldValue("Existing Work First Edition"),
                ]),
                $this->classification(),
                authors: [$this->manualAuthor("Author A")]
            )
        );
        $before = $this->authorGraphCounts();

        $second = $application->addBookCommit()->commit(
            $this->libraryId,
            new AddBookCommitRequest(
                null,
                AddBookCommitSelection::manual($first->work()->id()),
                new AddBookObservedMetadata([
                    UserObservedMetadataField::Title->value =>
                        new MetadataFieldValue("Existing Work Second Edition"),
                ]),
                $this->classification(),
                authors: [$this->manualAuthor("Author B")]
            )
        );

        self::assertSame($first->work()->id()->value(), $second->work()->id()->value());
        self::assertNotSame(
            $first->edition()->id()->value(),
            $second->edition()->id()->value()
        );
        self::assertSame($before, $this->authorGraphCounts());
        self::assertSame(
            ["Author A"],
            $this->database->get_col(
                "SELECT display_name FROM `{$this->tableNames->authors()}`"
            )
        );

        $zeroAuthorWork = $application->addBookCommit()->commit(
            $this->libraryId,
            new AddBookCommitRequest(
                null,
                AddBookCommitSelection::manual(),
                new AddBookObservedMetadata([
                    UserObservedMetadataField::Title->value =>
                        new MetadataFieldValue("Zero Author Work"),
                ]),
                $this->classification()
            )
        );
        $beforeZero = $this->authorGraphCounts();
        $application->addBookCommit()->commit(
            $this->libraryId,
            new AddBookCommitRequest(
                null,
                AddBookCommitSelection::manual($zeroAuthorWork->work()->id()),
                new AddBookObservedMetadata([
                    UserObservedMetadataField::Title->value =>
                        new MetadataFieldValue("Zero Author Work Second Edition"),
                ]),
                $this->classification(),
                authors: [$this->manualAuthor("Must Not Attach")]
            )
        );
        self::assertSame($beforeZero, $this->authorGraphCounts());
    }

    public function testManualAuthorsNeverMutateLocalOrSelectedExistingEdition(): void
    {
        $application = (new ProductionComposition($this->database))->application();
        $identity = $this->identity("9780306406157");
        $first = $application->addBookCommit()->commit(
            $this->libraryId,
            new AddBookCommitRequest(
                $identity->isbn13()->value(),
                AddBookCommitSelection::manual(),
                new AddBookObservedMetadata([
                    UserObservedMetadataField::Title->value =>
                        new MetadataFieldValue("Existing Edition"),
                ]),
                $this->classification(),
                authors: [$this->manualAuthor("Author A")]
            )
        );
        $before = $this->authorGraphCounts();

        $local = $application->addBookCommit()->commit(
            $this->libraryId,
            new AddBookCommitRequest(
                $identity->isbn13()->value(),
                AddBookCommitSelection::manual(),
                new AddBookObservedMetadata([
                    UserObservedMetadataField::Title->value =>
                        new MetadataFieldValue("Ignored title"),
                ]),
                $this->classification(),
                authors: [$this->manualAuthor("Author B")]
            )
        );
        $selected = $application->addBookCommit()->commit(
            $this->libraryId,
            new AddBookCommitRequest(
                $identity->isbn13()->value(),
                AddBookCommitSelection::existingEdition($first->edition()->id()),
                new AddBookObservedMetadata([]),
                $this->classification(),
                authors: [$this->manualAuthor("Author C")]
            )
        );

        self::assertTrue($local->existingEdition());
        self::assertTrue($selected->existingEdition());
        self::assertSame($before, $this->authorGraphCounts());
        self::assertSame(3, $this->tableCount($this->tableNames->items()));
    }

    public function testGenericMaterializationAndAddBookReuseExactAuthorCreditAndPreserveConflict(): void
    {
        $identity = $this->identity("9780441172719");
        $credit = $this->credit(
            "Cross Consumer Author",
            1,
            "/books/OL404M",
            "OL404A"
        );
        $query = BibliographicDiscoveryQuery::isbn($identity);
        $genericCandidate = BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalEdition,
            "open_library",
            "/books/OL404M",
            null,
            new DateTimeImmutable("2026-09-14T08:00:00+00:00"),
            MetadataMatchMethod::ExactIsbn,
            $query,
            "Cross Consumer Work",
            $identity,
            null,
            ["Cross Consumer Author"],
            [],
            [],
            null,
            null,
            null,
            0,
            [$credit]
        );
        $discovery = new MetadataLookupId(
            "lookup-40404040404040404040404040404040"
        );
        (new \Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicDiscoverySnapshotRepository(
            $this->database,
            $this->tableNames
        ))->save(new BibliographicDiscoverySnapshot(
            $discovery,
            new UserId((string) $this->wordpressUserId),
            $query,
            new DateTimeImmutable("2026-09-14T08:00:00+00:00"),
            new DateTimeImmutable("2099-09-14T08:30:00+00:00"),
            [$genericCandidate]
        ));
        $application = (new ProductionComposition($this->database))->application();
        $generic = $application->bibliographicMaterialization()->materialize(
            $discovery,
            new MetadataCandidateId($genericCandidate->id()),
            BibliographicMaterializationIntent::WorkAndEdition
        );

        $candidate = $this->candidate(
            "open_library",
            "/books/OL404M",
            "Cross Consumer Work",
            $identity,
            [$credit]
        );
        $lookup = new MetadataLookupId(
            "lookup-50505050505050505050505050505050"
        );
        $this->saveAddBookSnapshot($lookup, $identity, $candidate);
        $added = $application->addBookCommit()->commit(
            $this->libraryId,
            $this->candidateRequest($lookup, $candidate, $identity)
        );

        self::assertTrue($added->existingEdition());
        self::assertSame($generic->work()->id()->value(), $added->work()->id()->value());
        self::assertSame(1, $this->tableCount($this->tableNames->authors()));
        self::assertSame(1, $this->tableCount($this->tableNames->authorContributorCredits()));
        self::assertSame(1, $this->tableCount($this->tableNames->workContributors()));
        self::assertSame(2, (int) $this->database->get_var(
            "SELECT observation_count FROM `{$this->tableNames->authorCreditEvidence()}`"
        ));

        $conflictingCandidate = $this->candidate(
            "google_books",
            "volume-conflict",
            "Cross Consumer Work",
            $identity,
            [$this->credit("Different Person", 1, "volume-conflict")]
        );
        $conflictLookup = new MetadataLookupId(
            "lookup-60606060606060606060606060606060"
        );
        $this->saveAddBookSnapshot(
            $conflictLookup,
            $identity,
            $conflictingCandidate
        );
        $conflict = $application->addBookCommit()->commit(
            $this->libraryId,
            $this->candidateRequest(
                $conflictLookup,
                $conflictingCandidate,
                $identity
            )
        );

        self::assertTrue($conflict->existingEdition());
        self::assertSame(2, $this->tableCount($this->tableNames->items()));
        self::assertSame(1, $this->tableCount($this->tableNames->authors()));
        self::assertSame(1, $this->tableCount($this->tableNames->workContributors()));
        self::assertSame(1, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->authorContributorCredits()}` "
                . "WHERE materialization_status='unresolved' "
                . "AND review_reason='structural_ambiguity'"
        ));
    }

    public function testHardAuthorFailureRollsBackWorkEditionItemAndEvidence(): void
    {
        $candidate = $this->candidate(
            "open_library",
            "/books/OL707M",
            "Rollback Work",
            $this->identity("9780306406157"),
            [$this->credit("Rollback Author", 1, "/books/OL707M", "OL707A")]
        );
        $participant = new AddBookCommitEvidenceWriter(
            new WpdbMetadataFieldReviewRepository($this->database, $this->tableNames),
            new WpdbUserObservedMetadataEvidenceRepository($this->database, $this->tableNames),
            new WpdbEditionMetadataProvenanceRepository($this->database, $this->tableNames),
            new CanonicalAuthorMaterializer(
                new RejectingAddBookAuthorRepository(),
                new WpdbBibliographicProviderIdentityRepository(
                    $this->database,
                    $this->tableNames
                ),
                new WpdbAuthorContributorCreditRepository(
                    $this->database,
                    $this->tableNames
                ),
                new FixedAddBookAuthorIds(),
                new FixedAddBookAuthorClock()
            ),
            new UserId((string) $this->wordpressUserId),
            $this->libraryId,
            new AddBookObservedMetadata([]),
            $candidate,
            new DateTimeImmutable("2026-09-14T08:05:00+00:00")
        );

        try {
            (new ProductionComposition($this->database))->application()
                ->libraryItemCreation()->addWithNewWorkAndEdition(
                    $this->libraryId,
                    new \Biblio\Core\Catalog\ItemId("item-rollback"),
                    new WorkId("work-rollback"),
                    "Rollback Work",
                    new \Biblio\Core\Catalog\EditionId("edition-rollback"),
                    $this->classification(),
                    participant: $participant
                );
            self::fail("Hard Author persistence failure was swallowed.");
        } catch (PersistenceException $exception) {
            self::assertSame(FailureReason::PersistenceWriteFailed, $exception->reason());
        }

        foreach ([
            $this->tableNames->works(),
            $this->tableNames->editions(),
            $this->tableNames->items(),
            $this->tableNames->authors(),
            $this->tableNames->authorContributorCredits(),
            $this->tableNames->authorCreditEvidence(),
            $this->tableNames->workContributors(),
        ] as $table) {
            self::assertSame(0, $this->tableCount($table), $table);
        }
    }

    public function testHardManualAuthorFailureRollsBackCompleteAddBookGraph(): void
    {
        $workId = new WorkId("work-manual-rollback");
        $editionId = new \Biblio\Core\Catalog\EditionId(
            "edition-manual-rollback"
        );
        $participant = new AddBookCommitEvidenceWriter(
            new WpdbMetadataFieldReviewRepository($this->database, $this->tableNames),
            new WpdbUserObservedMetadataEvidenceRepository($this->database, $this->tableNames),
            new WpdbEditionMetadataProvenanceRepository($this->database, $this->tableNames),
            new CanonicalAuthorMaterializer(
                new RejectingAddBookAuthorRepository(),
                new WpdbBibliographicProviderIdentityRepository(
                    $this->database,
                    $this->tableNames
                ),
                new WpdbAuthorContributorCreditRepository(
                    $this->database,
                    $this->tableNames
                ),
                new FixedAddBookAuthorIds(),
                new FixedAddBookAuthorClock()
            ),
            new UserId((string) $this->wordpressUserId),
            $this->libraryId,
            new AddBookObservedMetadata([
                UserObservedMetadataField::Contributors->value =>
                    new MetadataFieldValue(["Edition Translator"]),
            ]),
            null,
            new DateTimeImmutable("2026-09-14T08:05:00+00:00"),
            new ManualAuthorAttemptPlan([new ManualAuthorAttempt(
                "manual-observation-rollback",
                "Rollback Manual Author",
                ContributorRole::Author,
                new ContributorPosition(1)
            )]),
            $workId,
            $editionId
        );

        try {
            (new ProductionComposition($this->database))->application()
                ->libraryItemCreation()->addWithNewWorkAndEdition(
                    $this->libraryId,
                    new \Biblio\Core\Catalog\ItemId("item-manual-rollback"),
                    $workId,
                    "Manual Rollback Work",
                    $editionId,
                    $this->classification(),
                    participant: $participant
                );
            self::fail("Hard manual Author persistence failure was swallowed.");
        } catch (PersistenceException $exception) {
            self::assertSame(FailureReason::PersistenceWriteFailed, $exception->reason());
        }

        foreach ([
            $this->tableNames->works(),
            $this->tableNames->editions(),
            $this->tableNames->items(),
            $this->tableNames->libraryCatalogContexts(),
            $this->tableNames->metadataUserObservations(),
            $this->tableNames->authors(),
            $this->tableNames->authorContributorCredits(),
            $this->tableNames->authorCreditEvidence(),
            $this->tableNames->workContributors(),
        ] as $table) {
            self::assertSame(0, $this->tableCount($table), $table);
        }
    }

    /** @param list<BibliographicAuthorCredit> $credits */
    private function candidate(
        string $provider,
        string $recordId,
        string $title,
        CanonicalIsbnIdentity $identity,
        array $credits
    ): MetadataCandidate {
        return new MetadataCandidate(
            $provider,
            $recordId,
            new DateTimeImmutable("2026-09-14T08:00:00+00:00"),
            MetadataMatchMethod::ExactIsbn,
            $identity,
            $identity,
            $title,
            null,
            array_map(
                static fn (BibliographicAuthorCredit $credit): string =>
                    $credit->observedDisplayName(),
                $credits
            ),
            [],
            [],
            null,
            null,
            null,
            null,
            $credits
        );
    }

    private function credit(
        string $name,
        int $position,
        string $recordId,
        ?string $openLibraryAuthorId = null
    ): BibliographicAuthorCredit {
        return new BibliographicAuthorCredit(
            $name,
            ContributorRole::Author,
            new ContributorPosition($position),
            AuthorCreditProviderSourceType::Edition,
            $recordId,
            $openLibraryAuthorId === null
                ? null : new OpenLibraryAuthorId($openLibraryAuthorId)
        );
    }

    private function saveAddBookSnapshot(
        MetadataLookupId $lookup,
        CanonicalIsbnIdentity $identity,
        MetadataCandidate $candidate
    ): void {
        (new WpdbMetadataLookupSnapshotRepository(
            $this->database,
            $this->tableNames
        ))->save(new MetadataLookupSnapshot(
            $lookup,
            new UserId((string) $this->wordpressUserId),
            $this->libraryId,
            $identity,
            new DateTimeImmutable("2026-09-14T08:00:00+00:00"),
            new DateTimeImmutable("2099-09-14T08:30:00+00:00"),
            [$candidate]
        ));
    }

    private function candidateRequest(
        MetadataLookupId $lookup,
        MetadataCandidate $candidate,
        CanonicalIsbnIdentity $identity
    ): AddBookCommitRequest {
        return new AddBookCommitRequest(
            $identity->isbn13()->value(),
            AddBookCommitSelection::candidate(
                $lookup,
                MetadataCandidateId::fromCandidate($candidate)
            ),
            new AddBookObservedMetadata([]),
            $this->classification()
        );
    }

    private function classification(): LibraryCatalogContextInitialization
    {
        return new LibraryCatalogContextInitialization(
            new LibraryCatalogSelection($this->bookTypeId)
        );
    }

    private function identity(string $isbn): CanonicalIsbnIdentity
    {
        return CanonicalIsbnIdentity::fromIsbn(new Isbn13($isbn));
    }

    private function manualAuthor(string $displayName): ManualAuthorInput
    {
        return ManualAuthorInput::fromDisplayName($displayName)
            ?? throw new RuntimeException("Manual Author fixture is empty.");
    }

    /** @return array<string, int> */
    private function authorGraphCounts(): array
    {
        return [
            "authors" => $this->tableCount($this->tableNames->authors()),
            "credits" => $this->tableCount(
                $this->tableNames->authorContributorCredits()
            ),
            "evidence" => $this->tableCount(
                $this->tableNames->authorCreditEvidence()
            ),
            "edges" => $this->tableCount(
                $this->tableNames->workContributors()
            ),
        ];
    }

    private function tableCount(string $table): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$table}`"
        );
    }

    private function providerAuthorClaimCount(): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->bibliographicProviderIdentities()}` "
                . "WHERE source_entity_type='author' AND target_type='author'"
        );
    }
}

final readonly class RejectingAddBookAuthorRepository implements WritableAuthorRepository
{
    public function add(Author $author): void
    {
        throw new PersistenceException(
            "Injected Add Book Author persistence failure.",
            failureReason: FailureReason::PersistenceWriteFailed
        );
    }

    public function replaceIfVersionMatches(
        Author $replacement,
        AuthorVersion $expectedVersion
    ): bool {
        return false;
    }

    public function addContributor(WorkContributor $contributor): void {}
    public function find(AuthorId $authorId): ?Author { return null; }
    public function findMany(array $authorIds): array { return []; }
    public function contributorsForWorks(array $workIds): array { return []; }
    public function workIdsForAuthors(array $authorIds): array { return []; }
}

final class FixedAddBookAuthorIds implements CanonicalAuthorMaterializationIdGenerator
{
    private int $author = 0;
    private int $credit = 0;

    public function nextAuthorId(): AuthorId
    {
        return new AuthorId("author-rollback-" . ++$this->author);
    }

    public function nextCreditId(): AuthorContributorCreditId
    {
        return new AuthorContributorCreditId(
            "author-credit-rollback-" . ++$this->credit
        );
    }
}

final readonly class FixedAddBookAuthorClock implements MetadataClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-09-14T08:05:00+00:00");
    }
}
