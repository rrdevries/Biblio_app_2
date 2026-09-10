<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderIdentityConflict;
use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderIdentityRepository;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\WorkId;
use wpdb;

final readonly class WpdbBibliographicProviderIdentityRepository implements
    BibliographicProviderIdentityRepository
{
    public function __construct(private wpdb $database, private CoreTableNames $tables) {}

    public function findWork(string $provider, string $sourceType, string $recordId): ?WorkId
    {
        $value = $this->database->get_var($this->database->prepare(
            "SELECT work_id FROM `{$this->tables->bibliographicProviderIdentities()}` "
                . "WHERE provider_key=%s AND source_entity_type=%s "
                . "AND provider_record_id=%s AND target_type='work'",
            $provider, $sourceType, $recordId
        ));
        return is_string($value) ? new WorkId($value) : null;
    }

    public function findEdition(string $provider, string $recordId): ?EditionId
    {
        $value = $this->database->get_var($this->database->prepare(
            "SELECT edition_id FROM `{$this->tables->bibliographicProviderIdentities()}` "
                . "WHERE provider_key=%s AND source_entity_type='edition' "
                . "AND provider_record_id=%s AND target_type='edition'",
            $provider, $recordId
        ));
        return is_string($value) ? new EditionId($value) : null;
    }

    public function claimWork(string $provider, string $sourceType, string $recordId, WorkId $workId): void
    {
        $this->claim($provider, $sourceType, $recordId, "work", $workId->value(), null);
    }

    public function claimEdition(string $provider, string $recordId, EditionId $editionId): void
    {
        $this->claim($provider, "edition", $recordId, "edition", null, $editionId->value());
    }

    private function claim(
        string $provider,
        string $sourceType,
        string $recordId,
        string $targetType,
        ?string $workId,
        ?string $editionId
    ): void {
        $previous = $this->database->suppress_errors(true);
        try {
            $inserted = $this->database->insert(
                $this->tables->bibliographicProviderIdentities(),
                [
                    "provider_key" => $provider,
                    "source_entity_type" => $sourceType,
                    "provider_record_id" => $recordId,
                    "target_type" => $targetType,
                    "work_id" => $workId,
                    "edition_id" => $editionId,
                ],
                ["%s", "%s", "%s", "%s", "%s", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previous);
        }
        if ($inserted === 1) { return; }
        $writeError = $this->database->last_error;
        $existing = $targetType === "work"
            ? $this->findWork($provider, $sourceType, $recordId)?->value()
            : $this->findEdition($provider, $recordId)?->value();
        $expected = $targetType === "work" ? $workId : $editionId;
        if ($existing === $expected) { return; }
        if (WpdbErrorTranslator::conflict($writeError) !== null) {
            throw new BibliographicProviderIdentityConflict();
        }
        throw WpdbErrorTranslator::writeFailure(
            "Could not persist bibliographic provider identity.",
            $writeError
        );
    }
}
