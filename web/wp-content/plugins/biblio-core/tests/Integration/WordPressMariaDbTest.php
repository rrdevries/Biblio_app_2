<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Plugin;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use wpdb;

final class WordPressMariaDbTest extends TestCase
{
    private string $tableName;

    protected function setUp(): void
    {
        parent::setUp();

        $database = $this->database();
        $this->tableName = $database->prefix
            . "biblio_core_integration_probe";

        if (preg_match('/^[a-zA-Z0-9_]+$/', $this->tableName) !== 1) {
            throw new RuntimeException("Unsafe integration probe table name.");
        }

        $this->dropProbeTable();

        $result = $database->query(
            "CREATE TABLE `{$this->tableName}` ("
            . "id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, "
            . "test_value VARCHAR(191) NOT NULL, "
            . "PRIMARY KEY (id)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        self::assertNotFalse($result, "Could not create integration probe table.");
    }

    protected function tearDown(): void
    {
        try {
            $this->dropProbeTable();
        } finally {
            parent::tearDown();
        }
    }

    public function testWordPressBootstrapsAgainstMariaDb(): void
    {
        $database = $this->database();
        $version = $database->get_var("SELECT VERSION()");

        self::assertSame("biblio_core_test", DB_NAME);
        self::assertGreaterThanOrEqual(1, did_action("plugins_loaded"));
        self::assertTrue(class_exists(Plugin::class));
        self::assertIsString($version);
        self::assertStringContainsString("MariaDB", $version);
    }

    public function testKnownValueCanBeWrittenReadAndCleanedUp(): void
    {
        $database = $this->database();
        $knownValue = "biblio-core-integration-ok";

        $inserted = $database->insert(
            $this->tableName,
            ["test_value" => $knownValue],
            ["%s"]
        );

        self::assertSame(1, $inserted);

        $storedValue = $database->get_var(
            $database->prepare(
                "SELECT test_value FROM `{$this->tableName}` WHERE id = %d",
                $database->insert_id
            )
        );

        self::assertSame($knownValue, $storedValue);

        $this->dropProbeTable();

        self::assertNull($database->get_var(
            $database->prepare("SHOW TABLES LIKE %s", $this->tableName)
        ));
    }

    private function database(): wpdb
    {
        global $wpdb;

        if (!$wpdb instanceof wpdb) {
            throw new RuntimeException("WordPress database connection is unavailable.");
        }

        return $wpdb;
    }

    private function dropProbeTable(): void
    {
        $this->database()->query(
            "DROP TABLE IF EXISTS `{$this->tableName}`"
        );
    }
}
