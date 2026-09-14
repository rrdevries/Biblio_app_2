<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Catalog\{AddLibraryItemCommitter,AddLibraryItemTransactionParticipant};
use Biblio\Core\Application\Catalog\Classification\LibraryCatalogContextInitialization;
use Biblio\Core\Application\Catalog\LocalEditionResolver;
use Biblio\Core\Application\Identity\AuthenticatedUser;
use Biblio\Core\Application\Library\{ActorLibraryContext,ActorLibraryContextRepository,LibraryContextQueryService};
use Biblio\Core\Application\Metadata\Author\{
    AuthorContributorCreditRace,
    AuthorContributorCreditRepository,
    AuthorContributorPositionRace,
    AuthorIdentityPromotionRace,
    AuthorProviderClaimRace,
    AuthorProviderIdentityRepository,
    CanonicalAuthorMaterializationIdGenerator,
    CanonicalAuthorMaterializer
};
use Biblio\Core\Application\Metadata\{AddBookCommitRequest,AddBookCommitSelection,AddBookCommitService,AddBookObservedMetadata,AddBookRecordIdGenerator,EditionMetadataProvenanceRepository,ManualAuthorInput,MetadataCandidate,MetadataCandidateId,MetadataClock,MetadataFieldReviewRepository,MetadataFieldValue,MetadataLookupId,MetadataLookupSnapshotRepository,MetadataLookupSnapshotUnavailable,MetadataMatchMethod,UserObservedMetadataEvidenceRepository,UserObservedMetadataField};
use Biblio\Core\Authorization\LibraryAuthorizationPolicy;
use Biblio\Core\Catalog\Classification\{LibraryBookTypeId,LibraryCatalogSelection};
use Biblio\Core\Catalog\{BibliographicMetadataRepository,CanonicalIsbnIdentity,Edition,EditionId,EditionIdentifierClaimRepository,EditionIsbnMetadata,EditionRepository,Isbn13,IsbnCanonicalizer,Item,ItemId,Work,WorkId,WorkRepository,WritableAuthorRepository};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\{Library,LibraryId,LibraryMembership,LibraryMembershipAssignment};
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AddBookCommitServiceTest extends TestCase
{
    public function testStaleLookupReusesCurrentLocalEdition(): void
    {
        $identity = $this->identity();
        $edition = new Edition(
            new EditionId("edition-existing"),
            new WorkId("work-existing"),
            "Existing title",
            $identity->metadata()
        );
        $work = new Work($edition->workId(), "Existing Work");
        $candidate = $this->candidate($identity);
        $committer = $this->createMock(AddLibraryItemCommitter::class);
        $committer->expects(self::once())
            ->method("addForExistingEdition")
            ->with(
                self::isInstanceOf(LibraryId::class),
                self::isInstanceOf(ItemId::class),
                self::callback(static fn (EditionId $id): bool =>
                    $id->equals($edition->id()))
            )
            ->willReturn(Item::active(
                new ItemId("item-new"),
                new LibraryId("library-a"),
                $edition->id()
            ));
        $committer->expects(self::never())->method("addWithNewWorkAndEdition");

        $result = $this->service(
            LibraryMembership::owner(),
            $edition,
            $work,
            $candidate,
            $committer
        )->commit(new LibraryId("library-a"), $this->request(true));

        self::assertTrue($result->existingEdition());
        self::assertTrue($result->edition()->id()->equals($edition->id()));
    }

    public function testNewIsbnUsesReviewedSnapshotForProvisionalCreation(): void
    {
        $identity = $this->identity();
        $candidate = $this->candidate($identity);
        $edition = new Edition(
            new EditionId("edition-new"),
            new WorkId("work-new"),
            "Reviewed title",
            $identity->metadata()
        );
        $work = new Work($edition->workId(), "Reviewed title");
        $committer = $this->createMock(AddLibraryItemCommitter::class);
        $committer->expects(self::once())
            ->method("addWithNewWorkAndEdition")
            ->with(
                self::isInstanceOf(LibraryId::class),
                self::isInstanceOf(ItemId::class),
                self::isInstanceOf(WorkId::class),
                "Reviewed title",
                self::isInstanceOf(EditionId::class),
                self::isInstanceOf(LibraryCatalogContextInitialization::class),
                self::callback(static fn (EditionIsbnMetadata $metadata): bool =>
                    $metadata->isbn13()?->value() === "9780306406157")
            )
            ->willReturn(Item::active(
                new ItemId("item-new"),
                new LibraryId("library-a"),
                $edition->id()
            ));

        $result = $this->service(
            LibraryMembership::owner(),
            null,
            $work,
            $candidate,
            $committer,
            $edition
        )->commit(new LibraryId("library-a"), $this->request(true));

        self::assertFalse($result->existingEdition());
        self::assertSame("Reviewed title", $result->edition()->title());
        self::assertSame("provisional", $result->work()->titleStatus()->value);
    }

    public function testExpiredOrUnboundSnapshotFailsBeforeCatalogWrite(): void
    {
        $committer = $this->createMock(AddLibraryItemCommitter::class);
        $committer->expects(self::never())->method("addForExistingEdition");
        $committer->expects(self::never())->method("addWithNewWorkAndEdition");

        $this->expectException(MetadataLookupSnapshotUnavailable::class);
        $this->service(
            LibraryMembership::owner(),
            null,
            new Work(new WorkId("work-unused"), "Unused"),
            null,
            $committer
        )->commit(new LibraryId("library-a"), $this->request(true));
    }

    public function testViewOnlyActorIsRejectedBeforeSnapshotOrWrite(): void
    {
        $committer = $this->createMock(AddLibraryItemCommitter::class);
        $committer->expects(self::never())->method("addForExistingEdition");
        $committer->expects(self::never())->method("addWithNewWorkAndEdition");

        $this->expectException(\Biblio\Core\Exception\AuthorizationException::class);
        $this->service(
            LibraryMembership::safeDefault(),
            null,
            new Work(new WorkId("work-unused"), "Unused"),
            $this->candidate($this->identity()),
            $committer
        )->commit(new LibraryId("library-a"), $this->request(true));
    }

    public function testObservedIsbnCannotBypassIdentifierValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AddBookCommitRequest(
            null,
            AddBookCommitSelection::manual(),
            new AddBookObservedMetadata([
                UserObservedMetadataField::Isbn->value =>
                    new MetadataFieldValue("9780306406157"),
            ]),
            new LibraryCatalogContextInitialization(
                new LibraryCatalogSelection(new LibraryBookTypeId("book-a"))
            )
        );
    }

    public function testExplicitAmbiguousEditionSelectionReusesOnlyChosenEdition(): void
    {
        $identity = $this->identity();
        $first = new Edition(
            new EditionId("edition-a"),
            new WorkId("work-a"),
            "Edition A",
            $identity->metadata()
        );
        $chosen = new Edition(
            new EditionId("edition-b"),
            new WorkId("work-b"),
            "Edition B",
            $identity->metadata()
        );
        $work = new Work($chosen->workId(), "Work B");
        $committer = $this->createMock(AddLibraryItemCommitter::class);
        $committer->expects(self::once())
            ->method("addForExistingEdition")
            ->with(
                self::isInstanceOf(LibraryId::class),
                self::isInstanceOf(ItemId::class),
                self::callback(static fn (EditionId $id): bool =>
                    $id->equals($chosen->id()))
            )
            ->willReturn(Item::active(
                new ItemId("item-new"),
                new LibraryId("library-a"),
                $chosen->id()
            ));
        $committer->expects(self::never())
            ->method("addWithNewEditionForExistingWork");
        $committer->expects(self::never())->method("addWithNewWorkAndEdition");

        $request = new AddBookCommitRequest(
            "9780306406157",
            AddBookCommitSelection::existingEdition($chosen->id()),
            new AddBookObservedMetadata([]),
            $this->classification()
        );
        $result = $this->service(
            LibraryMembership::owner(),
            null,
            $work,
            null,
            $committer,
            $chosen,
            [
                "9780306406157" => [$first],
                "0306406152" => [$chosen],
            ]
        )->commit(new LibraryId("library-a"), $request);

        self::assertTrue($result->existingEdition());
        self::assertTrue($result->edition()->id()->equals($chosen->id()));
    }

    public function testManipulatedAmbiguousEditionSelectionFailsWithoutWrite(): void
    {
        $identity = $this->identity();
        $first = new Edition(
            new EditionId("edition-a"),
            new WorkId("work-a"),
            "Edition A",
            $identity->metadata()
        );
        $second = new Edition(
            new EditionId("edition-b"),
            new WorkId("work-b"),
            "Edition B",
            $identity->metadata()
        );
        $committer = $this->createMock(AddLibraryItemCommitter::class);
        $committer->expects(self::never())->method("addForExistingEdition");
        $committer->expects(self::never())
            ->method("addWithNewEditionForExistingWork");
        $committer->expects(self::never())->method("addWithNewWorkAndEdition");

        $this->expectException(\Biblio\Core\Exception\ValidationException::class);
        $this->service(
            LibraryMembership::owner(),
            null,
            new Work(new WorkId("work-unused"), "Unused"),
            null,
            $committer,
            null,
            [
                "9780306406157" => [$first],
                "0306406152" => [$second],
            ]
        )->commit(
            new LibraryId("library-a"),
            new AddBookCommitRequest(
                "9780306406157",
                AddBookCommitSelection::existingEdition(
                    new EditionId("edition-manipulated")
                ),
                new AddBookObservedMetadata([]),
                $this->classification()
            )
        );
    }

    public function testManualSelectionCanCreateEditionForExplicitExistingWork(): void
    {
        $work = new Work(new WorkId("work-existing"), "Existing Work");
        $edition = new Edition(
            new EditionId("edition-new"),
            $work->id(),
            "Concrete new Edition"
        );
        $committer = $this->createMock(AddLibraryItemCommitter::class);
        $committer->expects(self::once())
            ->method("addWithNewEditionForExistingWork")
            ->with(
                self::isInstanceOf(LibraryId::class),
                self::isInstanceOf(ItemId::class),
                self::isInstanceOf(EditionId::class),
                self::callback(static fn (WorkId $id): bool =>
                    $id->equals($work->id())),
                "Concrete new Edition"
            )
            ->willReturn(Item::active(
                new ItemId("item-new"),
                new LibraryId("library-a"),
                $edition->id()
            ));
        $committer->expects(self::never())->method("addWithNewWorkAndEdition");

        $result = $this->service(
            LibraryMembership::owner(),
            null,
            $work,
            null,
            $committer,
            $edition
        )->commit(
            new LibraryId("library-a"),
            new AddBookCommitRequest(
                null,
                AddBookCommitSelection::manual($work->id()),
                new AddBookObservedMetadata([
                    UserObservedMetadataField::Title->value =>
                        new MetadataFieldValue("Concrete new Edition"),
                ]),
                $this->classification()
            )
        );

        self::assertFalse($result->existingEdition());
        self::assertTrue($result->work()->id()->equals($work->id()));
        self::assertSame("Concrete new Edition", $result->edition()->title());
    }

    public function testManualSelectionWithoutWorkKeepsNewProvisionalWorkPath(): void
    {
        $work = new Work(new WorkId("work-new"), "Manual Edition");
        $edition = new Edition(
            new EditionId("edition-new"),
            $work->id(),
            "Manual Edition"
        );
        $committer = $this->createMock(AddLibraryItemCommitter::class);
        $committer->expects(self::never())
            ->method("addWithNewEditionForExistingWork");
        $committer->expects(self::once())
            ->method("addWithNewWorkAndEdition")
            ->willReturn(Item::active(
                new ItemId("item-new"),
                new LibraryId("library-a"),
                $edition->id()
            ));

        $result = $this->service(
            LibraryMembership::owner(),
            null,
            $work,
            null,
            $committer,
            $edition
        )->commit(
            new LibraryId("library-a"),
            new AddBookCommitRequest(
                null,
                AddBookCommitSelection::manual(),
                new AddBookObservedMetadata([
                    UserObservedMetadataField::Title->value =>
                        new MetadataFieldValue("Manual Edition"),
                ]),
                $this->classification()
            )
        );

        self::assertFalse($result->existingEdition());
        self::assertSame("provisional", $result->work()->titleStatus()->value);
    }

    /** @param class-string<RuntimeException> $raceClass */
    #[DataProvider("authorRaceSignals")]
    public function testAuthorRaceRetriesCompleteAddBookCommitOnce(
        string $raceClass
    ): void {
        $identity = $this->identity();
        $candidate = $this->candidate($identity);
        $edition = new Edition(
            new EditionId("edition-new"),
            new WorkId("work-new"),
            "Reviewed title",
            $identity->metadata()
        );
        $work = new Work($edition->workId(), "Reviewed title");
        $calls = [];
        $committer = $this->createMock(AddLibraryItemCommitter::class);
        $committer->expects(self::exactly(2))
            ->method("addWithNewWorkAndEdition")
            ->willReturnCallback(static function (
                LibraryId $libraryId,
                ItemId $itemId,
                WorkId $workId,
                string $title,
                EditionId $editionId
            ) use (&$calls, $raceClass, $edition): Item {
                $calls[] = [
                    $libraryId->value(),
                    $itemId->value(),
                    $workId->value(),
                    $title,
                    $editionId->value(),
                ];
                if (count($calls) === 1) {
                    throw new $raceClass();
                }
                return Item::active(
                    $itemId,
                    $libraryId,
                    $edition->id()
                );
            });

        $result = $this->service(
            LibraryMembership::owner(),
            null,
            $work,
            $candidate,
            $committer,
            $edition
        )->commit(new LibraryId("library-a"), $this->request(true));

        self::assertSame($calls[0], $calls[1]);
        self::assertSame("edition-new", $result->edition()->id()->value());
    }

    public function testPersistentAuthorRaceStopsAfterOneRetry(): void
    {
        $committer = $this->createMock(AddLibraryItemCommitter::class);
        $committer->expects(self::exactly(2))
            ->method("addWithNewWorkAndEdition")
            ->willThrowException(new AuthorContributorCreditRace());

        $this->expectException(AuthorContributorCreditRace::class);
        $this->service(
            LibraryMembership::owner(),
            null,
            new Work(new WorkId("work-new"), "Reviewed title"),
            $this->candidate($this->identity()),
            $committer
        )->commit(new LibraryId("library-a"), $this->request(true));
    }

    public function testManualAuthorRetryReusesOneAttemptPlanAndObservationIds(): void
    {
        $edition = new Edition(
            new EditionId("edition-new"),
            new WorkId("work-new"),
            "Manual title"
        );
        $work = new Work($edition->workId(), "Manual title");
        $participants = [];
        $committer = $this->createMock(AddLibraryItemCommitter::class);
        $committer->expects(self::exactly(2))
            ->method("addWithNewWorkAndEdition")
            ->willReturnCallback(static function (
                LibraryId $libraryId,
                ItemId $itemId,
                WorkId $workId,
                string $title,
                EditionId $editionId,
                ?LibraryCatalogContextInitialization $classification,
                ?EditionIsbnMetadata $isbnMetadata,
                mixed $inventoryNumber,
                mixed $locationId,
                ?AddLibraryItemTransactionParticipant $participant
            ) use (&$participants, $edition): Item {
                $participants[] = $participant;
                if (count($participants) === 1) {
                    throw new AuthorContributorCreditRace();
                }
                return Item::active($itemId, $libraryId, $edition->id());
            });
        $ids = $this->createMock(AddBookRecordIdGenerator::class);
        $ids->method("nextItemId")->willReturn(new ItemId("item-new"));
        $ids->method("nextWorkId")->willReturn(new WorkId("work-new"));
        $ids->method("nextEditionId")->willReturn(new EditionId("edition-new"));
        $ids->expects(self::exactly(2))
            ->method("nextManualAuthorObservationId")
            ->willReturnOnConsecutiveCalls(
                "manual-observation-one",
                "manual-observation-two"
            );
        $first = ManualAuthorInput::fromDisplayName("Alpha");
        $second = ManualAuthorInput::fromDisplayName("Beta");
        self::assertNotNull($first);
        self::assertNotNull($second);

        $result = $this->service(
            LibraryMembership::owner(),
            null,
            $work,
            null,
            $committer,
            $edition,
            recordIds: $ids
        )->commit(
            new LibraryId("library-a"),
            new AddBookCommitRequest(
                null,
                AddBookCommitSelection::manual(),
                new AddBookObservedMetadata([
                    UserObservedMetadataField::Title->value =>
                        new MetadataFieldValue("Manual title"),
                ]),
                $this->classification(),
                authors: [$first, $second]
            )
        );

        self::assertSame($participants[0], $participants[1]);
        self::assertSame("edition-new", $result->edition()->id()->value());
    }

    public function testIsbnRaceWinnerDoesNotReceiveManualAuthors(): void
    {
        $winnerWork = new Work(new WorkId("work-race-winner"), "Winner Work");
        $winnerEdition = new Edition(
            new EditionId("edition-race-winner"),
            $winnerWork->id(),
            "Winner Edition"
        );
        $authorRepository = $this->createMock(WritableAuthorRepository::class);
        $authorRepository->expects(self::never())->method("add");
        $authorRepository->expects(self::never())->method("addContributor");
        $credits = $this->createMock(AuthorContributorCreditRepository::class);
        $credits->expects(self::never())->method("findByKey");
        $clock = $this->createStub(MetadataClock::class);
        $clock->method("now")->willReturn(
            new DateTimeImmutable("2026-09-14T10:10:00+00:00")
        );
        $materializer = new CanonicalAuthorMaterializer(
            $authorRepository,
            $this->createStub(AuthorProviderIdentityRepository::class),
            $credits,
            $this->createStub(CanonicalAuthorMaterializationIdGenerator::class),
            $clock
        );
        $reviews = $this->createStub(MetadataFieldReviewRepository::class);
        $reviews->method("findForUpdate")->willReturnCallback(
            static fn ($recordId, $field, $at) =>
                \Biblio\Core\Application\Metadata\MetadataFieldReview::empty(
                    $recordId,
                    $field,
                    $at
                )
        );
        $committer = $this->createMock(AddLibraryItemCommitter::class);
        $committer->expects(self::once())
            ->method("addWithNewWorkAndEdition")
            ->willReturnCallback(static function (
                LibraryId $libraryId,
                ItemId $itemId,
                WorkId $workId,
                string $title,
                EditionId $editionId,
                ?LibraryCatalogContextInitialization $classification,
                ?EditionIsbnMetadata $isbnMetadata,
                mixed $inventoryNumber,
                mixed $locationId,
                ?AddLibraryItemTransactionParticipant $participant
            ) use ($winnerWork, $winnerEdition): Item {
                $item = Item::active($itemId, $libraryId, $winnerEdition->id());
                $participant?->apply($winnerWork, $winnerEdition, $item, true);
                return $item;
            });
        $author = ManualAuthorInput::fromDisplayName("Must Not Attach");
        self::assertNotNull($author);
        $ids = $this->createStub(AddBookRecordIdGenerator::class);
        $ids->method("nextItemId")->willReturn(new ItemId("item-new"));
        $ids->method("nextWorkId")->willReturn(new WorkId("work-new"));
        $ids->method("nextEditionId")->willReturn(new EditionId("edition-new"));
        $ids->method("nextManualAuthorObservationId")
            ->willReturn("manual-observation-race-winner");

        $result = $this->service(
            LibraryMembership::owner(),
            null,
            $winnerWork,
            null,
            $committer,
            $winnerEdition,
            recordIds: $ids,
            materializer: $materializer,
            reviews: $reviews
        )->commit(
            new LibraryId("library-a"),
            new AddBookCommitRequest(
                null,
                AddBookCommitSelection::manual(),
                new AddBookObservedMetadata([
                    UserObservedMetadataField::Title->value =>
                        new MetadataFieldValue("Intended New Work"),
                ]),
                $this->classification(),
                authors: [$author]
            )
        );

        self::assertTrue($result->existingEdition());
        self::assertSame("work-race-winner", $result->work()->id()->value());
    }

    /** @return iterable<string, array{class-string<RuntimeException>}> */
    public static function authorRaceSignals(): iterable
    {
        yield "provider claim" => [AuthorProviderClaimRace::class];
        yield "contributor credit" => [AuthorContributorCreditRace::class];
        yield "contributor position" => [AuthorContributorPositionRace::class];
        yield "identity promotion" => [AuthorIdentityPromotionRace::class];
    }

    private function service(
        LibraryMembership $membership,
        ?Edition $localEdition,
        Work $work,
        ?MetadataCandidate $snapshotCandidate,
        AddLibraryItemCommitter $committer,
        ?Edition $committedEdition = null,
        array $legacyEditions = [],
        ?AddBookRecordIdGenerator $recordIds = null,
        ?CanonicalAuthorMaterializer $materializer = null,
        ?MetadataFieldReviewRepository $reviews = null
    ): AddBookCommitService {
        $actorId = new UserId("actor-a");
        $libraryId = new LibraryId("library-a");
        $authenticated = $this->createStub(AuthenticatedUser::class);
        $authenticated->method("requireUserId")->willReturn($actorId);
        $contexts = $this->createStub(ActorLibraryContextRepository::class);
        $contexts->method("findForActor")->willReturn(new ActorLibraryContext(
            Library::privateLibrary($libraryId),
            new LibraryMembershipAssignment($libraryId, $actorId, $membership),
            true
        ));
        $claims = $this->createStub(EditionIdentifierClaimRepository::class);
        $claims->method("findByCanonicalIsbn13")
            ->willReturn($localEdition?->id());
        $editions = $this->createStub(EditionRepository::class);
        $editions->method("find")->willReturnCallback(
            static function (EditionId $id) use (
                $localEdition,
                $committedEdition,
                $legacyEditions
            ): ?Edition {
                foreach ($legacyEditions as $matches) {
                    foreach ($matches as $match) {
                        if ($match->id()->equals($id)) {
                            return $match;
                        }
                    }
                }

                return match ($id->value()) {
                    $localEdition?->id()->value() => $localEdition,
                    $committedEdition?->id()->value() => $committedEdition,
                    default => null,
                };
            }
        );
        $legacy = $this->createStub(BibliographicMetadataRepository::class);
        $legacy->method("editionsForIsbns")->willReturn($legacyEditions);
        $snapshots = $this->createStub(MetadataLookupSnapshotRepository::class);
        $snapshots->method("candidateForCommit")->willReturn($snapshotCandidate);
        $ids = $recordIds ?? $this->createStub(AddBookRecordIdGenerator::class);
        $ids->method("nextItemId")->willReturn(new ItemId("item-new"));
        $ids->method("nextWorkId")->willReturn(new WorkId("work-new"));
        $ids->method("nextEditionId")->willReturn(new EditionId("edition-new"));
        $clock = $this->createStub(MetadataClock::class);
        $clock->method("now")->willReturn(
            new DateTimeImmutable("2026-09-06T10:10:00+00:00")
        );
        $works = $this->createStub(WorkRepository::class);
        $works->method("find")->willReturn($work);

        return new AddBookCommitService(
            $authenticated,
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
            $snapshots,
            $committer,
            $ids,
            $clock,
            $editions,
            $works,
            $reviews ?? $this->createStub(MetadataFieldReviewRepository::class),
            $this->createStub(UserObservedMetadataEvidenceRepository::class),
            $this->createStub(EditionMetadataProvenanceRepository::class),
            $materializer ?? new CanonicalAuthorMaterializer(
                $this->createStub(WritableAuthorRepository::class),
                $this->createStub(AuthorProviderIdentityRepository::class),
                $this->createStub(AuthorContributorCreditRepository::class),
                $this->createStub(CanonicalAuthorMaterializationIdGenerator::class),
                $clock
            )
        );
    }

    private function request(bool $candidate): AddBookCommitRequest
    {
        return new AddBookCommitRequest(
            "9780306406157",
            $candidate
                ? AddBookCommitSelection::candidate(
                    new MetadataLookupId(
                        "lookup-11111111111111111111111111111111"
                    ),
                    MetadataCandidateId::fromCandidate(
                        $this->candidate($this->identity())
                    )
                )
                : AddBookCommitSelection::manual(),
            new AddBookObservedMetadata([]),
            $this->classification()
        );
    }

    private function classification(): LibraryCatalogContextInitialization
    {
        return new LibraryCatalogContextInitialization(
            new LibraryCatalogSelection(new LibraryBookTypeId("book-a"))
        );
    }

    private function candidate(
        CanonicalIsbnIdentity $identity
    ): MetadataCandidate {
        return new MetadataCandidate(
            "open_library",
            "record-a",
            new DateTimeImmutable("2026-09-06T10:00:00+00:00"),
            MetadataMatchMethod::ExactIsbn,
            $identity,
            $identity,
            "Reviewed title",
            null,
            [],
            ["eng"],
            [],
            null,
            null,
            null,
            null
        );
    }

    private function identity(): CanonicalIsbnIdentity
    {
        return CanonicalIsbnIdentity::fromIsbn(
            new Isbn13("9780306406157")
        );
    }
}
