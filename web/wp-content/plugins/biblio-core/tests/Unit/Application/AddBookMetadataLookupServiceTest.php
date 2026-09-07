<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Catalog\LocalEditionResolutionType;
use Biblio\Core\Application\Catalog\LocalEditionResolver;
use Biblio\Core\Application\Catalog\Read\BibliographicRelationshipQueryService;
use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\ActorLibraryContext;
use Biblio\Core\Application\Library\ActorLibraryContextRepository;
use Biblio\Core\Application\Library\LibraryContextQueryService;
use Biblio\Core\Application\Metadata\{AddBookExistingItem,AddBookExistingItemRepository,AddBookMetadataLookupResult,AddBookMetadataLookupService,AddBookMetadataReviewPolicy,CandidateClassifier,FirstSufficientMetadataLookupService,MetadataCandidate,MetadataCandidateId,MetadataClock,MetadataLookupId,MetadataLookupIdGenerator,MetadataLookupSnapshot,MetadataLookupSnapshotRepository,MetadataMatchMethod,MetadataProvider,MetadataWorkLink,ProviderFailureReason,ProviderLookupResult};
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\{Author,AuthorId,AuthorRepository,BibliographicMetadataRepository,CanonicalIsbnIdentity,ContributorPosition,ContributorRole,Edition,EditionId,EditionIdentifierClaimRepository,EditionIsbnMetadata,EditionRepository,InventoryNumber,Isbn13,IsbnCanonicalizer,ItemId,LibraryLocation,LocationId,SeriesRepository,Work,WorkContributor,WorkId,WorkRepository};
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\WordPress\Rest\{CatalogCursorCodec,PrivateNoteCursorCodec,ReadingHistoryCursorCodec,RestResponseSerializer};
use Biblio\Core\Library\{Library,LibraryId,LibraryMembership,LibraryMembershipAssignment};
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AddBookMetadataLookupServiceTest extends TestCase
{
    private AddBookRecordingSnapshotRepository $snapshots;

    public function testExistingEditionIsReturnedBeforeAnyProviderCall(): void
    {
        $identity = $this->identity();
        $edition = new Edition(
            new EditionId("edition-existing"),
            new WorkId("work-existing"),
            "Concrete titel",
            $identity->metadata()
        );
        $claims = $this->createMock(EditionIdentifierClaimRepository::class);
        $claims->expects(self::once())
            ->method("findByCanonicalIsbn13")
            ->willReturn($edition->id());
        $editions = $this->createMock(EditionRepository::class);
        $editions->expects(self::once())->method("find")->willReturn($edition);
        $legacy = $this->createMock(BibliographicMetadataRepository::class);
        $legacy->expects(self::never())->method("editionsForIsbns");
        $works = $this->createMock(WorkRepository::class);
        $works->expects(self::once())->method("find")->willReturn(
            new Work($edition->workId(), "Abstracte titel")
        );
        $primary = new AddBookCountingMetadataProvider(
            "open_library",
            ProviderLookupResult::miss()
        );
        $fallback = new AddBookCountingMetadataProvider(
            "google_books",
            ProviderLookupResult::miss()
        );
        $existingItems = $this->createMock(
            AddBookExistingItemRepository::class
        );
        $existingItems->expects(self::once())
            ->method("forEditionsInLibrary")
            ->with(
                self::callback(static fn (LibraryId $id): bool =>
                    $id->value() === "library-a"),
                self::callback(static fn (array $ids): bool =>
                    count($ids) === 1
                    && $ids[0] instanceof EditionId
                    && $ids[0]->equals($edition->id()))
            )
            ->willReturn([
                "edition-existing" => [
                    new AddBookExistingItem(
                        new ItemId("item-existing"),
                        new InventoryNumber("INV-1"),
                        new LibraryLocation(
                            new LocationId("location-a"),
                            new LibraryId("library-a"),
                            "Kast A"
                        )
                    ),
                ],
            ]);
        $authors = $this->createStub(AuthorRepository::class);
        $authors->method("contributorsForWorks")->willReturn([
            "work-existing" => [new WorkContributor(
                $edition->workId(),
                new AuthorId("author-existing"),
                ContributorRole::Author,
                new ContributorPosition(1)
            )],
        ]);
        $authors->method("findMany")->willReturn([
            "author-existing" => new Author(
                new AuthorId("author-existing"),
                "Auteur Naam"
            ),
        ]);
        $service = $this->service(
            LibraryMembership::owner(),
            $claims,
            $editions,
            $legacy,
            $works,
            $primary,
            $fallback,
            $existingItems,
            $authors
        );

        $result = $service->lookup(
            new LibraryId("library-a"),
            "0-306-40615-2"
        );

        self::assertSame(
            LocalEditionResolutionType::LocalExact,
            $result->localStatus()
        );
        self::assertNull($result->metadataResult());
        self::assertSame("edition-existing", $result->localMatches()[0]
            ->edition()->id()->value());
        $serialized = $this->serializer()->addBookMetadataLookup($result);
        self::assertSame("Concrete titel", $serialized["local_matches"][0]
            ["edition_title"]);
        self::assertSame("Auteur Naam", $serialized["local_matches"][0]
            ["authors"][0]["display_name"]);
        self::assertSame("9780306406157", $serialized["local_matches"][0]
            ["canonical_isbn"]);
        self::assertArrayNotHasKey("cover", $serialized["local_matches"][0]);
        self::assertArrayNotHasKey("language", $serialized["local_matches"][0]);
        self::assertArrayNotHasKey("publisher", $serialized["local_matches"][0]);
        self::assertArrayNotHasKey(
            "publication_date",
            $serialized["local_matches"][0]
        );
        self::assertSame(1, $serialized["local_matches"][0]
            ["existing_item_count"]);
        self::assertSame("item-existing", $serialized["local_matches"][0]
            ["existing_items"][0]["item_id"]);
        self::assertSame("INV-1", $serialized["local_matches"][0]
            ["existing_items"][0]["inventory_number"]);
        self::assertSame("Kast A", $serialized["local_matches"][0]
            ["existing_items"][0]["location"]["display_name"]);
        self::assertSame(0, $primary->calls());
        self::assertSame(0, $fallback->calls());
    }

    public function testViewOnlyActorIsDeniedBeforeLocalOrProviderLookup(): void
    {
        $claims = $this->createMock(EditionIdentifierClaimRepository::class);
        $claims->expects(self::never())->method("findByCanonicalIsbn13");
        $editions = $this->createStub(EditionRepository::class);
        $legacy = $this->createStub(BibliographicMetadataRepository::class);
        $works = $this->createStub(WorkRepository::class);
        $primary = new AddBookCountingMetadataProvider(
            "open_library",
            ProviderLookupResult::miss()
        );
        $fallback = new AddBookCountingMetadataProvider(
            "google_books",
            ProviderLookupResult::miss()
        );
        $service = $this->service(
            LibraryMembership::safeDefault(),
            $claims,
            $editions,
            $legacy,
            $works,
            $primary,
            $fallback
        );

        try {
            $service->lookup(new LibraryId("library-a"), "9780306406157");
            self::fail("View-only Add Book lookup should be denied.");
        } catch (AuthorizationException) {
            self::assertSame(0, $primary->calls());
            self::assertSame(0, $fallback->calls());
        }
    }

    public function testNewIsbnUsesFirstSufficientHubAndSerializesReviewPolicy(): void
    {
        $primary = new AddBookCountingMetadataProvider(
            "open_library",
            ProviderLookupResult::candidates([$this->candidate("ol-record")])
        );
        $fallback = new AddBookCountingMetadataProvider(
            "google_books",
            ProviderLookupResult::miss()
        );
        $result = $this->newIsbnLookup($primary, $fallback);
        $data = $this->serializer()->addBookMetadataLookup($result);

        self::assertSame("single_candidate", $data["status"]);
        self::assertSame("9780306406157", $data["identifier"]["isbn_13"]);
        self::assertSame("lookup-00000000000000000000000000000001", $data["lookup_id"]);
        self::assertCount(1, $this->snapshots->saved);
        self::assertSame(
            "2026-09-06T10:30:00+00:00",
            $this->snapshots->saved[0]->expiresAt()->format("c")
        );
        self::assertSame([], $data["local_matches"]);
        self::assertCount(1, $data["candidates"]);
        self::assertSame("sufficient", $data["candidates"][0]["quality"]);
        self::assertSame("open_library", $data["candidates"][0]
            ["source"]["provider_key"]);
        self::assertTrue($data["manual_available"]);
        self::assertFalse($data["retry_available"]);
        self::assertSame(1, $primary->calls());
        self::assertSame(0, $fallback->calls());

        $bindings = array_column($data["field_bindings"], null, "field");
        self::assertSame("edition_title_evidence", $bindings["title"]["target"]);
        self::assertSame("edition", $bindings["subtitle"]["target"]);
        self::assertSame("work", $bindings["contributors"]
            ["explicit_mappings"]["author"]);
        self::assertSame("edition", $bindings["contributors"]
            ["explicit_mappings"]["translator"]);
        self::assertSame("evidence_only", $bindings["contributors"]
            ["fallback_target"]);
        self::assertSame([], $bindings["format"]["explicit_mappings"]);
        self::assertSame("evidence_only", $bindings["format"]
            ["fallback_target"]);
    }

    public function testMultipleCandidatesRemainSeparateAndUnranked(): void
    {
        $primary = new AddBookCountingMetadataProvider(
            "open_library",
            ProviderLookupResult::candidates([
                $this->candidate("record-a"),
                $this->candidate("record-b"),
            ])
        );
        $fallback = new AddBookCountingMetadataProvider(
            "google_books",
            ProviderLookupResult::miss()
        );
        $data = $this->serializer()->addBookMetadataLookup(
            $this->newIsbnLookup($primary, $fallback)
        );

        self::assertSame("multiple_candidates", $data["status"]);
        self::assertSame(
            2,
            count(array_unique(array_column($data["candidates"], "candidate_id")))
        );
        self::assertArrayNotHasKey("selected_candidate", $data);
        self::assertArrayNotHasKey("ranking", $data);
    }

    public function testMissAndProviderFailureRemainDistinctManualOutcomes(): void
    {
        $miss = $this->serializer()->addBookMetadataLookup(
            $this->newIsbnLookup(
                new AddBookCountingMetadataProvider(
                    "open_library",
                    ProviderLookupResult::miss()
                ),
                new AddBookCountingMetadataProvider(
                    "google_books",
                    ProviderLookupResult::miss()
                )
            )
        );
        $failure = $this->serializer()->addBookMetadataLookup(
            $this->newIsbnLookup(
                new AddBookCountingMetadataProvider(
                    "open_library",
                    ProviderLookupResult::unavailable(
                        ProviderFailureReason::Network
                    )
                ),
                new AddBookCountingMetadataProvider(
                    "google_books",
                    ProviderLookupResult::miss()
                )
            )
        );

        self::assertSame("no_usable_candidate", $miss["status"]);
        self::assertTrue($miss["manual_available"]);
        self::assertFalse($miss["retry_available"]);
        self::assertSame("provider_failure", $failure["status"]);
        self::assertTrue($failure["manual_available"]);
        self::assertTrue($failure["retry_available"]);
    }

    private function newIsbnLookup(
        MetadataProvider $primary,
        MetadataProvider $fallback
    ): AddBookMetadataLookupResult {
        $claims = $this->createStub(EditionIdentifierClaimRepository::class);
        $claims->method("findByCanonicalIsbn13")->willReturn(null);
        $editions = $this->createStub(EditionRepository::class);
        $legacy = $this->createStub(BibliographicMetadataRepository::class);
        $legacy->method("editionsForIsbns")->willReturn([]);
        $works = $this->createStub(WorkRepository::class);

        return $this->service(
            LibraryMembership::owner(),
            $claims,
            $editions,
            $legacy,
            $works,
            $primary,
            $fallback
        )->lookup(new LibraryId("library-a"), "9780306406157");
    }

    private function service(
        LibraryMembership $membership,
        EditionIdentifierClaimRepository $claims,
        EditionRepository $editions,
        BibliographicMetadataRepository $legacy,
        WorkRepository $works,
        MetadataProvider $primary,
        MetadataProvider $fallback,
        ?AddBookExistingItemRepository $existingItems = null,
        ?AuthorRepository $authors = null
    ): AddBookMetadataLookupService {
        $actor = new UserId("actor-a");
        $libraryId = new LibraryId("library-a");
        $authenticated = $this->createStub(AuthenticatedUser::class);
        $authenticated->method("requireUserId")->willReturn($actor);
        $contexts = $this->createStub(ActorLibraryContextRepository::class);
        $contexts->method("findForActor")->willReturn(
            new ActorLibraryContext(
                Library::privateLibrary($libraryId),
                new LibraryMembershipAssignment(
                    $libraryId,
                    $actor,
                    $membership
                ),
                true
            )
        );
        $snapshots = new AddBookRecordingSnapshotRepository();
        $this->snapshots = $snapshots;
        $lookupIds = $this->createStub(MetadataLookupIdGenerator::class);
        $lookupIds->method("next")->willReturn(
            new MetadataLookupId("lookup-00000000000000000000000000000001")
        );
        $transactions = $this->createStub(TransactionManager::class);
        $transactions->method("run")->willReturnCallback(
            static fn (callable $operation): mixed => $operation()
        );
        $clock = $this->createStub(MetadataClock::class);
        $clock->method("now")->willReturn(
            new DateTimeImmutable("2026-09-06T10:00:00+00:00")
        );

        return new AddBookMetadataLookupService(
            new LibraryContextQueryService(
                $authenticated,
                $contexts,
                new LibraryAuthorizationPolicy()
            ),
            new LocalEditionResolver(
                new IsbnCanonicalizer(),
                $claims,
                $editions,
                $legacy
            ),
            $works,
            new BibliographicRelationshipQueryService(
                $authors ?? $this->createStub(AuthorRepository::class),
                $this->createStub(SeriesRepository::class)
            ),
            $existingItems
                ?? $this->createStub(AddBookExistingItemRepository::class),
            new FirstSufficientMetadataLookupService(
                new CandidateClassifier(),
                $primary,
                $fallback
            ),
            new AddBookMetadataReviewPolicy(),
            $authenticated,
            $snapshots,
            $lookupIds,
            $transactions,
            $clock
        );
    }

    private function candidate(string $recordId): MetadataCandidate
    {
        $identity = $this->identity();

        return new MetadataCandidate(
            "open_library",
            $recordId,
            new DateTimeImmutable("2026-09-06T10:00:00+00:00"),
            MetadataMatchMethod::ExactIsbn,
            $identity,
            $identity,
            "Concrete titel",
            "Ondertitel",
            ["Auteur"],
            ["nld"],
            ["Uitgever"],
            "2026",
            320,
            "Hardcover",
            new MetadataWorkLink("work-signal")
        );
    }

    private function identity(): CanonicalIsbnIdentity
    {
        return CanonicalIsbnIdentity::fromIsbn(
            new Isbn13("9780306406157")
        );
    }

    private function serializer(): RestResponseSerializer
    {
        return new RestResponseSerializer(
            new CatalogCursorCodec(),
            new ReadingHistoryCursorCodec(),
            new PrivateNoteCursorCodec()
        );
    }
}

final class AddBookRecordingSnapshotRepository implements
    MetadataLookupSnapshotRepository
{
    /** @var list<MetadataLookupSnapshot> */
    public array $saved = [];

    public function save(MetadataLookupSnapshot $snapshot): void
    {
        $this->saved[] = $snapshot;
    }

    public function candidateForCommit(
        MetadataLookupId $lookupId,
        MetadataCandidateId $candidateId,
        UserId $actorId,
        LibraryId $libraryId,
        DateTimeImmutable $at
    ): ?MetadataCandidate {
        return null;
    }
}

final class AddBookCountingMetadataProvider implements MetadataProvider
{
    private int $calls = 0;

    public function __construct(
        private readonly string $key,
        private readonly ProviderLookupResult $result
    ) {
    }

    public function key(): string { return $this->key; }

    public function lookup(CanonicalIsbnIdentity $isbn): ProviderLookupResult
    {
        ++$this->calls;
        return $this->result;
    }

    public function calls(): int { return $this->calls; }
}
