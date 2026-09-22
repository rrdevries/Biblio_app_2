<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Cutover\RehearsalContract;
use Biblio\Core\Application\Migration\Runner\DeterministicJson;
use Biblio\Core\Catalog\Classification\DefaultClassificationSeeds;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\CoreSchemaHealthChecker;
use wpdb;

/** Read-only fingerprinting. Restricted row bytes are hashed in memory, never returned. */
final readonly class RehearsalDatabaseState
{
    public function __construct(private wpdb $db, private CoreTableNames $tables) {}

    /**
 * @return array<string,mixed>
 */
    public function fingerprint(): array
    {
        // These are outside the approved WordPress/Biblio target shape. Refuse
        // rather than silently omit executable or derived database state.
        foreach ([
            "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE()",
            "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()",
            "SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()",
        ] as $sql) {
            RehearsalContract::require($this->db->get_var($sql) === "0", "unsupported_database_objects");
        }
        $names = $this->db->get_col("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        RehearsalContract::require($names !== [] && $this->db->last_error === "", "database_inventory_failed");
        sort($names, SORT_STRING);
        $all = [];
        $biblio = [];
        foreach ($names as $name) {
            RehearsalContract::require(is_string($name) && preg_match('/^[a-zA-Z0-9_]+$/D', $name) === 1, "database_table_invalid");
            $schema = $this->db->get_row("SHOW CREATE TABLE `{$name}`", ARRAY_A);
            $rows = $this->db->get_results("SELECT * FROM `{$name}`", ARRAY_A);
            RehearsalContract::require(is_array($schema) && is_array($rows), "database_read_failed");
            $this->assertReadSucceeded();
            RehearsalContract::require(!str_starts_with($name, $this->db->prefix . "biblio_")
                || in_array($name, $this->tables->schema1026(), true), "unknown_biblio_table");
            $hashes = [];
            foreach ($rows as $row) {
                ksort($row, SORT_STRING);
                // Encode binary-safe values without placing data in evidence.
                $encoded = [];
                foreach ($row as $key => $value) {
                    $encoded[$key] = $value === null ? null : base64_encode((string) $value);
                }
                $hashes[] = DeterministicJson::hash($encoded);
            }
            sort($hashes, SORT_STRING);
            $all[$name] = ["rows" => count($rows), "schema_sha256" => hash("sha256", (string) $schema["Create Table"]), "data_sha256" => DeterministicJson::hash($hashes)];
            if (in_array($name, $this->tables->schema1026(), true)) {
                $biblio[$name] = $all[$name];
            }
        }
        RehearsalContract::require(count($biblio) === 57, "biblio_table_inventory_mismatch");
        $triggers = $this->db->get_results("SHOW TRIGGERS", ARRAY_A);
        RehearsalContract::require(is_array($triggers), "database_trigger_inventory_failed");
        $this->assertReadSucceeded();
        // Creation instants are transport metadata; charset/collation remain semantic.
        $triggerHashes = [];
        foreach ($triggers as $trigger) {
            $triggerHashes[] = DeterministicJson::hash(array_intersect_key($trigger, array_flip([
                "Trigger", "Event", "Table", "Statement", "Timing", "sql_mode", "Definer",
                "character_set_client", "collation_connection", "Database Collation",
            ])));
        }
        sort($triggerHashes, SORT_STRING);
        $databaseMetadata = $this->db->get_row(
            "SELECT DEFAULT_CHARACTER_SET_NAME AS charset,DEFAULT_COLLATION_NAME AS collation FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE()",
            ARRAY_A
        );
        RehearsalContract::require(is_array($databaseMetadata) && isset($databaseMetadata["charset"], $databaseMetadata["collation"]), "database_metadata_unavailable");
        $this->assertReadSucceeded();
        return [
            "database_sha256" => DeterministicJson::hash([$all, $triggerHashes, $databaseMetadata]),
            "database_metadata" => $databaseMetadata,
            "biblio_sha256" => DeterministicJson::hash($biblio),
            "tables" => $all,
            "biblio_tables" => $biblio,
            "trigger_sha256" => DeterministicJson::hash($triggerHashes),
        ];
    }

    private function assertReadSucceeded(): void
    {
        RehearsalContract::require($this->db->last_error === "", "database_read_failed");
    }

    public function assertEmpty(string $userId, string $libraryId): void
    {
        RehearsalContract::require((new CoreSchemaHealthChecker($this->db, $this->tables))->inspectForVersion(1026)->isHealthy(), "schema_unhealthy");
        $snapshot = $this->fingerprint();
        $allowed = [
            $this->tables->libraries() => 1,
            $this->tables->memberships() => 1,
            $this->tables->personalLibraryDesignations() => 1,
        ];
        $seedTables = [
            $this->tables->libraryBookTypes() => DefaultClassificationSeeds::bookTypes(),
            $this->tables->libraryGenres() => DefaultClassificationSeeds::genres(),
            $this->tables->librarySubjects() => DefaultClassificationSeeds::subjects(),
        ];
        $allowed += array_map(count(...), $seedTables);
        self::assertEmptyCounts($this->tables->schema1026(), $snapshot["biblio_tables"], $allowed);
        foreach ($seedTables as $name => $seeds) {
            $rows = $this->db->get_results("SELECT * FROM `{$name}`", ARRAY_A);
            $expected = [];
            foreach ($seeds as $seed) {
                $expected[$seed->key()->value()] = $seed->defaultName()->value();
            }
            $actual = [];
            foreach ($rows as $row) {
                $idColumn = $name === $this->tables->libraryBookTypes() ? "book_type_id" : "genre_id";
                $idPrefix = $name === $this->tables->libraryBookTypes() ? "seed-book-type-" : "seed-genre-";
                RehearsalContract::require($row["library_id"] === $libraryId
                    && isset($expected[$row["seed_key"] ?? ""])
                    && !isset($actual[$row["seed_key"]])
                    && $row["display_name"] === $expected[$row["seed_key"]]
                    && $row["term_status"] === "active"
                    && $row[$idColumn] === $idPrefix . substr(hash("sha256", $row["seed_key"]), 0, 32), "unaccepted_seed_state");
                $actual[$row["seed_key"]] = true;
            }
            RehearsalContract::require(count($actual) === count($expected), "missing_required_seed");
        }
        $designation = $this->db->get_row("SELECT user_id,library_id FROM `{$this->tables->personalLibraryDesignations()}`", ARRAY_A);
        RehearsalContract::equal($designation, ["user_id" => $userId, "library_id" => $libraryId], "target_designation_mismatch");
    }

    /**
     * Every schema table defaults to zero; seeds are checked for exact content separately.
     * @param list<string> $schema
     * @param array<string,array<string,mixed>> $states
     * @param array<string,int> $allowed
     */
    public static function assertEmptyCounts(array $schema, array $states, array $allowed): void
    {
        $names = array_keys($states);
        sort($schema, SORT_STRING);
        sort($names, SORT_STRING);
        RehearsalContract::require(count($schema) === 57 && $schema === $names, "biblio_table_inventory_mismatch");
        foreach ($states as $table => $state) {
            RehearsalContract::require(($state["rows"] ?? null) === ($allowed[$table] ?? 0), "target_not_empty");
        }
    }
}
