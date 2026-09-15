<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Author;

use Biblio\Core\Application\Metadata\Author\AuthorContributorCreditKey;
use Biblio\Core\Application\Migration\Runner\TypedMigrationPlan;
use Biblio\Core\Catalog\{ContributorPosition, ContributorRole};
use Biblio\Core\Identity\IdentifierConstraints;

final readonly class CatalogWorkContributorPlan implements TypedMigrationPlan
{
    private string $observedDisplayName;

    public function __construct(
        private string $authorSourceId,
        private string $workSourceId,
        private ContributorRole $role,
        private ContributorPosition $position,
        string $observedDisplayName
    ) {
        IdentifierConstraints::assertValid(
            $authorSourceId,
            "Contributor Author source ID"
        );
        IdentifierConstraints::assertValid(
            $workSourceId,
            "Contributor Work source ID"
        );
        $this->observedDisplayName =
            AuthorContributorCreditKey::normalizeObservedName(
                $observedDisplayName
            );
    }

    public function authorSourceId(): string { return $this->authorSourceId; }
    public function workSourceId(): string { return $this->workSourceId; }
    public function role(): ContributorRole { return $this->role; }
    public function position(): ContributorPosition { return $this->position; }
    public function observedDisplayName(): string
    {
        return $this->observedDisplayName;
    }
    public function canonicalPayload(): array
    {
        return [
            "target_kind" => "catalog_work_contributor",
            "author_source_id" => $this->authorSourceId,
            "work_source_id" => $this->workSourceId,
            "role" => $this->role->value,
            "position" => $this->position->value(),
            "observed_display_name" => $this->observedDisplayName,
        ];
    }
}
