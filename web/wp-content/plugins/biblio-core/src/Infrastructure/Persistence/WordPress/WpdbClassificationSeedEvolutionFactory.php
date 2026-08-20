<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Persistence\WordPress;

use Biblio\Core\Application\Catalog\Classification\ClassificationSeedEvolutionService;
use Biblio\Core\Catalog\Classification\ClassificationNameNormalizer;

final class WpdbClassificationSeedEvolutionFactory
{
    public static function create(
        \wpdb $database,
        CoreTableNames $tableNames
    ): ClassificationSeedEvolutionService {
        return new ClassificationSeedEvolutionService(
            new WpdbLibraryBookTypeRepository($database, $tableNames),
            new WpdbLibraryGenreRepository($database, $tableNames),
            ClassificationNameNormalizer::create()
        );
    }

    private function __construct()
    {
    }
}
