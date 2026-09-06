<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Application;

use Biblio\Core\Application\Metadata\MetadataCandidate;
use Biblio\Core\Application\Metadata\MetadataClock;
use Biblio\Core\Application\Metadata\MetadataField;
use Biblio\Core\Application\Metadata\MetadataFieldReview;
use Biblio\Core\Application\Metadata\MetadataFieldReviewRepository;
use Biblio\Core\Application\Metadata\MetadataFieldReviewService;
use Biblio\Core\Application\Metadata\MetadataMatchMethod;
use Biblio\Core\Application\Metadata\MetadataRecordId;
use Biblio\Core\Application\TransactionManager;
use Biblio\Core\Catalog\CanonicalIsbnIdentity;
use Biblio\Core\Catalog\Isbn13;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class MetadataFieldReviewServiceTest extends TestCase
{
    public function testCandidateFieldsAreIngestedAtomicallyWithProviderEvidence(): void
    {
        $repository = new MetadataReviewInMemoryRepository();
        $clock = new MetadataReviewFixedClock(new DateTimeImmutable(
            "2026-09-06T12:00:00+00:00"
        ));
        $service = new MetadataFieldReviewService(
            $repository,
            new MetadataReviewPassThroughTransactionManager(),
            $clock
        );
        $recordId = new MetadataRecordId("lookup-9780441172719");

        $service->ingestCandidate($recordId, $this->candidate("open_library", "OL1M"));
        $result = $service->ingestCandidate($recordId, $this->candidate("google_books", "GB1"));

        self::assertSame([
            "title",
            "contributors",
            "languages",
            "publishers",
            "publication_date",
            "page_count",
            "format",
        ], array_keys($result));
        self::assertArrayNotHasKey("subtitle", $result);
        self::assertSame(
            ["Frank Herbert", "Brian Herbert"],
            $result["contributors"]->activeProposals()[0]->value()->value()
        );
        self::assertCount(
            2,
            $result["contributors"]->activeProposals()[0]->evidence()
        );
    }

    private function candidate(string $provider, string $record): MetadataCandidate
    {
        $isbn = CanonicalIsbnIdentity::fromIsbn(new Isbn13("9780441172719"));
        return new MetadataCandidate(
            $provider,
            $record,
            new DateTimeImmutable("2026-09-06T11:00:00+00:00"),
            MetadataMatchMethod::ExactIsbn,
            $isbn,
            $isbn,
            "Dune",
            null,
            ["Frank Herbert", "Brian Herbert"],
            ["en"],
            ["Ace"],
            "1984-09-01",
            896,
            "paperback",
            null
        );
    }
}

final class MetadataReviewInMemoryRepository implements MetadataFieldReviewRepository
{
    /** @var array<string, MetadataFieldReview> */
    private array $reviews = [];

    public function find(
        MetadataRecordId $recordId,
        MetadataField $field
    ): ?MetadataFieldReview {
        return $this->reviews[$this->key($recordId, $field)] ?? null;
    }

    public function findForUpdate(
        MetadataRecordId $recordId,
        MetadataField $field,
        DateTimeImmutable $whenMissing
    ): MetadataFieldReview {
        return $this->reviews[$this->key($recordId, $field)]
            ?? MetadataFieldReview::empty($recordId, $field, $whenMissing);
    }

    public function save(MetadataFieldReview $review): void
    {
        $this->reviews[$this->key($review->recordId(), $review->field())] = $review;
    }

    private function key(MetadataRecordId $recordId, MetadataField $field): string
    {
        return $recordId->value() . "\0" . $field->value;
    }
}

final readonly class MetadataReviewPassThroughTransactionManager implements TransactionManager
{
    public function run(callable $operation): mixed { return $operation(); }
}

final readonly class MetadataReviewFixedClock implements MetadataClock
{
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable
    {
        return $this->now->setTimezone(new DateTimeZone("UTC"));
    }
}
