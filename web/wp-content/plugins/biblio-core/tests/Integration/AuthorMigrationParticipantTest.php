<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Identity\{
    PersonalMigrationTarget,
    PersonalMigrationTargetReadiness
};
use Biblio\Core\Application\Metadata\Author\{
    AuthorContributorCredit,
    AuthorContributorCreditId,
    AuthorContributorCreditKey,
    AuthorContributorCreditRepository,
    AuthorContributorCreditStatus,
    AuthorContributorCreditVersion,
    AuthorCreditEvidence,
    AuthorCreditReviewReason,
    AuthorMaterializationStatus,
    AuthorMaterializationWriteDisposition,
    CanonicalAuthorMaterializationIdGenerator,
    CanonicalAuthorMaterializer,
    MigrationAuthorCredit
};
use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Application\Metadata\Search\{
    BibliographicAuthorReference,
    BibliographicTextSearchQuery
};
use Biblio\Core\Application\Migration\Author\{
    AuthorMigrationFailure,
    AuthorMigrationReason,
    AuthorMigrationWriter,
    CatalogAuthorMigrationParticipant,
    CatalogAuthorPlan,
    CatalogWorkContributorMigrationParticipant,
    CatalogWorkContributorPlan
};
use Biblio\Core\Application\Migration\Catalog\CatalogWorkMigrationParticipant;
use Biblio\Core\Application\Migration\{
    CommitMigrationRecordService,
    MappingDisposition,
    MigrationClock,
    MigrationLedgerRepository,
    MigrationMode,
    MigrationReconciliation,
    MigrationRecordOutcome,
    MigrationRun,
    MigrationTargetMapping,
    ObserveSourceRecordService,
    SourceObservation
};
use Biblio\Core\Application\Migration\Runner\{
    MigrationParticipant,
    MigrationPlanningTarget,
    MigrationSourceRecord
};
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
use Biblio\Core\Exception\{ConflictException, ValidationException};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\{
    WpdbAuthorContributorCreditRepository,
    WpdbAuthorRepository,
    WpdbBibliographicAuthorWorkSearchProvider,
    WpdbBibliographicProviderIdentityRepository,
    WpdbBibliographicSearchProvider,
    WpdbLibraryRepository,
    WpdbMigrationLedgerRepository,
    WpdbTransactionManager,
    WpdbWorkRepository
};
use Biblio\Core\Infrastructure\Persistence\PersistenceException;
use Biblio\Core\Library\{Library, LibraryId, LibraryName};
use DateTimeImmutable;
use TypeError;

final class AuthorMigrationTestClock implements MigrationClock, MetadataClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("2026-09-15T10:00:00.123456+00:00");
    }
}

final class AuthorMigrationTestIds implements
    CanonicalAuthorMaterializationIdGenerator
{
    private int $authors = 0;
    private int $credits = 0;

    public function __construct(private readonly string $token)
    {
    }

    public function nextAuthorId(): AuthorId
    {
        return new AuthorId(
            "migrated-author-{$this->token}-" . ++$this->authors
        );
    }

    public function nextCreditId(): AuthorContributorCreditId
    {
        return new AuthorContributorCreditId(
            "migrated-author-credit-{$this->token}-" . ++$this->credits
        );
    }
}

final readonly class FailingMigrationAuthorRepository implements
    WritableAuthorRepository
{
    public function __construct(
        private WritableAuthorRepository $inner,
        private bool $failAuthor = false,
        private bool $failContributor = false
    ) {
    }

    public function add(Author $author): void
    {
        if ($this->failAuthor) {
            throw new PersistenceException("Injected migration Author failure.");
        }
        $this->inner->add($author);
    }
    public function replaceIfVersionMatches(
        Author $replacement,
        \Biblio\Core\Catalog\AuthorVersion $expectedVersion
    ): bool {
        return $this->inner->replaceIfVersionMatches($replacement, $expectedVersion);
    }
    public function addContributor(WorkContributor $contributor): void
    {
        if ($this->failContributor) {
            throw new PersistenceException("Injected WorkContributor failure.");
        }
        $this->inner->addContributor($contributor);
    }
    public function find(AuthorId $authorId): ?Author
    {
        return $this->inner->find($authorId);
    }
    public function findMany(array $authorIds): array
    {
        return $this->inner->findMany($authorIds);
    }
    public function contributorsForWorks(array $workIds): array
    {
        return $this->inner->contributorsForWorks($workIds);
    }
    public function workIdsForAuthors(array $authorIds): array
    {
        return $this->inner->workIdsForAuthors($authorIds);
    }
}

final readonly class FailingMigrationCreditRepository implements
    AuthorContributorCreditRepository
{
    public function __construct(
        private AuthorContributorCreditRepository $inner,
        private bool $failCredit = false,
        private bool $failEvidence = false
    ) {
    }

    public function find(AuthorContributorCreditId $creditId): ?AuthorContributorCredit
    {
        return $this->inner->find($creditId);
    }
    public function findByKey(
        AuthorContributorCreditKey $key
    ): ?AuthorContributorCredit {
        return $this->inner->findByKey($key);
    }
    public function create(
        AuthorContributorCredit $credit
    ): AuthorContributorCredit {
        if ($this->failCredit) {
            throw new PersistenceException("Injected contributor credit failure.");
        }
        return $this->inner->create($credit);
    }
    public function observeEvidence(
        AuthorCreditEvidence $evidence
    ): AuthorMaterializationWriteDisposition {
        if ($this->failEvidence) {
            throw new PersistenceException("Injected contributor evidence failure.");
        }
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

final readonly class RacingMigrationCreditRepository implements
    AuthorContributorCreditRepository
{
    public function __construct(
        private AuthorContributorCreditRepository $inner,
        private WritableAuthorRepository $authors,
        private AuthorId $conflictingAuthorId
    ) {
    }

    public function find(AuthorContributorCreditId $creditId): ?AuthorContributorCredit
    {
        return $this->inner->find($creditId);
    }
    public function findByKey(
        AuthorContributorCreditKey $key
    ): ?AuthorContributorCredit {
        return $this->inner->findByKey($key);
    }
    public function create(
        AuthorContributorCredit $credit
    ): AuthorContributorCredit {
        $stored = $this->inner->create($credit);
        $this->authors->addContributor(new WorkContributor(
            $credit->workId(),
            $this->conflictingAuthorId,
            ContributorRole::CoAuthor,
            $credit->position()
        ));
        return $stored;
    }
    public function observeEvidence(
        AuthorCreditEvidence $evidence
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

final readonly class FailingCommitOutcomeMigrationLedger implements
    MigrationLedgerRepository
{
    public function __construct(private MigrationLedgerRepository $inner)
    {
    }

    public function beginOrResume(MigrationRun $run): MigrationRun
    {
        return $this->inner->beginOrResume($run);
    }
    public function findRun(string $runId): ?MigrationRun
    {
        return $this->inner->findRun($runId);
    }
    public function saveRun(MigrationRun $run): void
    {
        $this->inner->saveRun($run);
    }
    public function releaseRunLock(string $runId): void
    {
        $this->inner->releaseRunLock($runId);
    }
    public function addOrFindObservation(
        SourceObservation $observation
    ): SourceObservation {
        return $this->inner->addOrFindObservation($observation);
    }
    public function lockObservation(
        string $runId,
        string $observationId
    ): SourceObservation {
        return $this->inner->lockObservation($runId, $observationId);
    }
    public function commitOutcome(
        SourceObservation $observation,
        MigrationRecordOutcome $outcome,
        DateTimeImmutable $at
    ): void {
        $mappings = $outcome->mappings();
        $this->inner->commitOutcome(
            $observation,
            MigrationRecordOutcome::mapped([$mappings[0]]),
            $at
        );
        throw new PersistenceException(
            "Injected migration outcome failure after first mapping."
        );
    }
    public function reconciliation(string $runId): MigrationReconciliation
    {
        return $this->inner->reconciliation($runId);
    }
    public function priorTargets(
        MigrationRun $run,
        SourceObservation $observation
    ): array {
        return $this->inner->priorTargets($run, $observation);
    }
    public function sourceTargets(
        MigrationRun $run,
        string $sourceType,
        string $sourceId
    ): array {
        return $this->inner->sourceTargets($run, $sourceType, $sourceId);
    }
    public function targetSources(
        MigrationRun $run,
        string $targetType,
        string $targetId
    ): array {
        return $this->inner->targetSources($run, $targetType, $targetId);
    }
}

final class AuthorMigrationParticipantTest extends PersistenceIntegrationTestCase
{
    public function testStableAuthorIdentityIsProvisionalReplaySafeAndNeverNameMerged(): void
    {
        $fixture = $this->fixture("identity");
        $fixture["authors"]->add(new Author(
            new AuthorId("pre-existing-same-name"),
            "Alex Smith"
        ));

        $first = $this->apply(
            $fixture,
            $fixture["author_participant"],
            MigrationSourceRecord::typed(
                CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                "source-author-1",
                new CatalogAuthorPlan("  Alex\u{00A0}\tSmith  ")
            )
        );
        $second = $this->apply(
            $fixture,
            $fixture["author_participant"],
            MigrationSourceRecord::typed(
                CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                "source-author-2",
                new CatalogAuthorPlan("Alex Smith")
            )
        );

        $firstId = new AuthorId($first["outcome"]->mappings()[0]->targetId());
        self::assertNotSame(
            $firstId->value(),
            $second["outcome"]->mappings()[0]->targetId()
        );
        self::assertNotSame("pre-existing-same-name", $firstId->value());
        $stored = $fixture["authors"]->find($firstId);
        self::assertSame("Alex Smith", $stored?->displayName());
        self::assertSame(AuthorIdentityStatus::Provisional, $stored?->identityStatus());
        self::assertSame(AuthorDisplayNameStatus::Observed, $stored?->displayNameStatus());
        self::assertSame(3, $this->rows($this->tableNames->authors()));
        self::assertSame(0, $this->rows(
            $this->tableNames->bibliographicProviderIdentities()
        ));

        $later = $this->laterRun($fixture, "identity-replay");
        $replay = $this->apply(
            $later,
            $later["author_participant"],
            MigrationSourceRecord::typed(
                CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                "source-author-1",
                new CatalogAuthorPlan("Alex Smith")
            )
        );
        self::assertSame($firstId->value(), $replay["outcome"]->mappings()[0]->targetId());
        self::assertSame(
            MappingDisposition::Reused,
            $replay["outcome"]->mappings()[0]->disposition()
        );
        self::assertSame(3, $this->rows($this->tableNames->authors()));

        $changed = $this->laterRun($later, "identity-changed");
        try {
            $this->apply(
                $changed,
                $changed["author_participant"],
                MigrationSourceRecord::typed(
                    CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                    "source-author-1",
                    new CatalogAuthorPlan("Changed Name")
                )
            );
            self::fail("Changed Author payload was silently reused.");
        } catch (AuthorMigrationFailure $failure) {
            self::assertSame(AuthorMigrationReason::DivergentReplay, $failure->reason());
        }
        self::assertSame("Alex Smith", $fixture["authors"]->find($firstId)?->displayName());
    }

    public function testAuthorMappingsAreIsolatedByTargetAndSourceFamily(): void
    {
        $first = $this->fixture("isolation-first");
        $source = MigrationSourceRecord::typed(
            CatalogAuthorMigrationParticipant::SOURCE_TYPE,
            "author/isolation",
            new CatalogAuthorPlan("Shared source identity")
        );
        $firstId = $this->apply(
            $first,
            $first["author_participant"],
            $source
        )["outcome"]->mappings()[0]->targetId();

        $otherTarget = $this->fixture("isolation-other-target");
        $otherTargetId = $this->apply(
            $otherTarget,
            $otherTarget["author_participant"],
            $source
        )["outcome"]->mappings()[0]->targetId();
        self::assertNotSame($firstId, $otherTargetId);

        $otherFamily = $this->laterRunForSourceFamily(
            $first,
            "isolation-other-family",
            "alternate"
        );
        $otherFamilyId = $this->apply(
            $otherFamily,
            $otherFamily["author_participant"],
            $source
        )["outcome"]->mappings()[0]->targetId();
        self::assertNotSame($firstId, $otherFamilyId);
        self::assertNotSame($otherTargetId, $otherFamilyId);
        self::assertSame(3, $this->rows($this->tableNames->authors()));
    }

    public function testContributorUsesExactMappingsAcrossWorksAndSearchOnlyReads(): void
    {
        $fixture = $this->fixture("multiple-works");
        $author = $this->apply(
            $fixture,
            $fixture["author_participant"],
            MigrationSourceRecord::typed(
                CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                "author/shared",
                new CatalogAuthorPlan("Zoë Auteur")
            )
        );
        $authorId = new AuthorId($author["outcome"]->mappings()[0]->targetId());
        $this->mapWork($fixture, "work/a", "work-target-a", "Zulu Work");
        $this->mapWork($fixture, "work/b", "work-target-b", "Alpha Work");

        $first = $this->apply(
            $fixture,
            $fixture["contributor_participant"],
            $this->contributor(
                "occurrence/a",
                "author/shared",
                "work/a",
                ContributorRole::Author,
                2,
                "Zoë Auteur"
            )
        );
        $second = $this->apply(
            $fixture,
            $fixture["contributor_participant"],
            $this->contributor(
                "occurrence/b",
                "author/shared",
                "work/b",
                ContributorRole::CoAuthor,
                1,
                "Zoë Auteur"
            )
        );

        self::assertSame(1, $this->rows($this->tableNames->authors()));
        self::assertSame(2, $this->rows($this->tableNames->authorContributorCredits()));
        self::assertSame(2, $this->rows($this->tableNames->authorCreditEvidence()));
        self::assertSame(2, $this->rows($this->tableNames->workContributors()));
        self::assertSame(
            ["author_contributor_credit", "work_contributor"],
            array_map(
                static fn (MigrationTargetMapping $mapping): string =>
                    $mapping->targetType(),
                $first["outcome"]->mappings()
            )
        );
        $edges = $fixture["authors"]->contributorsForWorks([
            new WorkId("work-target-a"),
            new WorkId("work-target-b"),
        ]);
        self::assertSame(2, $edges["work-target-a"][0]->position()->value());
        self::assertSame(ContributorRole::Author, $edges["work-target-a"][0]->role());
        self::assertSame(1, $edges["work-target-b"][0]->position()->value());
        self::assertSame(ContributorRole::CoAuthor, $edges["work-target-b"][0]->role());
        self::assertSame("migration", $this->database->get_var(
            "SELECT MIN(source_kind) FROM `{$this->tableNames->authorCreditEvidence()}`"
        ));
        self::assertSame(0, $this->rows(
            $this->tableNames->bibliographicProviderIdentities()
        ));

        $before = $this->authorTableCounts();
        $authorPage = (new WpdbBibliographicSearchProvider(
            $this->database,
            $this->tableNames
        ))->searchAuthors(new BibliographicTextSearchQuery("Auteur"));
        self::assertSame($authorId->value(), $authorPage->items()[0]->reference()
            ->authorId()?->value());
        $works = (new WpdbBibliographicAuthorWorkSearchProvider(
            $this->database,
            $this->tableNames
        ))->searchWorksForAuthor(
            BibliographicAuthorReference::canonical($authorId),
            0,
            10
        );
        self::assertSame(
            ["work-target-b", "work-target-a"],
            array_map(
                static fn ($item): string => $item->reference()->workId()?->value(),
                $works->items()
            )
        );
        self::assertSame($before, $this->authorTableCounts());

        $later = $this->laterRun($fixture, "multiple-works-replay");
        $this->apply(
            $later,
            $later["author_participant"],
            MigrationSourceRecord::typed(
                CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                "author/shared",
                new CatalogAuthorPlan("Zoë Auteur")
            )
        );
        $replay = $this->apply(
            $later,
            $later["contributor_participant"],
            $this->contributor(
                "occurrence/a",
                "author/shared",
                "work/a",
                ContributorRole::Author,
                2,
                "Zoë Auteur"
            )
        );
        self::assertSame(
            [MappingDisposition::Reused, MappingDisposition::Reused],
            array_map(
                static fn (MigrationTargetMapping $mapping): MappingDisposition =>
                    $mapping->disposition(),
                $replay["outcome"]->mappings()
            )
        );
        self::assertSame(2, $this->rows($this->tableNames->authorContributorCredits()));
        self::assertSame(2, $this->rows($this->tableNames->authorCreditEvidence()));
        self::assertSame(2, $this->rows($this->tableNames->workContributors()));
        self::assertSame($second["plan"]->dependencies(), [[
            "source_type" => CatalogAuthorMigrationParticipant::SOURCE_TYPE,
            "source_id" => "author/shared",
        ], [
            "source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE,
            "source_id" => "work/b",
        ]]);

        $this->database->query(
            "DELETE FROM `{$this->tableNames->authorCreditEvidence()}`"
        );
        $missingEvidence = $this->laterRun($later, "missing-evidence");
        $this->apply(
            $missingEvidence,
            $missingEvidence["author_participant"],
            MigrationSourceRecord::typed(
                CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                "author/shared",
                new CatalogAuthorPlan("Zoë Auteur")
            )
        );
        try {
            $this->apply(
                $missingEvidence,
                $missingEvidence["contributor_participant"],
                $this->contributor(
                    "occurrence/a",
                    "author/shared",
                    "work/a",
                    ContributorRole::Author,
                    2,
                    "Zoë Auteur"
                )
            );
            self::fail("Contributor replay without migration evidence was reused.");
        } catch (AuthorMigrationFailure $failure) {
            self::assertSame(
                AuthorMigrationReason::DivergentReplay,
                $failure->reason()
            );
        }
    }

    public function testMissingWrongAndConflictingDependenciesFailClosed(): void
    {
        $fixture = $this->fixture("conflicts");
        $record = $this->contributor(
            "occurrence/missing",
            "author/missing",
            "work/missing",
            ContributorRole::Author,
            1,
            "Missing"
        );
        $plan = $fixture["contributor_participant"]->plan(
            $record,
            $fixture["target"]
        );
        self::assertSame([
            [
                "source_type" => CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                "source_id" => "author/missing",
            ],
            [
                "source_type" => CatalogWorkMigrationParticipant::SOURCE_TYPE,
                "source_id" => "work/missing",
            ],
        ], $plan->dependencies());
        try {
            $this->apply($fixture, $fixture["contributor_participant"], $record);
            self::fail("Missing dependency was accepted.");
        } catch (AuthorMigrationFailure $failure) {
            self::assertSame(
                AuthorMigrationReason::MissingTargetReference,
                $failure->reason()
            );
        }

        $this->mapWork(
            $fixture,
            "work/only",
            "work-only",
            "Mapped Work"
        );
        try {
            $this->apply(
                $fixture,
                $fixture["contributor_participant"],
                $this->contributor(
                    "occurrence/missing-author",
                    "author/still-missing",
                    "work/only",
                    ContributorRole::Author,
                    1,
                    "No fallback"
                )
            );
            self::fail("Missing Author dependency was accepted.");
        } catch (AuthorMigrationFailure $failure) {
            self::assertSame(
                AuthorMigrationReason::MissingTargetReference,
                $failure->reason()
            );
        }

        $this->mapSourceTarget(
            $fixture,
            MigrationSourceRecord::typed(
                CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                "author/wrong-type",
                new CatalogAuthorPlan("Wrong type")
            ),
            "edition",
            "edition-not-an-author"
        );
        try {
            $this->apply(
                $fixture,
                $fixture["contributor_participant"],
                $this->contributor(
                    "occurrence/wrong-type",
                    "author/wrong-type",
                    "work/only",
                    ContributorRole::Author,
                    1,
                    "Wrong type"
                )
            );
            self::fail("Wrong dependency target type was accepted.");
        } catch (AuthorMigrationFailure $failure) {
            self::assertSame(
                AuthorMigrationReason::MissingTargetReference,
                $failure->reason()
            );
        }
        self::assertSame(0, $this->rows(
            $this->tableNames->authorContributorCredits()
        ));

        $author = $this->apply(
            $fixture,
            $fixture["author_participant"],
            MigrationSourceRecord::typed(
                CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                "author/exact",
                new CatalogAuthorPlan("Exact")
            )
        );
        $authorId = new AuthorId($author["outcome"]->mappings()[0]->targetId());
        $this->mapWork($fixture, "work/exact", "work-exact", "Exact Work");
        $fixture["authors"]->add(new Author(
            new AuthorId("unrelated-author"),
            "Unrelated"
        ));
        $fixture["authors"]->addContributor(new WorkContributor(
            new WorkId("work-exact"),
            new AuthorId("unrelated-author"),
            ContributorRole::Author,
            new ContributorPosition(1)
        ));

        try {
            $this->apply(
                $fixture,
                $fixture["contributor_participant"],
                $this->contributor(
                    "occurrence/conflict",
                    "author/exact",
                    "work/exact",
                    ContributorRole::CoAuthor,
                    1,
                    "Exact"
                )
            );
            self::fail("Occupied contributor position was overwritten.");
        } catch (AuthorMigrationFailure $failure) {
            self::assertSame(
                AuthorMigrationReason::ContributorPositionConflict,
                $failure->reason()
            );
        }
        self::assertSame(0, $this->rows($this->tableNames->authorContributorCredits()));
        self::assertSame(0, $this->rows($this->tableNames->authorCreditEvidence()));
        self::assertCount(
            1,
            $fixture["authors"]->contributorsForWorks([
                new WorkId("work-exact")
            ])["work-exact"]
        );
        self::assertSame(2, $this->rows($this->tableNames->authors()));
        self::assertNotNull($fixture["authors"]->find($authorId));
    }

    public function testApprovedAuthorAndExactEdgeAreReusedButDivergenceFails(): void
    {
        $fixture = $this->fixture("approved");
        $approved = new Author(
            new AuthorId("approved-author"),
            "Canonical presentation",
            AuthorIdentityStatus::Resolved,
            AuthorDisplayNameStatus::Observed
        );
        $fixture["authors"]->add($approved);
        $authorResult = $this->apply(
            $fixture,
            $fixture["author_participant"],
            MigrationSourceRecord::typed(
                CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                "author/approved",
                new CatalogAuthorPlan("Source spelling", $approved->id())
            )
        );
        self::assertSame(
            MappingDisposition::Reused,
            $authorResult["outcome"]->mappings()[0]->disposition()
        );
        self::assertSame(
            "Canonical presentation",
            $fixture["authors"]->find($approved->id())?->displayName()
        );

        $this->mapWork($fixture, "work/approved", "work-approved", "Approved Work");
        $fixture["authors"]->addContributor(new WorkContributor(
            new WorkId("work-approved"),
            $approved->id(),
            ContributorRole::CoAuthor,
            new ContributorPosition(3)
        ));
        $occurrence = $this->contributor(
            "occurrence/approved",
            "author/approved",
            "work/approved",
            ContributorRole::CoAuthor,
            3,
            "Source spelling"
        );
        $outcome = $this->apply(
            $fixture,
            $fixture["contributor_participant"],
            $occurrence
        )["outcome"];
        self::assertSame(
            [MappingDisposition::Created, MappingDisposition::Reused],
            array_map(
                static fn (MigrationTargetMapping $mapping): MappingDisposition =>
                    $mapping->disposition(),
                $outcome->mappings()
            )
        );
        self::assertSame(1, $this->rows($this->tableNames->authorContributorCredits()));
        self::assertSame(1, $this->rows($this->tableNames->workContributors()));

        try {
            $this->apply(
                $fixture,
                $fixture["contributor_participant"],
                $this->contributor(
                    "occurrence/duplicate-author",
                    "author/approved",
                    "work/approved",
                    ContributorRole::Author,
                    4,
                    "Source spelling"
                )
            );
            self::fail("The same Author was linked twice to one Work.");
        } catch (AuthorMigrationFailure $failure) {
            self::assertSame(
                AuthorMigrationReason::ContributorPositionConflict,
                $failure->reason()
            );
        }
        self::assertSame(1, $this->rows($this->tableNames->authorContributorCredits()));
        self::assertSame(1, $this->rows($this->tableNames->workContributors()));

        $later = $this->laterRun($fixture, "approved-divergent");
        $this->apply(
            $later,
            $later["author_participant"],
            MigrationSourceRecord::typed(
                CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                "author/approved",
                new CatalogAuthorPlan("Source spelling", $approved->id())
            )
        );
        try {
            $this->apply(
                $later,
                $later["contributor_participant"],
                $this->contributor(
                    "occurrence/approved",
                    "author/approved",
                    "work/approved",
                    ContributorRole::Author,
                    3,
                    "Changed occurrence"
                )
            );
            self::fail("Changed contributor occurrence was silently reused.");
        } catch (AuthorMigrationFailure $failure) {
            self::assertSame(AuthorMigrationReason::DivergentReplay, $failure->reason());
        }
        self::assertSame(1, $this->rows($this->tableNames->authorContributorCredits()));
        self::assertSame(1, $this->rows($this->tableNames->workContributors()));
    }

    public function testMigrationMaterializerReportsIdentityAndLateEdgeConflictsSafely(): void
    {
        $identity = $this->fixture("identity-conflict");
        $mapped = $this->apply(
            $identity,
            $identity["author_participant"],
            MigrationSourceRecord::typed(
                CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                "author/mapped",
                new CatalogAuthorPlan("Mapped Author")
            )
        );
        $mappedAuthorId = new AuthorId(
            $mapped["outcome"]->mappings()[0]->targetId()
        );
        $otherAuthorId = new AuthorId("other-linked-author");
        $identity["authors"]->add(new Author($otherAuthorId, "Other Author"));
        $this->mapWork(
            $identity,
            "work/identity",
            "work-identity",
            "Identity Work"
        );
        $record = $this->contributor(
            "occurrence/identity",
            "author/mapped",
            "work/identity",
            ContributorRole::Author,
            1,
            "Mapped Author"
        );
        $observation = $this->observe($identity, $record);
        $input = new MigrationAuthorCredit(
            new WorkId("work-identity"),
            $mappedAuthorId,
            ContributorRole::Author,
            new ContributorPosition(1),
            "Mapped Author",
            $observation->id(),
            $observation->createdAt()
        );
        $conflictingCredit = new AuthorContributorCredit(
            new AuthorContributorCreditId("identity-conflict-credit"),
            AuthorContributorCreditKey::fromSource(
                $input->workId(),
                $input->role(),
                $input->position(),
                $input->observedDisplayName(),
                $input->sourceIdentity()
            ),
            $input->workId(),
            $input->role(),
            $input->position(),
            $input->observedDisplayName(),
            $otherAuthorId,
            AuthorContributorCreditStatus::Linked,
            null,
            $identity["clock"]->now(),
            $identity["clock"]->now(),
            AuthorContributorCreditVersion::initial()
        );
        $identity["credits"]->create($conflictingCredit);
        $result = $identity["materializer"]->materializeMigrationAuthor($input);
        self::assertSame(AuthorMaterializationStatus::IdentityConflict, $result->status());
        self::assertSame($otherAuthorId->value(), $result->authorId()?->value());
        self::assertSame(
            AuthorMaterializationWriteDisposition::NotWritten,
            $result->providerClaim()
        );
        self::assertSame(0, $this->rows(
            $this->tableNames->bibliographicProviderIdentities()
        ));

        $race = $this->fixture("edge-race");
        $this->apply(
            $race,
            $race["author_participant"],
            MigrationSourceRecord::typed(
                CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                "author/race",
                new CatalogAuthorPlan("Race Author")
            )
        );
        $this->mapWork($race, "work/race", "work-race", "Race Work");
        $conflictingAuthor = new AuthorId("race-conflicting-author");
        $race["authors"]->add(new Author($conflictingAuthor, "Race Conflict"));
        $raceCountsBefore = $this->authorTableCounts();
        $race = $this->withWriter(
            $race,
            $race["authors"],
            new RacingMigrationCreditRepository(
                $race["credits"],
                $race["authors"],
                $conflictingAuthor
            )
        );
        try {
            $this->apply(
                $race,
                $race["contributor_participant"],
                $this->contributor(
                    "occurrence/race",
                    "author/race",
                    "work/race",
                    ContributorRole::Author,
                    1,
                    "Race Author"
                )
            );
            self::fail("Late contributor-edge conflict was committed.");
        } catch (AuthorMigrationFailure $failure) {
            self::assertSame(
                AuthorMigrationReason::ContributorPositionConflict,
                $failure->reason()
            );
        }
        self::assertSame($raceCountsBefore, $this->authorTableCounts());
    }

    public function testPlanValidationAndLateFailureLeaveNoPartialGraph(): void
    {
        try {
            new CatalogAuthorPlan(" \t \n ");
            self::fail("Blank Author name was accepted.");
        } catch (ValidationException) {
        }
        try {
            /** @phpstan-ignore-next-line Deliberate unsupported role proof. */
            new CatalogWorkContributorPlan(
                "author",
                "work",
                "translator",
                new ContributorPosition(1),
                "Translator"
            );
            self::fail("Unsupported Work role was accepted.");
        } catch (TypeError) {
        }

        $fixture = $this->fixture("rollback");
        $author = $this->apply(
            $fixture,
            $fixture["author_participant"],
            MigrationSourceRecord::typed(
                CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                "author/rollback",
                new CatalogAuthorPlan("Rollback Author")
            )
        );
        $this->mapWork($fixture, "work/rollback", "work-rollback", "Rollback Work");
        $record = $this->contributor(
            "occurrence/rollback",
            "author/rollback",
            "work/rollback",
            ContributorRole::Author,
            1,
            "Rollback Author"
        );
        $plan = $fixture["contributor_participant"]->plan(
            $record,
            $fixture["target"]
        );
        $observation = $this->observe($fixture, $record);
        $fixture["ledger"] = new FailingCommitOutcomeMigrationLedger(
            $fixture["ledger"]
        );
        $fixture = $this->withWriter(
            $fixture,
            $fixture["authors"],
            $fixture["credits"]
        );
        $fixture = $this->withRunServices($fixture);
        try {
            $fixture["commit"]->commit(
                $fixture["run"],
                $observation,
                fn (): MigrationRecordOutcome =>
                    $fixture["contributor_participant"]->apply(
                        $record,
                        $observation,
                        $plan,
                        $fixture["target"]
                    )
            );
            self::fail("Late outcome failure was hidden.");
        } catch (PersistenceException $exception) {
            self::assertSame(
                "Injected migration outcome failure after first mapping.",
                $exception->getMessage()
            );
        }
        self::assertSame(0, $this->rows($this->tableNames->authorContributorCredits()));
        self::assertSame(0, $this->rows($this->tableNames->authorCreditEvidence()));
        self::assertSame(0, $this->rows($this->tableNames->workContributors()));
        self::assertSame(
            "observed",
            $this->database->get_var($this->database->prepare(
                "SELECT processing_status FROM `{$this->tableNames->migrationSourceObservations()}` WHERE observation_id=%s",
                $observation->id()
            ))
        );
        self::assertNotNull($fixture["authors"]->find(new AuthorId(
            $author["outcome"]->mappings()[0]->targetId()
        )));
        self::assertSame(0, (int) $this->database->get_var(
            $this->database->prepare(
                "SELECT COUNT(*) FROM `{$this->tableNames->migrationTargetMappings()}` WHERE observation_id=%s",
                $observation->id()
            )
        ));
    }

    public function testInjectedAuthorCreditEvidenceAndEdgeFailuresRollback(): void
    {
        $authorFailure = $this->fixture("fail-author");
        $authorFailure = $this->withWriter(
            $authorFailure,
            new FailingMigrationAuthorRepository(
                $authorFailure["authors"],
                failAuthor: true
            ),
            $authorFailure["credits"]
        );
        try {
            $this->apply(
                $authorFailure,
                $authorFailure["author_participant"],
                MigrationSourceRecord::typed(
                    CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                    "author/fail",
                    new CatalogAuthorPlan("Fail Author")
                )
            );
            self::fail("Injected Author failure was hidden.");
        } catch (PersistenceException) {
        }
        self::assertSame(0, $this->rows($this->tableNames->authors()));
        self::assertSame(0, $this->rows($this->tableNames->migrationTargetMappings()));

        foreach (["credit", "evidence", "edge"] as $failureKind) {
            $fixture = $this->fixture("fail-{$failureKind}");
            $this->apply(
                $fixture,
                $fixture["author_participant"],
                MigrationSourceRecord::typed(
                    CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                    "author/{$failureKind}",
                    new CatalogAuthorPlan("Failure Author {$failureKind}")
                )
            );
            $this->mapWork(
                $fixture,
                "work/{$failureKind}",
                "work-failure-{$failureKind}",
                "Failure Work {$failureKind}"
            );
            $authorRepository = new FailingMigrationAuthorRepository(
                $fixture["authors"],
                failContributor: $failureKind === "edge"
            );
            $creditRepository = new FailingMigrationCreditRepository(
                $fixture["credits"],
                failCredit: $failureKind === "credit",
                failEvidence: $failureKind === "evidence"
            );
            $fixture = $this->withWriter(
                $fixture,
                $authorRepository,
                $creditRepository
            );
            $mappingsBefore = $this->rows(
                $this->tableNames->migrationTargetMappings()
            );
            try {
                $this->apply(
                    $fixture,
                    $fixture["contributor_participant"],
                    $this->contributor(
                        "occurrence/fail-{$failureKind}",
                        "author/{$failureKind}",
                        "work/{$failureKind}",
                        ContributorRole::Author,
                        1,
                        "Failure Author {$failureKind}"
                    )
                );
                self::fail("Injected {$failureKind} failure was hidden.");
            } catch (PersistenceException) {
            }
            self::assertSame(0, $this->rows(
                $this->tableNames->authorContributorCredits()
            ));
            self::assertSame(0, $this->rows(
                $this->tableNames->authorCreditEvidence()
            ));
            self::assertSame(0, $this->rows(
                $this->tableNames->workContributors()
            ));
            self::assertSame(
                $mappingsBefore,
                $this->rows($this->tableNames->migrationTargetMappings())
            );
        }
    }

    /** @return array<string, mixed> */
    private function fixture(string $suffix): array
    {
        $library = new LibraryId("author-migration-library-{$suffix}");
        (new WpdbLibraryRepository($this->database, $this->tableNames))->add(
            Library::privateLibrary($library, new LibraryName("Library {$suffix}"))
        );
        $ledger = new WpdbMigrationLedgerRepository($this->database, $this->tableNames);
        $transactions = new WpdbTransactionManager($this->database);
        $clock = new AuthorMigrationTestClock();
        $run = $ledger->beginOrResume(MigrationRun::start(
            "author-migration-run-{$suffix}",
            "synthetic",
            "snapshot-{$suffix}",
            hash("sha256", "snapshot-{$suffix}"),
            "test-1",
            "2.30.0",
            new UserId("author-migration-user-{$suffix}"),
            $library,
            MigrationMode::Apply,
            $clock->now()
        ));
        $works = new WpdbWorkRepository($this->database, $this->tableNames);
        $authors = new WpdbAuthorRepository($this->database, $this->tableNames);
        $credits = new WpdbAuthorContributorCreditRepository(
            $this->database,
            $this->tableNames
        );
        $ids = new AuthorMigrationTestIds($suffix);
        $materializer = new CanonicalAuthorMaterializer(
            $authors,
            new WpdbBibliographicProviderIdentityRepository(
                $this->database,
                $this->tableNames
            ),
            $credits,
            $ids,
            $clock
        );
        $writer = new AuthorMigrationWriter(
            $ledger,
            $ids,
            $authors,
            $works,
            $credits,
            $materializer
        );
        $fixture = [
            "library" => $library,
            "ledger" => $ledger,
            "transactions" => $transactions,
            "clock" => $clock,
            "run" => $run,
            "works" => $works,
            "authors" => $authors,
            "credits" => $credits,
            "ids" => $ids,
            "materializer" => $materializer,
            "author_participant" => new CatalogAuthorMigrationParticipant($writer),
            "contributor_participant" =>
                new CatalogWorkContributorMigrationParticipant($writer),
        ];
        return $this->withRunServices($fixture);
    }

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    private function withWriter(
        array $fixture,
        WritableAuthorRepository $authors,
        AuthorContributorCreditRepository $credits
    ): array {
        $materializer = new CanonicalAuthorMaterializer(
            $authors,
            new WpdbBibliographicProviderIdentityRepository(
                $this->database,
                $this->tableNames
            ),
            $credits,
            $fixture["ids"],
            $fixture["clock"]
        );
        $writer = new AuthorMigrationWriter(
            $fixture["ledger"],
            $fixture["ids"],
            $authors,
            $fixture["works"],
            $credits,
            $materializer
        );
        $fixture["author_participant"] =
            new CatalogAuthorMigrationParticipant($writer);
        $fixture["contributor_participant"] =
            new CatalogWorkContributorMigrationParticipant($writer);
        $fixture["materializer"] = $materializer;
        return $fixture;
    }

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    private function withRunServices(array $fixture): array
    {
        $fixture["target"] = new MigrationPlanningTarget(
            new PersonalMigrationTarget(
                $fixture["run"]->targetUserId(),
                $fixture["library"],
                new LibraryName("Migration target"),
                new PersonalMigrationTargetReadiness([])
            )
        );
        $fixture["observe"] = new ObserveSourceRecordService(
            $fixture["ledger"],
            $fixture["clock"]
        );
        $fixture["commit"] = new CommitMigrationRecordService(
            $fixture["ledger"],
            $fixture["transactions"],
            $fixture["clock"]
        );
        return $fixture;
    }

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    private function laterRun(array $fixture, string $suffix): array
    {
        $fixture["ledger"]->releaseRunLock($fixture["run"]->id());
        $fixture["run"] = $fixture["ledger"]->beginOrResume(MigrationRun::start(
            "author-migration-run-{$suffix}",
            "synthetic",
            "snapshot-{$suffix}",
            hash("sha256", "snapshot-{$suffix}"),
            "test-1",
            "2.30.0",
            $fixture["run"]->targetUserId(),
            $fixture["library"],
            MigrationMode::Apply,
            $fixture["clock"]->now()
        ));
        return $this->withRunServices($fixture);
    }

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    private function laterRunForSourceFamily(
        array $fixture,
        string $suffix,
        string $sourceFamily
    ): array {
        $fixture["ledger"]->releaseRunLock($fixture["run"]->id());
        $fixture["run"] = $fixture["ledger"]->beginOrResume(MigrationRun::start(
            "author-migration-run-{$suffix}",
            $sourceFamily,
            "snapshot-{$suffix}",
            hash("sha256", "snapshot-{$suffix}"),
            "test-1",
            "2.30.0",
            $fixture["run"]->targetUserId(),
            $fixture["library"],
            MigrationMode::Apply,
            $fixture["clock"]->now()
        ));
        return $this->withRunServices($fixture);
    }

    /** @param array<string, mixed> $fixture */
    private function mapWork(
        array $fixture,
        string $sourceId,
        string $workId,
        string $title
    ): void {
        $work = new Work(new WorkId($workId), $title);
        $fixture["works"]->add($work);
        $record = new MigrationSourceRecord(
            CatalogWorkMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            ["approved_work_id" => $workId]
        );
        $observation = $this->observe($fixture, $record);
        $fixture["commit"]->commit(
            $fixture["run"],
            $observation,
            static fn (): MigrationRecordOutcome => MigrationRecordOutcome::mapped([
                new MigrationTargetMapping(
                    "work",
                    $workId,
                    MappingDisposition::Reused
                ),
            ])
        );
    }

    /** @param array<string, mixed> $fixture */
    private function mapSourceTarget(
        array $fixture,
        MigrationSourceRecord $record,
        string $targetType,
        string $targetId
    ): void {
        $observation = $this->observe($fixture, $record);
        $fixture["commit"]->commit(
            $fixture["run"],
            $observation,
            static fn (): MigrationRecordOutcome => MigrationRecordOutcome::mapped([
                new MigrationTargetMapping(
                    $targetType,
                    $targetId,
                    MappingDisposition::Reused
                ),
            ])
        );
    }

    private function contributor(
        string $sourceId,
        string $authorSourceId,
        string $workSourceId,
        ContributorRole $role,
        int $position,
        string $displayName
    ): MigrationSourceRecord {
        return MigrationSourceRecord::typed(
            CatalogWorkContributorMigrationParticipant::SOURCE_TYPE,
            $sourceId,
            new CatalogWorkContributorPlan(
                $authorSourceId,
                $workSourceId,
                $role,
                new ContributorPosition($position),
                $displayName
            )
        );
    }

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    private function apply(
        array $fixture,
        MigrationParticipant $participant,
        MigrationSourceRecord $record
    ): array {
        $plan = $participant->plan($record, $fixture["target"]);
        $observation = $this->observe($fixture, $record);
        $outcome = $fixture["commit"]->commit(
            $fixture["run"],
            $observation,
            fn (): MigrationRecordOutcome => $participant->apply(
                $record,
                $observation,
                $plan,
                $fixture["target"]
            )
        );
        return compact("plan", "observation", "outcome");
    }

    /** @param array<string, mixed> $fixture */
    private function observe(
        array $fixture,
        MigrationSourceRecord $record
    ): SourceObservation {
        return $fixture["observe"]->observe(
            $fixture["run"],
            $record->sourceType(),
            $record->sourceId(),
            $record->payloadHash(),
            $record->payload()
        );
    }

    private function rows(string $table): int
    {
        return (int) $this->database->get_var("SELECT COUNT(*) FROM `{$table}`");
    }

    /** @return array<string, int> */
    private function authorTableCounts(): array
    {
        return [
            "authors" => $this->rows($this->tableNames->authors()),
            "credits" => $this->rows(
                $this->tableNames->authorContributorCredits()
            ),
            "evidence" => $this->rows(
                $this->tableNames->authorCreditEvidence()
            ),
            "contributors" => $this->rows(
                $this->tableNames->workContributors()
            ),
            "provider_claims" => $this->rows(
                $this->tableNames->bibliographicProviderIdentities()
            ),
            "mappings" => $this->rows(
                $this->tableNames->migrationTargetMappings()
            ),
        ];
    }
}
