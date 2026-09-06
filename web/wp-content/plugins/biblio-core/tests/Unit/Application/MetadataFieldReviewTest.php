<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Metadata\MetadataField;
use Biblio\Core\Application\Metadata\MetadataFieldConfirmationState;
use Biblio\Core\Application\Metadata\MetadataFieldEvidence;
use Biblio\Core\Application\Metadata\MetadataFieldProposal;
use Biblio\Core\Application\Metadata\MetadataFieldProposalState;
use Biblio\Core\Application\Metadata\MetadataFieldReview;
use Biblio\Core\Application\Metadata\MetadataFieldValue;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Application\Metadata\MetadataRecordId;
use Biblio\Core\Catalog\IsbnType;
use Biblio\Core\Identity\UserId;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use LogicException;

final class MetadataFieldReviewTest extends TestCase
{
    public function testIdenticalProviderValuesShareOneProposalAndRetainAllEvidence(): void
    {
        $review = $this->review(MetadataField::Title);
        $value = new MetadataFieldValue("Dune");

        $review->observe($value, $this->evidence("open_library", "OL1M", 1), $this->at(1));
        $review->observe($value, $this->evidence("google_books", "GB1", 2), $this->at(2));
        $review->observe($value, $this->evidence("open_library", "OL1M", 3), $this->at(3));

        self::assertCount(1, $review->activeProposals());
        self::assertCount(2, $review->proposals()[0]->evidence());
        self::assertSame(2, $review->proposals()[0]->evidence()[0]->observationCount());
    }

    public function testMatchingCanonicalValueAddsSupportingEvidenceWithoutConfirmation(): void
    {
        $review = $this->review(MetadataField::Title);
        $review->recordUnconfirmedValue(new MetadataFieldValue("Dune"), $this->at(1));

        $review->observe(
            new MetadataFieldValue("Dune"),
            $this->evidence("open_library", "OL1M", 2),
            $this->at(2)
        );

        self::assertSame(
            MetadataFieldConfirmationState::Unconfirmed,
            $review->confirmationState()
        );
        self::assertCount(0, $review->activeProposals());
        self::assertSame(MetadataFieldProposalState::Supporting, $review->proposals()[0]->state());
    }

    public function testRejectedContentNeverReactivatesButDifferentContentCanBeProposed(): void
    {
        $review = $this->review(MetadataField::Title);
        $dune = new MetadataFieldValue("Dune");
        $messiah = new MetadataFieldValue("Dune Messiah");
        $actor = new UserId("user-1");

        $review->observe($dune, $this->evidence("open_library", "OL1M", 1), $this->at(1));
        $review->reject($dune->hash(), $actor, $this->at(2));
        $review->observe($dune, $this->evidence("google_books", "GB1", 3), $this->at(3));
        $review->observe($messiah, $this->evidence("google_books", "GB2", 4), $this->at(4));

        self::assertSame(MetadataFieldProposalState::Rejected, $this->proposal($review, $dune)->state());
        self::assertCount(2, $this->proposal($review, $dune)->evidence());
        self::assertSame([$messiah->hash()], array_map(
            static fn (MetadataFieldProposal $proposal): string => $proposal->value()->hash(),
            $review->activeProposals()
        ));
    }

    public function testExplicitConfirmationReplacesCanonicalAndSupersedesOtherActiveValues(): void
    {
        $review = $this->review(MetadataField::Title);
        $old = new MetadataFieldValue("Duin");
        $chosen = new MetadataFieldValue("Dune");
        $other = new MetadataFieldValue("Dune: A Novel");
        $actor = new UserId("user-1");
        $review->correctManually($old, $actor, $this->at(1));
        $review->observe($chosen, $this->evidence("open_library", "OL1M", 2), $this->at(2));
        $review->observe($other, $this->evidence("google_books", "GB1", 3), $this->at(3));

        self::assertTrue($review->hasConflict());
        self::assertSame("Duin", $review->canonicalValue()?->value());

        $review->confirm($chosen->hash(), $actor, $this->at(4));

        self::assertSame("Dune", $review->canonicalValue()?->value());
        self::assertSame(MetadataFieldConfirmationState::UserConfirmed, $review->confirmationState());
        self::assertSame(MetadataFieldProposalState::Confirmed, $this->proposal($review, $chosen)->state());
        self::assertSame(MetadataFieldProposalState::Superseded, $this->proposal($review, $other)->state());
        self::assertCount(0, $review->activeProposals());
    }

    public function testIntentionalBlankBlocksMetadataUntilExplicitReopen(): void
    {
        $review = $this->review(MetadataField::Contributors);
        $authors = new MetadataFieldValue(["Frank Herbert", "Brian Herbert"]);
        $actor = new UserId("user-1");

        $review->markIntentionallyBlank($actor, $this->at(1));
        $review->observe(
            $authors,
            $this->evidence("open_library", "OL1M", 2),
            $this->at(2)
        );

        self::assertSame(
            MetadataFieldConfirmationState::IntentionallyBlank,
            $review->confirmationState()
        );
        self::assertCount(0, $review->activeProposals());
        self::assertSame(
            MetadataFieldProposalState::BlockedByIntentionalBlank,
            $this->proposal($review, $authors)->state()
        );
        self::assertSame(["Frank Herbert", "Brian Herbert"], $authors->value());

        $review->allowProposalsAgain($this->at(3));

        self::assertSame(MetadataFieldConfirmationState::Unknown, $review->confirmationState());
        self::assertCount(1, $review->activeProposals());
    }

    public function testUnconfirmedInputCannotDowngradeUserConfirmedValue(): void
    {
        $review = $this->review(MetadataField::Title);
        $review->correctManually(
            new MetadataFieldValue("Dune"),
            new UserId("user-1"),
            $this->at(1)
        );

        $this->expectException(LogicException::class);
        $review->recordUnconfirmedValue(
            new MetadataFieldValue("Duin"),
            $this->at(2)
        );
    }

    private function review(MetadataField $field): MetadataFieldReview
    {
        return MetadataFieldReview::empty(
            new MetadataRecordId("record-1"),
            $field,
            $this->at(0)
        );
    }

    private function evidence(string $provider, string $record, int $minute): MetadataFieldEvidence
    {
        return new MetadataFieldEvidence(
            $provider,
            $record,
            $this->at($minute),
            $this->at($minute),
            1,
            MetadataMatchMethod::ExactIsbn,
            IsbnType::Isbn13,
            "9780441172719"
        );
    }

    private function at(int $minute): DateTimeImmutable
    {
        return new DateTimeImmutable(
            sprintf("2026-09-05 10:%02d:00.000000", $minute),
            new DateTimeZone("UTC")
        );
    }

    private function proposal(
        MetadataFieldReview $review,
        MetadataFieldValue $value
    ): MetadataFieldProposal {
        foreach ($review->proposals() as $proposal) {
            if ($proposal->value()->equals($value)) {
                return $proposal;
            }
        }
        self::fail("Proposal not found.");
    }
}
