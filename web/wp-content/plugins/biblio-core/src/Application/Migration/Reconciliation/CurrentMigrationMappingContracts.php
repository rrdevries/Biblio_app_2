<?php

declare(strict_types=1);

namespace Biblio\Core\Application\Migration\Reconciliation;

use Biblio\Core\Application\Migration\Author\{
    CatalogAuthorMigrationParticipant,
    CatalogWorkContributorMigrationParticipant
};
use Biblio\Core\Application\Migration\Catalog\{
    CatalogEditionMigrationParticipant,
    CatalogItemMigrationParticipant,
    CatalogWorkMigrationParticipant
};
use Biblio\Core\Application\Migration\Circulation\CirculationMigrationParticipant;
use Biblio\Core\Application\Migration\Assessments\{
    HistoricalRatingMigrationParticipant,
    HistoricalWrittenReviewMigrationParticipant
};
use Biblio\Core\Application\Migration\Notes\PrivateNoteMigrationParticipant;
use Biblio\Core\Application\Migration\Preservation\PreservedSourceEvidenceMigrationParticipant;
use Biblio\Core\Application\Migration\Reading\{
    ReadingRoundMigrationParticipant,
    ReadingTruthMigrationParticipant
};
use Biblio\Core\Application\Migration\Series\{
    CatalogSeriesMigrationParticipant,
    CatalogWorkSeriesMigrationParticipant
};

final class CurrentMigrationMappingContracts
{
    public static function create(): MigrationMappingContractRegistry
    {
        $entity = MigrationMappingKind::Entity;
        $relation = MigrationMappingKind::Relation;

        return new MigrationMappingContractRegistry([
            new MigrationMappingContract(
                CatalogAuthorMigrationParticipant::SOURCE_TYPE,
                [new MigrationMappingRule("author", $entity, true)]
            ),
            new MigrationMappingContract(
                CatalogWorkMigrationParticipant::SOURCE_TYPE,
                [new MigrationMappingRule("work", $entity, true)]
            ),
            new MigrationMappingContract(
                CatalogSeriesMigrationParticipant::SOURCE_TYPE,
                [new MigrationMappingRule("series", $entity, true)]
            ),
            new MigrationMappingContract(
                CatalogWorkSeriesMigrationParticipant::SOURCE_TYPE,
                [new MigrationMappingRule("work_series_membership", $relation, true)]
            ),
            new MigrationMappingContract(
                CatalogWorkContributorMigrationParticipant::SOURCE_TYPE,
                [
                    new MigrationMappingRule("author_contributor_credit", $entity, true),
                    new MigrationMappingRule("work_contributor", $relation, true),
                ]
            ),
            new MigrationMappingContract(
                CatalogEditionMigrationParticipant::SOURCE_TYPE,
                [
                    new MigrationMappingRule("edition", $entity, true),
                    new MigrationMappingRule("canonical_isbn", $relation, false),
                ]
            ),
            new MigrationMappingContract(
                CatalogItemMigrationParticipant::SOURCE_TYPE,
                [
                    new MigrationMappingRule("item", $entity, true),
                    new MigrationMappingRule("item_local_details", $relation, false),
                    new MigrationMappingRule("library_catalog_context", $relation, true),
                ]
            ),
            new MigrationMappingContract(
                ReadingRoundMigrationParticipant::SOURCE_TYPE,
                [new MigrationMappingRule("reading_round", $entity, true)]
            ),
            new MigrationMappingContract(
                ReadingTruthMigrationParticipant::SOURCE_TYPE,
                [new MigrationMappingRule("personal_reading_truth", $entity, true)]
            ),
            new MigrationMappingContract(
                PrivateNoteMigrationParticipant::SOURCE_TYPE,
                [new MigrationMappingRule("private_note", $entity, true)]
            ),
            new MigrationMappingContract(
                HistoricalRatingMigrationParticipant::SOURCE_TYPE,
                [new MigrationMappingRule("rating", $entity, true)]
            ),
            new MigrationMappingContract(
                HistoricalWrittenReviewMigrationParticipant::SOURCE_TYPE,
                [new MigrationMappingRule("written_review", $entity, true)]
            ),
            new MigrationMappingContract(
                CirculationMigrationParticipant::SOURCE_TYPE,
                []
            ),
            new MigrationMappingContract(
                PreservedSourceEvidenceMigrationParticipant::SOURCE_TYPE,
                []
            ),
        ]);
    }
}
