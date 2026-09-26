<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\Migration;

use Biblio\Core\Application\Migration\Cutover\{ProductionTestResetAuthorization, RehearsalContract};
use Biblio\Core\Application\Migration\Runner\DeterministicJson;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use wpdb;

/** One reviewed, fully fingerprint-bound test population. No generic table selector or cascade wipe. */
final readonly class WpdbProductionTestTargetReset
{
    public function __construct(private wpdb $db, private WpdbProductionMigrationTarget $target,
        private ProductionBackupDirectory $backups, private ProductionEvidenceDirectory $evidence) {}

    /**
     * @param array<string,array<string,mixed>> $reviewedTables
     * @param array<string,string> $scope
     * @return array<string,mixed>
     */
    public function prepare(array $reviewedTables, array $scope): array
    {
        $environment = $this->target->identity();
        $this->assertScope($scope);
        $baseline = $this->target->fingerprint();
        RehearsalContract::equal($baseline['tables'], $reviewedTables, 'reviewed_test_population_changed');
        $order = $this->deletionOrder();
        $expected = $baseline['tables'];
        $structural = $this->structuralTables();
        foreach ($baseline['biblio_tables'] as $name => $state) {
            if (in_array($name, $this->migrationTables(), true)) {
                RehearsalContract::require($state['rows'] === 0, 'reset_migration_state_present');
            }
            $rows = [];
            if (in_array($name, $structural, true)) {
                $rows = $this->db->get_results($this->db->prepare("SELECT * FROM `{$name}` WHERE library_id=%s", $scope['preserve_library_id']), ARRAY_A);
                RehearsalContract::require(is_array($rows) && $this->db->last_error === '', 'reset_structure_read_failed');
            }
            $expected[$name]['rows'] = count($rows);
            $expected[$name]['data_sha256'] = self::rowHash($rows);
        }
        $binding = ['purpose' => 'production-test-target-reset', 'environment' => $environment,
            'scope' => $scope, 'baseline' => $baseline, 'expected_tables' => $expected, 'delete_order' => $order];
        $backup = $this->backups->create('PRE_RESET', $binding);
        RehearsalContract::equal($backup['baseline'], $baseline, 'reset_backup_baseline_changed');
        return ['binding' => $binding, 'backup' => $backup];
    }

    /** @return array<string,mixed> */
    public function execute(ProductionTestResetAuthorization $authorization): array
    {
        $packet = $authorization->packet;
        $binding = $packet['binding'];
        $this->assertScope($binding['scope']);
        $this->backups->verify($packet['backup'], $binding);
        RehearsalContract::equal($packet['backup']['baseline'], $binding['baseline'], 'reset_backup_baseline_changed');
        RehearsalContract::equal($this->target->fingerprint(), $binding['baseline'], 'reviewed_test_population_changed');
        RehearsalContract::equal($this->deletionOrder(), $binding['delete_order'], 'reset_dependencies_changed');
        $this->target->acquire();
        $transaction = false;
        $blocked = false;
        try {
            $this->target->blockWrites();
            $blocked = true;
            RehearsalContract::equal($this->target->fingerprint(), $binding['baseline'], 'reviewed_test_population_changed');
            $this->query('START TRANSACTION');
            $transaction = true;
            RehearsalContract::equal($this->target->fingerprint(), $binding['baseline'], 'reviewed_test_population_changed');
            $cleared = [];
            foreach ($binding['delete_order'] as $name) {
                if (in_array($name, $this->migrationTables(), true)) { continue; }
                $sql = "DELETE FROM `{$name}`";
                if (in_array($name, $this->structuralTables(), true)) {
                    $sql .= $this->db->prepare(' WHERE library_id=%s', $binding['scope']['discard_library_id']);
                }
                $affected = $this->query($sql);
                RehearsalContract::equal($affected, $binding['baseline']['tables'][$name]['rows'] - $binding['expected_tables'][$name]['rows'], 'reset_affected_rows_mismatch');
                $cleared[$name] = $affected;
            }
            $this->target->assertWritesBlocked();
            $this->target->assertEmpty();
            $after = $this->target->fingerprint();
            RehearsalContract::equal($after['tables'], $binding['expected_tables'], 'reset_preservation_mismatch');
            foreach (['trigger_sha256', 'database_metadata'] as $key) {
                RehearsalContract::equal($after[$key], $binding['baseline'][$key], 'reset_schema_changed');
            }
            $this->query('COMMIT');
            $transaction = false;
            RehearsalContract::equal($this->target->fingerprint(), $after, 'reset_post_commit_changed');
            $result = ['status' => 'TEST_TARGET_RESET_VERIFIED', 'packet_digest' => ProductionTestResetAuthorization::digest($packet),
                'before' => $binding['baseline'], 'after' => $after, 'cleared_counts' => $cleared, 'writes_remain_blocked' => true];
            $result['artifact'] = $this->evidence->append('production-test-reset', $result);
            return $result;
        } catch (\Throwable $failure) {
            $rolledBack = null;
            if ($transaction) {
                try { $this->query('ROLLBACK'); $rolledBack = true; }
                catch (\Throwable) { $rolledBack = false; }
            }
            $this->evidence->append('production-test-reset-failure', ['packet_digest' => ProductionTestResetAuthorization::digest($packet),
                'reason' => $failure instanceof \Biblio\Core\Application\Migration\Cutover\RehearsalFailure ? $failure->reason : 'reset_failed',
                'write_block_established' => $blocked, 'transaction_rollback_succeeded' => $rolledBack]);
            throw $failure;
        } finally { $this->target->release(); }
    }

    public function restore(ProductionTestResetAuthorization $authorization, string $confirmation): void
    {
        $authorization->assertRestoreConfirmation($confirmation);
        $this->target->prepareTestResetRestore($authorization);
        $this->assertScope($authorization->packet['binding']['scope'], false);
        $this->target->acquire();
        try {
            $this->backups->verify($authorization->packet['backup'], $authorization->packet['binding']);
            $this->target->blockWrites();
            $this->backups->restoreTestReset($authorization, $confirmation);
            $this->evidence->append('production-test-reset-restored', ['packet_digest' => ProductionTestResetAuthorization::digest($authorization->packet), 'writes_remain_blocked' => true]);
        } finally { $this->target->release(); }
    }

    /** @param array<string,string> $scope */
    private function assertScope(array $scope, bool $before = true): void
    {
        RehearsalContract::keys($scope, ['preserve_user_id', 'preserve_library_id', 'discard_user_id', 'discard_library_id']);
        $identity = $this->target->identity();
        RehearsalContract::require($scope['preserve_user_id'] === $identity['target_user_id']
            && $scope['preserve_library_id'] === $identity['target_library_id']
            && $scope['discard_library_id'] !== $scope['preserve_library_id'] && $scope['discard_user_id'] !== $scope['preserve_user_id'], 'reset_scope_invalid');
        if ($identity['purpose'] === 'production') {
            RehearsalContract::equal($scope, ['preserve_user_id' => '227', 'preserve_library_id' => 'personal-a41aedfee4e20e3392355258f6a8adc3',
                'discard_user_id' => '1', 'discard_library_id' => 'personal-4e564b2797df5f2f4b69c676cc9665cf'], 'reset_unreviewed_identity');
        }
        if (!$before) { return; }
        $tables = new CoreTableNames($this->db->prefix);
        foreach (['preserve', 'discard'] as $side) {
            $sql = $this->db->prepare("SELECT COUNT(*) FROM `{$tables->personalLibraryDesignations()}` WHERE user_id=%s AND library_id=%s", $scope[$side . '_user_id'], $scope[$side . '_library_id']);
            RehearsalContract::require($this->db->get_var($sql) === '1', 'reset_designation_changed');
        }
        RehearsalContract::require($this->db->get_var("SELECT COUNT(*) FROM `{$tables->libraries()}`") === '2'
            && $this->db->get_var("SELECT COUNT(*) FROM `{$tables->memberships()}`") === '2'
            && $this->db->get_var("SELECT COUNT(*) FROM `{$tables->personalLibraryDesignations()}`") === '2', 'reset_unreviewed_structure');
    }

    /** @return list<string> */
    private function structuralTables(): array
    {
        $t = new CoreTableNames($this->db->prefix);
        return [$t->libraries(), $t->memberships(), $t->personalLibraryDesignations(), $t->libraryBookTypes(), $t->libraryGenres(), $t->librarySubjects()];
    }

    /** @return list<string> */
    private function migrationTables(): array
    {
        $t = new CoreTableNames($this->db->prefix);
        return [$t->migrationRuns(), $t->migrationRunLocks(), $t->migrationSourceObservations(), $t->migrationTargetMappings(), $t->migrationPreservations(), $t->migrationQuarantine()];
    }

    /**
     * Child-before-parent, with FK enforcement left ON and no DELETE triggers/cross-boundary cascades.
     * @return list<string>
     */
    private function deletionOrder(): array
    {
        $names = (new CoreTableNames($this->db->prefix))->schema1026();
        RehearsalContract::require($this->db->get_var('SELECT @@SESSION.foreign_key_checks') === '1', 'reset_foreign_keys_disabled');
        $engines = $this->db->get_results("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()", ARRAY_A);
        RehearsalContract::require(is_array($engines) && $this->db->last_error === '', 'reset_engine_read_failed');
        foreach ($engines as $row) { RehearsalContract::require($row['ENGINE'] === 'InnoDB', 'reset_nontransactional_table'); }
        RehearsalContract::require($this->db->get_var("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_MANIPULATION='DELETE'") === '0', 'reset_delete_trigger_present');
        $edges = $this->db->get_results("SELECT TABLE_SCHEMA,TABLE_NAME,REFERENCED_TABLE_SCHEMA,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_NAME IS NOT NULL AND (TABLE_SCHEMA=DATABASE() OR REFERENCED_TABLE_SCHEMA=DATABASE())", ARRAY_A);
        RehearsalContract::require(is_array($edges) && $this->db->last_error === '', 'reset_dependencies_unreadable');
        $database = $this->db->get_var('SELECT DATABASE()');
        $children = array_fill_keys($names, []);
        foreach ($edges as $edge) {
            RehearsalContract::require($edge['TABLE_SCHEMA'] === $database && $edge['REFERENCED_TABLE_SCHEMA'] === $database
                && in_array($edge['TABLE_NAME'], $names, true) && in_array($edge['REFERENCED_TABLE_NAME'], $names, true), 'reset_cross_boundary_reference');
            if ($edge['TABLE_NAME'] !== $edge['REFERENCED_TABLE_NAME']) { $children[$edge['REFERENCED_TABLE_NAME']][] = $edge['TABLE_NAME']; }
            else { throw new \Biblio\Core\Application\Migration\Cutover\RehearsalFailure('reset_self_reference'); }
        }
        $order = [];
        while ($children !== []) {
            $ready = array_keys(array_filter($children, static fn (array $dependencies): bool => array_diff($dependencies, $order) === []));
            sort($ready, SORT_STRING);
            RehearsalContract::require($ready !== [], 'reset_dependency_cycle');
            foreach ($ready as $name) { $order[] = $name; unset($children[$name]); }
        }
        return $order;
    }

    /** @param list<array<string,mixed>> $rows */
    private static function rowHash(array $rows): string
    {
        $hashes = [];
        foreach ($rows as $row) {
            ksort($row, SORT_STRING);
            $encoded = [];
            foreach ($row as $key => $value) { $encoded[$key] = $value === null ? null : base64_encode((string) $value); }
            $hashes[] = DeterministicJson::hash($encoded);
        }
        sort($hashes, SORT_STRING);
        return DeterministicJson::hash($hashes);
    }

    private function query(string $sql): int
    {
        $result = $this->db->query($sql);
        RehearsalContract::require($result !== false, 'reset_database_operation_failed');
        return (int) $result;
    }
}
