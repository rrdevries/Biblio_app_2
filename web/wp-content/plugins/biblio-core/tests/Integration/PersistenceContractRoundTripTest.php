<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Borrowing\ExternalLoan;
use Biblio\Core\Borrowing\ExternalLoanId;
use Biblio\Core\Catalog\Edition;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\Item;
use Biblio\Core\Catalog\ItemId;
use Biblio\Core\Catalog\Work;
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Identity\IdentifierConstraints;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbEditionRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbExternalLoanRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbExternalLoanWriter;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbItemRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbReadingRoundRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbWorkRepository;
use Biblio\Core\Library\AdditionalPermissions;
use Biblio\Core\Library\Library;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\LibraryMembership;
use Biblio\Core\Library\LibraryMembershipAssignment;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use Biblio\Core\Reading\ReadingRound;
use Biblio\Core\Reading\ReadingDate;
use Biblio\Core\Reading\ReadingRoundId;
use Biblio\Core\Reading\ReadingSource;
use DateTimeImmutable;

final class PersistenceContractRoundTripTest extends
    PersistenceIntegrationTestCase
{
    public function testMaximumLengthIdentifiersAndCurrentStatesRoundTrip(): void
    {
        $userId = new UserId($this->maximumId("u"));
        $libraryId = new LibraryId($this->maximumId("l"));
        $workId = new WorkId($this->maximumId("w"));
        $editionId = new EditionId($this->maximumId("e"));
        $itemId = new ItemId($this->maximumId("i"));
        $loanId = new ExternalLoanId($this->maximumId("x"));
        $roundId = new ReadingRoundId($this->maximumId("r"));
        $library = Library::privateLibrary($libraryId);
        $membership = new LibraryMembershipAssignment(
            $libraryId,
            $userId,
            new LibraryMembership(
                ManagementRole::Manager,
                UseAccess::Direct,
                MembershipStatus::Active,
                AdditionalPermissions::fromValues(
                    " lending ",
                    "collecties"
                )
            )
        );
        $work = new Work($workId, str_repeat("é", Work::MAX_TITLE_LENGTH));
        $edition = new Edition($editionId, $workId);
        $item = Item::active($itemId, $libraryId, $editionId);
        $loan = ExternalLoan::active(
            $loanId,
            $userId,
            $workId,
            new DateTimeImmutable("2026-08-17T10:00:00+00:00")
        );
        $round = ReadingRound::active(
            $roundId,
            $userId,
            $workId,
            ReadingSource::libraryItem($itemId),
            ReadingDate::exact(2026, 8, 17),
            new DateTimeImmutable("2026-08-17T11:00:00+00:00")
        );

        $libraries = new WpdbLibraryRepository(
            $this->database,
            $this->tableNames
        );
        $memberships = new WpdbLibraryMembershipRepository(
            $this->database,
            $this->tableNames
        );
        $works = new WpdbWorkRepository(
            $this->database,
            $this->tableNames
        );
        $editions = new WpdbEditionRepository(
            $this->database,
            $this->tableNames
        );
        $items = new WpdbItemRepository(
            $this->database,
            $this->tableNames
        );
        $loanReader = new WpdbExternalLoanRepository(
            $this->database,
            $this->tableNames
        );
        $loanWriter = new WpdbExternalLoanWriter(
            $this->database,
            $this->tableNames
        );
        $rounds = new WpdbReadingRoundRepository(
            $this->database,
            $this->tableNames
        );

        $libraries->add($library);
        $memberships->add($membership);
        $works->add($work);
        $editions->add($edition);
        $items->add($item);
        $loanWriter->add($loan);
        $rounds->addForUser($userId, $round);

        self::assertSame(
            $libraryId->value(),
            $libraries->find($libraryId)?->id()->value()
        );
        self::assertSame(
            [" lending ", "collecties"],
            $memberships->findFor($libraryId, $userId)
                ?->membership()->additionalPermissions()->values()
        );
        self::assertSame(
            $work->title(),
            $works->find($workId)?->title()
        );
        self::assertSame(
            $workId->value(),
            $editions->find($editionId)?->workId()->value()
        );
        self::assertSame(
            $editionId->value(),
            $items->findInLibrary($itemId, $libraryId)?->editionId()->value()
        );
        self::assertSame(
            $loanId->value(),
            $loanReader->findForUser($loanId, $userId)?->id()->value()
        );
        self::assertSame(
            $roundId->value(),
            $rounds->findForUser($roundId, $userId)?->id()->value()
        );
    }

    private function maximumId(string $suffix): string
    {
        return str_repeat("é", IdentifierConstraints::MAX_LENGTH - 1)
            . $suffix;
    }
}
