<?php

declare(strict_types=1);

/** Standalone read-only PDO observer. Never loads wp-config.php or WordPress. */
final class NormalEvidenceFailure extends RuntimeException {}
function normalRequire(bool $condition, string $reason): void
{
    if (!$condition) { throw new NormalEvidenceFailure($reason); }
}
function normalHash(mixed $value): string
{
    return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
}
function normalRow(array $row): array
{
    ksort($row, SORT_STRING);
    return array_map(static fn ($value) => $value === null ? null : base64_encode((string) $value), $row);
}
function normalReadEvidence(string $path): array
{
    normalRequire(is_file($path) && !is_link($path) && is_file($path . '.sha256'), 'evidence_missing');
    $bytes = file_get_contents($path);
    normalRequire(file_get_contents($path . '.sha256') === hash('sha256', $bytes) . '  ' . basename($path) . "\n", 'evidence_checksum_mismatch');
    return json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
}

try {
    if (($argv[1] ?? '') === 'compare') {
        normalRequire($argc === 4, 'compare_arguments');
        $before = normalReadEvidence($argv[2]);
        $after = normalReadEvidence($argv[3]);
        $changed = [];
        foreach (array_unique([...array_keys($before['watched_state']['tables']), ...array_keys($after['watched_state']['tables'])]) as $table) {
            if (($before['watched_state']['tables'][$table] ?? null) !== ($after['watched_state']['tables'][$table] ?? null)) { $changed[] = $table; }
        }
        $equal = $before['watched_state'] === $after['watched_state'];
        echo json_encode(['exact_watched_equality' => $equal, 'changed_tables' => $changed,
            'global_schema_equal' => $before['watched_state']['global_schema_sha256'] === $after['watched_state']['global_schema_sha256'],
            'global_data_equal' => $before['watched_state']['global_data_sha256'] === $after['watched_state']['global_data_sha256'],
            'volatile_exclusions' => []], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
        exit($equal ? 0 : 2);
    }
    normalRequire($argc === 3 && $argv[1] === 'capture', 'capture_arguments');
    normalRequire(getenv('DDEV_SITENAME') === 'biblio-v2', 'normal_project_required');
    $path = $argv[2];
    $directory = dirname($path);
    normalRequire(realpath($directory) === $directory && !is_link($directory)
        && str_starts_with($directory, '/var/www/html/.local/') && (fileperms($directory) & 0077) === 0
        && preg_match('/^[a-z0-9-]+\.json$/D', basename($path)) === 1
        && !file_exists($path) && !file_exists($path . '.sha256'), 'fresh_private_evidence_path_required');
    require_once dirname(__DIR__) . '/web/wp-content/plugins/biblio-core/vendor/autoload.php';
    $expected = (new Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames('wp_'))->schema1026();
    $db = new PDO('mysql:host=db;dbname=db;charset=utf8mb4', 'db', 'db', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_STRINGIFY_FETCHES => true, PDO::ATTR_EMULATE_PREPARES => false]);
    normalRequire($db->query('SELECT DATABASE()')->fetchColumn() === 'db', 'normal_database_required');
    $db->exec('SET SESSION TRANSACTION READ ONLY');
    $db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    foreach (["SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE()",
        "SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE()",
        "SELECT COUNT(*) FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE()"] as $sql) {
        normalRequire($db->query($sql)->fetchColumn() === '0', 'unsupported_database_object');
    }
    $inventorySql = "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME";
    $inventory = $db->query($inventorySql)->fetchAll();
    $tables = $schema = $data = [];
    foreach ($inventory as $entry) {
        $name = $entry['TABLE_NAME'];
        normalRequire(preg_match('/^[a-zA-Z0-9_]+$/D', $name) === 1 && $entry['ENGINE'] === 'InnoDB', 'unsupported_table');
        $ddl = $db->query("SHOW CREATE TABLE `{$name}`")->fetch()['Create Table'];
        $schema[$name] = hash('sha256', $ddl);
        $hashes = [];
        foreach ($db->query("SELECT * FROM `{$name}`") as $row) { $hashes[] = normalHash(normalRow($row)); }
        sort($hashes, SORT_STRING);
        $data[$name] = ['rows' => count($hashes), 'content_sha256' => normalHash($hashes)];
        $tables[$name] = $data[$name] + ['schema_sha256' => $schema[$name]];
    }
    $biblio = array_values(array_filter(array_keys($tables), static fn ($name) => str_starts_with($name, 'wp_biblio_')));
    sort($biblio, SORT_STRING);
    sort($expected, SORT_STRING);
    normalRequire($biblio === $expected && count($biblio) === 57, 'biblio_inventory_mismatch');
    $triggers = array_map(normalRow(...), $db->query('SHOW TRIGGERS')->fetchAll());
    usort($triggers, static fn ($a, $b) => normalHash($a) <=> normalHash($b));
    $databaseMetadata = $db->query('SELECT @@character_set_database AS charset,@@collation_database AS collation')->fetch();
    $options = [];
    foreach ($db->query('SELECT option_id,option_name,option_value,autoload FROM wp_options ORDER BY option_id') as $row) {
        $options[$row['option_id']] = ['name_sha256' => hash('sha256', $row['option_name']),
            'value_sha256' => hash('sha256', $row['option_value']), 'autoload_sha256' => hash('sha256', $row['autoload'])];
    }
    $sanity = ['schema_version_1026' => $db->query("SELECT option_value FROM wp_options WHERE option_name='biblio_core_schema_version'")->fetchColumn() === '1026'];
    $sanity['migration_tables_empty'] = true;
    foreach ($biblio as $table) {
        if (str_starts_with($table, 'wp_biblio_migration_') && $tables[$table]['rows'] !== 0) { $sanity['migration_tables_empty'] = false; }
    }
    $checks = [
        'membership_references_valid' => "SELECT COUNT(*) FROM wp_biblio_library_memberships m LEFT JOIN wp_biblio_libraries l ON l.library_id=m.library_id LEFT JOIN wp_users u ON CAST(u.ID AS CHAR)=m.user_id WHERE l.library_id IS NULL OR u.ID IS NULL",
        'library_active_owner_valid' => "SELECT COUNT(*) FROM wp_biblio_libraries l WHERE l.library_type<>'private_library' OR l.library_status<>'active' OR NOT EXISTS (SELECT 1 FROM wp_biblio_library_memberships m WHERE m.library_id=l.library_id AND m.membership_status='active' AND m.management_role='owner' AND m.use_access='direct')",
        'personal_designations_valid' => "SELECT COUNT(*) FROM wp_biblio_personal_library_designations d LEFT JOIN wp_biblio_library_memberships m ON m.library_id=d.library_id AND m.user_id=d.user_id WHERE m.user_id IS NULL OR m.membership_status<>'active' OR m.management_role<>'owner' OR m.use_access<>'direct'",
        'item_edition_work_valid' => "SELECT COUNT(*) FROM wp_biblio_items i LEFT JOIN wp_biblio_editions e ON e.edition_id=i.edition_id LEFT JOIN wp_biblio_works w ON w.work_id=e.work_id WHERE e.edition_id IS NULL OR w.work_id IS NULL",
        'item_details_valid' => "SELECT COUNT(*) FROM wp_biblio_item_local_details d LEFT JOIN wp_biblio_items i ON i.item_id=d.item_id WHERE i.item_id IS NULL",
    ];
    foreach ($checks as $key => $sql) { $sanity[$key] = $db->query($sql)->fetchColumn() === '0'; }
    $header = file_get_contents(dirname(__DIR__) . '/web/wp-content/plugins/biblio-core/biblio-core.php');
    normalRequire(preg_match('/^ \* Version:\s*([^\r\n]+)$/m', $header, $version) === 1, 'version_missing');
    $sanity['core_header_constant_consistent'] = trim($version[1]) === Biblio\Core\Plugin::VERSION;
    foreach ($schema as $table => $hash) {
        normalRequire(hash('sha256', $db->query("SHOW CREATE TABLE `{$table}`")->fetch()['Create Table']) === $hash, 'concurrent_schema_change');
    }
    normalRequire($db->query($inventorySql)->fetchAll() === $inventory, 'concurrent_inventory_change');
    $db->commit();
    $state = ['policy' => ['all_tables_all_columns' => true, 'schema_includes_auto_increment' => true, 'volatile_exclusions' => []],
        'tables' => $tables, 'all_options' => $options, 'biblio_tables' => $biblio,
        'database_metadata' => $databaseMetadata, 'trigger_sha256' => normalHash($triggers),
        'global_schema_sha256' => normalHash([$schema, $databaseMetadata, $triggers]), 'global_data_sha256' => normalHash($data)];
    $artifact = ['purpose' => 'prospective_normal_db_invariance', 'historical_equality_claimed' => false,
        'previous_attempt' => 'INVALIDATED / INCONCLUSIVE', 'captured_at_utc' => gmdate('c'),
        'observer_sha256' => hash_file('sha256', __FILE__), 'read_only_transaction' => true,
        'wordpress_bootstrapped' => false, 'core_version' => Biblio\Core\Plugin::VERSION,
        'sanity' => $sanity, 'watched_state' => $state];
    $bytes = json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    foreach ([$path => $bytes, $path . '.sha256' => hash('sha256', $bytes) . '  ' . basename($path) . "\n"] as $destination => $content) {
        $file = fopen($destination, 'xb');
        normalRequire(is_resource($file), 'evidence_exists');
        normalRequire(fwrite($file, $content) === strlen($content) && fflush($file), 'evidence_write_failed');
        fclose($file);
        normalRequire(chmod($destination, 0400), 'evidence_permission_failed');
    }
    echo json_encode(['artifact' => basename($path), 'sha256' => hash('sha256', $bytes), 'tables' => count($tables),
        'biblio_tables' => count($biblio), 'sanity_passed' => !in_array(false, $sanity, true)], JSON_THROW_ON_ERROR), "\n";
    exit(in_array(false, $sanity, true) ? 2 : 0);
} catch (Throwable $failure) {
    // Do not output PDO messages, SQL, credentials or row content.
    $reason = $failure instanceof NormalEvidenceFailure ? $failure->getMessage() : 'read_or_serialization_failure';
    fwrite(STDERR, "Normal DB evidence capture/compare failed: {$reason}; no baseline acceptance.\n");
    exit(1);
}
