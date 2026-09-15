<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Author;

use Biblio\Core\Application\Metadata\Author\AuthorContributorCreditKey;
use Biblio\Core\Application\Migration\Runner\TypedMigrationPlan;
use Biblio\Core\Catalog\AuthorId;

final readonly class CatalogAuthorPlan implements TypedMigrationPlan
{
    private string $displayName;

    public function __construct(
        string $displayName,
        private ?AuthorId $approvedExistingAuthorId = null
    ) {
        $this->displayName = AuthorContributorCreditKey::normalizeObservedName(
            $displayName
        );
    }

    public function displayName(): string { return $this->displayName; }
    public function approvedExistingAuthorId(): ?AuthorId
    {
        return $this->approvedExistingAuthorId;
    }
    public function canonicalPayload(): array
    {
        return [
            "target_kind" => "catalog_author",
            "display_name" => $this->displayName,
            "approved_existing_author_id" =>
                $this->approvedExistingAuthorId?->value(),
        ];
    }
}
