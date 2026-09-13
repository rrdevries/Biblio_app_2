<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderIdentityConflict;
use Biblio\Core\Application\Metadata\Discovery\BibliographicProviderIdentityRepository;
use Biblio\Core\Application\Metadata\Author\AuthorProviderClaimRace;
use Biblio\Core\Application\Metadata\Author\AuthorProviderIdentityConflict;
use Biblio\Core\Application\Metadata\Author\AuthorProviderIdentityRepository;
use Biblio\Core\Application\Metadata\Search\BibliographicProviderEntityIdentity;
use Biblio\Core\Application\Metadata\Search\BibliographicAuthorWorkMappingLookup;
use Biblio\Core\Application\Metadata\Search\BibliographicWorkProviderIdentityLookup;
use Biblio\Core\Catalog\AuthorId;
use Biblio\Core\Catalog\EditionId;
use Biblio\Core\Catalog\WorkId;
use wpdb;

final readonly class WpdbBibliographicProviderIdentityRepository implements
    BibliographicProviderIdentityRepository,
    AuthorProviderIdentityRepository,
    BibliographicAuthorWorkMappingLookup,
    BibliographicWorkProviderIdentityLookup
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

    public function findAuthor(string $provider, string $recordId): ?AuthorId
    {
        $value = $this->database->get_var($this->database->prepare(
            "SELECT author_id FROM `{$this->tables->bibliographicProviderIdentities()}` "
                . "WHERE provider_key=%s AND source_entity_type='author' "
                . "AND provider_record_id=%s AND target_type='author'",
            $provider,
            $recordId
        ));
        return is_string($value) ? new AuthorId($value) : null;
    }

    public function providerWorkIdentities(WorkId $workId, string $providerKey): array
    {
        $values = $this->database->get_col($this->database->prepare(
            "SELECT provider_record_id "
                . "FROM `{$this->tables->bibliographicProviderIdentities()}` "
                . "WHERE provider_key=%s AND source_entity_type='work' "
                . "AND target_type='work' AND work_id=%s "
                . "ORDER BY provider_record_id ASC",
            $providerKey,
            $workId->value()
        ));
        return array_map(
            static fn (mixed $recordId): BibliographicProviderEntityIdentity =>
                BibliographicProviderEntityIdentity::work($providerKey, (string) $recordId),
            $values
        );
    }

    public function mappedWorksForAuthor(
        AuthorId $authorId,
        string $providerKey,
        array $providerWorkRecordIds
    ): array {
        if ($providerWorkRecordIds === []) { return []; }
        $placeholders = implode(",", array_fill(0, count($providerWorkRecordIds), "%s"));
        $rows = $this->database->get_results($this->database->prepare(
            "SELECT identities.provider_record_id,identities.work_id "
                . "FROM `{$this->tables->bibliographicProviderIdentities()}` identities "
                . "INNER JOIN `{$this->tables->workContributors()}` contributors "
                . "ON contributors.work_id=identities.work_id "
                . "WHERE identities.provider_key=%s "
                . "AND identities.source_entity_type='work' "
                . "AND identities.target_type='work' "
                . "AND contributors.author_id=%s "
                . "AND contributors.contributor_role IN ('author','co_author') "
                . "AND identities.provider_record_id IN ({$placeholders})",
            $providerKey,
            $authorId->value(),
            ...$providerWorkRecordIds
        ));
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->provider_record_id] = new WorkId((string) $row->work_id);
        }
        return $result;
    }

    public function claimWork(string $provider, string $sourceType, string $recordId, WorkId $workId): void
    {
        $this->claim(
            $provider,
            $sourceType,
            $recordId,
            "work",
            $workId->value(),
            null,
            null
        );
    }

    public function claimEdition(string $provider, string $recordId, EditionId $editionId): void
    {
        $this->claim(
            $provider,
            "edition",
            $recordId,
            "edition",
            null,
            $editionId->value(),
            null
        );
    }

    public function claimAuthor(
        string $provider,
        string $recordId,
        AuthorId $authorId
    ): void {
        $existing = $this->findAuthor($provider, $recordId);
        if ($existing !== null) {
            if ($existing->value() === $authorId->value()) {
                return;
            }
            throw new AuthorProviderIdentityConflict();
        }

        $previous = $this->database->suppress_errors(true);
        try {
            $inserted = $this->database->insert(
                $this->tables->bibliographicProviderIdentities(),
                [
                    "provider_key" => $provider,
                    "source_entity_type" => "author",
                    "provider_record_id" => $recordId,
                    "target_type" => "author",
                    "work_id" => null,
                    "edition_id" => null,
                    "author_id" => $authorId->value(),
                ],
                ["%s", "%s", "%s", "%s", "%s", "%s", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previous);
        }
        if ($inserted === 1) {
            return;
        }
        if (WpdbErrorTranslator::conflict($this->database->last_error) !== null) {
            throw new AuthorProviderClaimRace();
        }
        throw WpdbErrorTranslator::writeFailure(
            "Could not persist provider Author identity.",
            $this->database->last_error
        );
    }

    private function claim(
        string $provider,
        string $sourceType,
        string $recordId,
        string $targetType,
        ?string $workId,
        ?string $editionId,
        ?string $authorId
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
                    "author_id" => $authorId,
                ],
                ["%s", "%s", "%s", "%s", "%s", "%s", "%s"]
            );
        } finally {
            $this->database->suppress_errors($previous);
        }
        if ($inserted === 1) { return; }
        $writeError = $this->database->last_error;
        $existing = match ($targetType) {
            "work" => $this->findWork(
                $provider,
                $sourceType,
                $recordId
            )?->value(),
            "edition" => $this->findEdition($provider, $recordId)?->value(),
            "author" => $this->findAuthor($provider, $recordId)?->value(),
            default => null,
        };
        $expected = match ($targetType) {
            "work" => $workId,
            "edition" => $editionId,
            "author" => $authorId,
            default => null,
        };
        if ($existing === $expected) { return; }
        if (WpdbErrorTranslator::conflict($writeError) !== null) {
            if ($targetType === "author") {
                if ($existing === null) {
                    throw new AuthorProviderClaimRace();
                }
                throw new AuthorProviderIdentityConflict();
            }
            throw new BibliographicProviderIdentityConflict();
        }
        throw WpdbErrorTranslator::writeFailure(
            "Could not persist bibliographic provider identity.",
            $writeError
        );
    }
}
