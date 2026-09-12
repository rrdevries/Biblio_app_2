<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Metadata\Search\{
    BibliographicProviderEntityIdentity,
    BibliographicWorkReference,
    BibliographicWorkSelectorCodec
};
use Biblio\Core\Catalog\WorkId;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicProviderIdentityRepository;

final class BibliographicWorkSelectorPersistenceTest extends PersistenceIntegrationTestCase
{
    private const string SECRET = "integration-bibliographic-work-selector";

    public function testCompositeVerificationRevalidatesCurrentMappingWithoutWriting(): void
    {
        $this->insertWork("work-current", "Current Work");
        $this->insertWork("work-other", "Other Work");
        $provider = BibliographicProviderEntityIdentity::work(
            "open_library",
            "/works/OL77W"
        );
        $repository = new WpdbBibliographicProviderIdentityRepository(
            $this->database,
            $this->tableNames
        );
        $repository->claimWork(
            $provider->providerKey(),
            "work",
            $provider->providerRecordId(),
            new WorkId("work-current")
        );
        $codec = new BibliographicWorkSelectorCodec(self::SECRET, $repository);
        $selector = $codec->encode(BibliographicWorkReference::canonical(
            new WorkId("work-current"),
            $provider
        ));

        $decoded = $codec->decode($selector);
        self::assertSame("work-current", $decoded->workId()?->value());
        self::assertSame("/works/OL77W", $decoded->providerIdentity()?->providerRecordId());
        self::assertSame(1, $this->mappingCount());

        self::assertSame(1, $this->database->delete(
            $this->tableNames->bibliographicProviderIdentities(),
            [
                "provider_key" => "open_library",
                "source_entity_type" => "work",
                "provider_record_id" => "/works/OL77W",
                "target_type" => "work",
            ],
            ["%s", "%s", "%s", "%s"]
        ));
        $this->assertRejected($codec, $selector);

        $repository->claimWork(
            $provider->providerKey(),
            "work",
            $provider->providerRecordId(),
            new WorkId("work-other")
        );
        $this->assertRejected($codec, $selector);
        self::assertSame(1, $this->mappingCount());
    }

    private function assertRejected(
        BibliographicWorkSelectorCodec $codec,
        string $selector
    ): void {
        try {
            $codec->decode($selector);
            self::fail("A stale composite Work selector was accepted.");
        } catch (ValidationException) {
            self::addToAssertionCount(1);
        }
    }

    private function insertWork(string $id, string $title): void
    {
        self::assertSame(1, $this->database->insert($this->tableNames->works(), [
            "work_id" => $id,
            "work_title" => $title,
            "work_title_status" => "librarian_confirmed",
        ]));
    }

    private function mappingCount(): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->bibliographicProviderIdentities()}`"
        );
    }
}
