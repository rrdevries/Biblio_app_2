<?php

declare(strict_types=1);

// One owner-approved repair, with no generic population selector or full-import path.
use Biblio\Core\Application\Migration\{BeginMigrationRunService,CommitMigrationRecordService,MigrationMode,MigrationRunLifecycleService,ObserveSourceRecordService};
use Biblio\Core\Application\Migration\Catalog\CatalogItemMigrationParticipant;
use Biblio\Core\Application\Migration\Cutover\{ItemRepairVerifier,RehearsalContract as C,RehearsalFailure};
use Biblio\Core\Application\Migration\Runner\{DeterministicJson,MigrationPlanningTarget,PreparedMigrationPlan,PreparedMigrationRecord};
use Biblio\Core\Application\Catalog\Query\CatalogQuery;
use Biblio\Core\Application\Catalog\Read\CatalogOverviewPageSize;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Infrastructure\Migration\{CurrentV1CatalogMapper,CurrentV1CatalogSourceIds,CurrentV1ClassificationMapper,CurrentV1ClassificationRepairApprovals,CurrentV1ItemLocalMapper,CurrentV1ReviewedItemLocalContract,CurrentV1ReviewedCopyExclusionContract,CurrentV1SourceAdapter,MariaDbProductionTransport,ProductionApplicationConfiguration,ProductionEvidenceDirectory,ProductionSourceLoader,RehearsalComposition,WpdbProductionMigrationTarget};
use Biblio\Core\Infrastructure\Persistence\WordPress\{CoreTableNames,WpdbLibraryBookTypeRepository,WpdbLibraryGenreRepository,WpdbMigrationLedgerRepository,WpdbTransactionManager};
use Biblio\Core\Infrastructure\WordPress\Migration\{OpaqueMigrationRunIdGenerator,RuntimeMigrationEnvironment,SystemMigrationClock};
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;

const REPAIR_RUN = 'migration-run-4058ab2b14dec369eeec6b6214e8ce00';
const REPAIR_MANIFEST = '43306b888056daeed2c3837aba6538befbb94caccc43b1c5d37c77dc8ff52480';
const REPAIR_POPULATION = 'f91c174f527d6eadb192309781b94cd3247ef58f6538eb127ec454b916349867';
const REPAIR_CONVERGENCE = '48fcb27c323435fcd8dc90f56f78714c5db73ad5da4a0dd9ec3f81ea8b7e8a66';
const REPAIR_EXAMPLES = ['The MacKade brothers: Rafe & Jared','Onderstromen','Captivated','Irish hearts','Abandoned in Death'];

function repairJson(string $path): array
{
    C::require(is_file($path) && !is_link($path) && (fileperms($path) & 0077) === 0, 'repair_input_unsafe');
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}

function repairSave(string $path, array $value): void
{
    $bytes = DeterministicJson::encode($value) . "\n";
    $file = fopen($path, 'xb');
    C::require(is_resource($file), 'repair_evidence_exists');
    try { C::require(fwrite($file, $bytes) === strlen($bytes) && fflush($file), 'repair_evidence_write_failed'); }
    finally { fclose($file); }
    chmod($path, 0400);
}

/** Hash rows in memory; the evidence never contains product/private row content. */
function repairRowHashes(wpdb $db, string $table): array
{
    C::require(preg_match('/^wp_biblio_[a-z_]+$/D', $table) === 1, 'repair_table_forbidden');
    $rows = $db->get_results("SELECT * FROM `{$table}`", ARRAY_A);
    C::require(is_array($rows) && $db->last_error === '', 'repair_read_failed');
    $hashes = array_map(DeterministicJson::hash(...), $rows);
    sort($hashes, SORT_STRING);
    return $hashes;
}

function repairCatalog($app, LibraryId $library): array
{
    wp_set_current_user(227);
    $items = []; $works = []; $nora = []; $examples = []; $cursor = null;
    do {
        $page = $app->catalogQuery()->query($library, new CatalogQuery(pageSize: new CatalogOverviewPageSize(100), cursor: $cursor));
        foreach ($page->items() as $item) {
            $id = $item->itemId()->value();
            C::require(!isset($items[$id]), 'repair_catalog_duplicate');
            $items[$id] = $item->workId()->value(); $works[$item->workId()->value()] = true;
            $names = array_map(static fn($a) => $a->displayName(), $item->authors());
            if (in_array('Nora Roberts', $names, true)) { $nora[$item->workId()->value()] = true; }
            if (in_array($item->title(), REPAIR_EXAMPLES, true)) {
                $examples[$item->title()][] = [
                    'item_id' => $id, 'work_id' => $item->workId()->value(), 'authors' => $names,
                    'series' => array_map(static fn($s) => ['name' => $s->series()->displayName(), 'position' => $s->position()->value()], $item->series()),
                ];
                $app->catalogUiReads()->itemDetail($library, $item->itemId());
            }
        }
        $cursor = $page->nextCursor();
    } while ($cursor !== null);
    ksort($items, SORT_STRING);
    return ['items' => $items, 'item_count' => count($items), 'work_count' => count($works), 'nora_work_count' => count($nora), 'examples' => $examples];
}

function repairSourceCoverage($inspection, array $links, array $catalog, array $exclusions): array
{
    $books = []; $copies = []; $nora = []; $visible = []; $excludedNora = []; $named = [];
    foreach ($inspection->records() as $record) {
        if ($record->sourceType() === CurrentV1SourceAdapter::BOOK) {
            $books[$record->sourceId()] = $record;
            if (in_array('Nora Roberts', $record->payload()['authors'] ?? [], true)) { $nora[$record->sourceId()] = true; }
        } elseif ($record->sourceType() === CurrentV1SourceAdapter::COPY) { $copies[$record->sourceId()] = $record; }
    }
    foreach ($copies as $copyId => $copy) {
        $bookId = $copy->payload()['bookId'];
        $item = $links[CurrentV1CatalogSourceIds::item($copyId)] ?? null;
        if ($item !== null) {
            C::require(isset($catalog['items'][$item]), 'repair_source_item_not_visible');
            $visible[$bookId] = true;
        }
        $title = $books[$bookId]->payload()['title'];
        if (in_array($title, REPAIR_EXAMPLES, true)) { $named[$title][] = ['book_id' => $bookId, 'copy_id' => $copyId, 'item_id' => $item]; }
    }
    foreach ($exclusions as $exclusion) {
        C::require(!isset($links[CurrentV1CatalogSourceIds::item($exclusion['source_id'])]), 'repair_excluded_copy_resurrected');
        $bookId = $copies[$exclusion['source_id']]->payload()['bookId'];
        if (isset($nora[$bookId])) { $excludedNora[$bookId] = true; }
    }
    $visibleIds = array_map(strval(...), array_keys($visible)); sort($visibleIds, SORT_STRING);
    $visibleNora = array_map(strval(...), array_keys(array_intersect_key($visible, $nora))); sort($visibleNora, SORT_STRING);
    $excludedIds = array_map(strval(...), array_keys($excludedNora)); sort($excludedIds, SORT_STRING);
    return ['visible_source_books' => count($visible), 'visible_book_ids' => $visibleIds, 'nora_source_books' => count($nora),
        'nora_visible_book_ids' => $visibleNora, 'nora_excluded_book_ids' => $excludedIds, 'examples' => $named];
}

umask(0077);
$action = $argv[1] ?? '';
if (!in_array($action, ['prepare', 'apply'], true) || $argc !== ($action === 'prepare' ? 3 : 4)) {
    fwrite(STDERR, "Usage: php scripts/post-cutover-classification-repair.php prepare CONFIG.json\n       php scripts/post-cutover-classification-repair.php apply CONFIG.json PACKET_SHA256\n");
    exit(2);
}
require dirname(__DIR__) . '/web/wp-content/plugins/biblio-core/vendor/autoload.php';
$writesStarted = false; $target = null;
try {
    C::require(getenv('DDEV_SITENAME') === 'biblio-v2' && getenv('DDEV_APPROOT') === '/var/www/html', 'repair_environment_forbidden');
    ProductionApplicationConfiguration::assertAudited(dirname(__DIR__));
    $config = repairJson($argv[2]);
    C::equal($config['owner_decision_sha256'], hash_file('sha256', $config['owner_decision_path']), 'repair_owner_decision_changed');
    C::equal($config['owner_decision_sha256'], '9464a69deb2fa20f00994e83ebe35c7c99ecf6d26040ea4d20b2ba216a7edf38', 'repair_wrong_owner_decision');
    $output = $config['output_root'];
    C::require(str_starts_with($output, '/var/www/html/.local/') && realpath($output) === $output && (fileperms($output) & 0077) === 0, 'repair_output_unsafe');
    $production = repairJson($config['production_config']);
    $production['target']['git_sha'] = $config['candidate_sha'];
    $population = repairJson($config['population']);
    C::equal($population['manifest_sha256'], REPAIR_MANIFEST, 'repair_manifest_changed');
    $members = $population['members'];
    C::equal(DeterministicJson::hash($members), REPAIR_POPULATION, 'repair_population_changed');
    $backup = repairJson($config['backup_receipt']);
    C::equal($backup['binding']['purpose'], 'PRE-CLASSIFICATION-REPAIR', 'repair_wrong_backup_purpose');
    C::equal($backup['binding']['completed_run_id'], REPAIR_RUN, 'repair_wrong_backup_run');
    C::require($backup['backup']['independent_restore_verified'] === true, 'repair_backup_not_verified');
    C::equal($backup['backup']['binding_sha256'], DeterministicJson::hash($backup['binding']), 'repair_backup_binding_changed');
    C::equal(hash_file('sha256', $config['backup_path']), $backup['backup']['sha256'], 'repair_backup_changed');
    $backupEvidence = new ProductionEvidenceDirectory($config['backup_evidence_root']);
    $storedBackup = $backup['backup']; unset($storedBackup['artifact']);
    C::equal($backupEvidence->read($backup['backup']['artifact']), $storedBackup, 'repair_backup_receipt_changed');
    $productionDatabase = 'db'; $mode = $action === 'apply' ? 'apply' : 'preflight';
    require __DIR__ . '/production-cutover-bootstrap.php';
    $app = (new ProductionComposition($wpdb))->application();
    $environment = new RuntimeMigrationEnvironment(dirname(__DIR__) . '/web/wp-content/plugins/biblio-core/biblio-core.php');
    $user = new UserId('227'); $library = new LibraryId('personal-a41aedfee4e20e3392355258f6a8adc3');
    $target = new WpdbProductionMigrationTarget($wpdb, $app->personalMigrationTargets(), $environment, $user, $library, $production['target'], dirname(__DIR__) . '/web/.maintenance');
    $target->acquire();
    $baseline = $target->fingerprint();
    C::equal($baseline, $backup['binding']['baseline'], 'repair_pre_backup_drift');
    $identity = $target->identity();
    $oldIdentity = $backup['binding']['environment'];
    foreach (['purpose','project','database','root','url','target_user_id','target_library_id','prefix','server_version'] as $key) {
        C::equal($identity[$key], $oldIdentity[$key], 'repair_backup_target_changed');
    }
    $source = ProductionSourceLoader::load($production['source']);
    C::equal($source->intake->identity()->manifestSha256(), REPAIR_MANIFEST, 'repair_source_changed');
    $pt = new MigrationPlanningTarget($app->personalMigrationTargets()->validate($user, $library));
    $planner = (new RehearsalComposition($wpdb, $app, $environment))->preApprovalPlanner($source);
    $inspection = $planner->inspectSource($source->intake->extractionRoot(), $source->intake->identity()->adapterId());
    $original = $planner->prepare($inspection, $pt);
    C::equal($original->failures(), [], 'repair_original_planning_failed');
    $blocked = [];
    foreach ($original->findings() as $finding) {
        if ($finding->reasonCode() === 'unresolved_classification_dependency') { $blocked[] = $finding->sourceId(); }
    }
    $approvedIds = array_column($members, 'copy_id'); sort($blocked, SORT_STRING); sort($approvedIds, SORT_STRING);
    C::equal($blocked, $approvedIds, 'repair_blocked_population_changed');
    $tables = new CoreTableNames('wp_'); $ledger = new WpdbMigrationLedgerRepository($wpdb, $tables);
    C::equal((int) $wpdb->get_var('SELECT COUNT(*) FROM wp_biblio_migration_runs'), 1, 'repair_unexpected_run');
    $snapshot = $ledger->snapshot(REPAIR_RUN);
    C::equal($snapshot->run()->status()->value, 'completed', 'repair_original_run_not_completed');
    $old = []; $typed = [];
    foreach ($snapshot->observations() as $observation) { $old[$observation->sourceType()][$observation->sourceId()] = $observation; }
    foreach ($original->records() as $record) { $typed[$record->record()->sourceType()][$record->record()->sourceId()] = $record->record(); }
    foreach ($members as $member) {
        foreach ($member['mapping'] as $type => $binding) {
            $observation = $old[$type][$binding['source_id']] ?? null;
            $record = $typed[$type][$binding['source_id']] ?? null;
            C::require($observation !== null && $record !== null, 'repair_catalog_mapping_missing');
            C::equal($observation->payloadHash(), $binding['payload_hash'], 'repair_catalog_observation_changed');
            C::equal($record->payloadHash(), $binding['payload_hash'], 'repair_catalog_plan_changed');
            $ids = [];
            foreach ($observation->mappings() as $mapping) {
                if ($mapping->targetType() === $binding['target_type']) { $ids[] = $mapping->targetId(); }
            }
            C::equal($ids, [$binding['target_id']], 'repair_catalog_target_changed');
        }
        C::equal($wpdb->get_var($wpdb->prepare('SELECT work_id FROM wp_biblio_editions WHERE edition_id=%s', $member['mapping']['catalog_edition']['target_id'])), $member['mapping']['catalog_work']['target_id'], 'repair_edition_relation_changed');
        C::equal($ledger->committedSourceTargets('227', $library->value(), CurrentV1SourceAdapter::SOURCE_FAMILY, 'catalog_item', CurrentV1CatalogSourceIds::item($member['copy_id'])), [], 'repair_item_already_mapped');
    }
    $convergedGroups = repairJson($config['converged_groups']);
    C::equal(DeterministicJson::hash($convergedGroups), REPAIR_CONVERGENCE, 'repair_converged_groups_changed');
    C::equal(count($convergedGroups), 7, 'repair_converged_group_count_changed');
    $contract = (new CurrentV1ClassificationRepairApprovals($members, REPAIR_POPULATION))->contract(REPAIR_MANIFEST, $inspection->records(), $convergedGroups);
    $mapped = (new CurrentV1CatalogMapper(
        classificationMapper: new CurrentV1ClassificationMapper(new WpdbLibraryBookTypeRepository($wpdb, $tables), new WpdbLibraryGenreRepository($wpdb, $tables), $contract),
        itemLocalMapper: new CurrentV1ItemLocalMapper(new CurrentV1ReviewedItemLocalContract(REPAIR_MANIFEST), new CurrentV1ReviewedCopyExclusionContract(REPAIR_MANIFEST, $source->review['copy_exclusions']))
    ))->map($inspection, $pt);
    $wanted = []; foreach ($approvedIds as $copyId) { $wanted[CurrentV1CatalogSourceIds::item($copyId)] = true; }
    $memberByItem = []; foreach ($members as $member) { $memberByItem[CurrentV1CatalogSourceIds::item($member['copy_id'])] = $member; }
    $contexts = new \Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryCatalogContextRepository($wpdb, $tables);
    $participant = $app->migrationParticipants()->forType(CatalogItemMigrationParticipant::SOURCE_TYPE);
    C::require($participant !== null, 'repair_item_participant_missing');
    $records = []; $plannedIds = []; $identities = []; $newContexts = []; $eligibleWorkIds = [];
    foreach ($mapped->records() as $record) {
        if ($record->sourceType() !== 'catalog_item') { continue; }
        if (!isset($wanted[$record->sourceId()])) {
            C::equal($record->payloadHash(), ($typed['catalog_item'][$record->sourceId()] ?? null)?->payloadHash(), 'repair_unrelated_item_plan_changed');
            continue;
        }
        C::equal($record->payload()['classification']['genre_ids'], [], 'repair_genre_promotion');
        C::equal($record->payload()['classification']['subject_ids'], [], 'repair_subject_promotion');
        C::equal($record->payload()['approved_existing_item_id'], null, 'repair_copy_identity_reuse');
        $member = $memberByItem[$record->sourceId()];
        C::equal($record->typedPlan()->editionSourceId(), $member['mapping']['catalog_edition']['source_id'], 'repair_dependency_changed');
        $context = $contexts->find($library, new \Biblio\Core\Catalog\WorkId($member['mapping']['catalog_work']['target_id']));
        C::require($context === null || $context->hasSameClassification($record->typedPlan()->classification()), 'repair_existing_classification_conflict');
        if ($context === null) { $newContexts[$member['mapping']['catalog_work']['target_id']] = $record->payload()['classification']['book_type_id']; }
        $inventory = $record->typedPlan()->inventoryNumber()?->value();
        if ($inventory !== null) {
            C::equal((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM wp_biblio_items WHERE library_id=%s AND inventory_number=%s', $library->value(), $inventory)), 0, 'repair_inventory_conflict');
        }
        $plan = $participant->plan($record, $pt);
        $records[] = new PreparedMigrationRecord($record, $participant, $plan);
        // Only exact approved Copy records admitted by the existing Item planner
        // enter post-repair expectations. No Book/title-level eligibility inference.
        $eligibleWorkIds[$record->sourceId()] = $member['mapping']['catalog_work']['target_id'];
        $plannedIds[] = $record->sourceId(); $identities[] = $record->identityArray();
    }
    sort($plannedIds, SORT_STRING); $wantedIds = array_keys($wanted); sort($wantedIds, SORT_STRING);
    C::equal($plannedIds, $wantedIds, 'repair_item_plan_set_mismatch');
    C::equal(count($records), 342, 'repair_item_plan_count_mismatch');
    $digest = DeterministicJson::hash([$contract->identity(), REPAIR_POPULATION, $identities]);
    $prepared = new PreparedMigrationPlan($inspection, $pt, $records, [], [], [$contract->identity()], $digest, $source->bundle->digest(), $original->profileReview());
    $catalogBefore = repairCatalog($app, $library);
    $oldLinks = [];
    foreach ($old['catalog_item'] as $sourceId => $observation) {
        foreach ($observation->mappings() as $mapping) {
            if ($mapping->targetType() === 'item') { $oldLinks[$sourceId] = $mapping->targetId(); }
        }
    }
    $coverageBefore = repairSourceCoverage($inspection, $oldLinks, $catalogBefore, $source->review['copy_exclusions']);
    C::equal($coverageBefore['nora_source_books'], 62, 'repair_nora_source_changed');
    C::equal(count($coverageBefore['nora_visible_book_ids']), 37, 'repair_nora_before_changed');
    C::equal(count($coverageBefore['nora_excluded_book_ids']), 3, 'repair_nora_exclusions_changed');
    $noraApproved = [];
    foreach ($inspection->records() as $record) {
        if ($record->sourceType() === CurrentV1SourceAdapter::BOOK && in_array($record->sourceId(), array_column($members, 'book_id'), true)
            && in_array('Nora Roberts', $record->payload()['authors'] ?? [], true)) { $noraApproved[] = $record->sourceId(); }
    }
    C::equal(count($noraApproved), 22, 'repair_nora_approved_changed');
    // Resolve actual schema names rather than granting a broad migration-table allowlist.
    $insertTables = [$tables->items(), $tables->itemLocalDetails(), $tables->libraryCatalogContexts(), $tables->migrationRuns(), $tables->migrationSourceObservations(), $tables->migrationTargetMappings(), $tables->migrationPreservations()];
    $oldRows = []; foreach ($insertTables as $table) { $oldRows[$table] = repairRowHashes($wpdb, $table); }
    C::equal($target->fingerprint(), $baseline, 'repair_preparation_wrote_database');
    $packet = ['purpose' => 'POST-CUTOVER-FIX-01', 'environment' => $identity, 'population_sha256' => REPAIR_POPULATION, 'plan_sha256' => $digest,
        'backup_sha256' => $backup['backup']['sha256'], 'baseline' => $baseline, 'config_sha256' => hash_file('sha256', $argv[2]), 'catalog_before' => $catalogBefore, 'source_coverage_before' => $coverageBefore];
    if ($action === 'prepare') {
        repairSave($output . '/packet.json', $packet);
        echo json_encode(['status' => 'PREPARED_ZERO_WRITE', 'packet_sha256' => DeterministicJson::hash($packet), 'items_planned' => count($records), 'catalog_before' => array_diff_key($catalogBefore, ['items' => true])], JSON_THROW_ON_ERROR), "\n";
        exit(0);
    }
    C::equal(repairJson($output . '/packet.json'), $packet, 'repair_packet_drift');
    C::equal($argv[3], DeterministicJson::hash($packet), 'repair_packet_confirmation_mismatch');
    C::require(!file_exists($output . '/execution-start.json'), 'repair_already_attempted');
    $transport = new MariaDbProductionTransport($wpdb, 'db', 'db', 'root', 'root', $target);
    $target->blockWrites();
    C::equal($target->fingerprint(), $baseline, 'repair_pre_write_drift');
    repairSave($output . '/execution-start.json', ['packet_sha256' => $argv[3], 'candidate_sha' => $config['candidate_sha']]);
    $writesStarted = true;
    $transactions = new WpdbTransactionManager($wpdb); $clock = new SystemMigrationClock();
    $run = (new BeginMigrationRunService($app->personalMigrationTargets(), $ledger, $transactions, $clock, new OpaqueMigrationRunIdGenerator()))->begin(
        CurrentV1SourceAdapter::SOURCE_FAMILY, REPAIR_MANIFEST, REPAIR_MANIFEST, $inspection->profile()->sourceVersion(), 'post-cutover-fix-01', $user, $library, MigrationMode::Apply
    );
    $observe = new ObserveSourceRecordService($ledger, $clock); $commit = new CommitMigrationRecordService($ledger, $transactions, $clock);
    foreach ($records as $record) {
        $target->assertWritesBlocked(); $sourceRecord = $record->record();
        $observation = $observe->observe($run, $sourceRecord->sourceType(), $sourceRecord->sourceId(), $sourceRecord->payloadHash(), $sourceRecord->payload());
        $outcome = $commit->commit($run, $observation, static fn() => $participant->apply($sourceRecord, $observation, $record->plan(), $pt));
        C::require(in_array($outcome->disposition()->value, ['mapped','preserved_deferred'], true), 'repair_item_apply_failed');
    }
    $reconciliation = $app->migrationReconciliation()->reconcileItemRepair($run, $prepared, REPAIR_RUN);
    C::require($reconciliation->accepted(), 'repair_reconciliation_failed');
    $run = (new MigrationRunLifecycleService($ledger, $transactions, $clock))->complete($run);
    $after = $target->fingerprint();
    C::equal(array_keys($after['tables']), array_keys($baseline['tables']), 'repair_table_inventory_changed');
    C::equal($after['database_metadata'], $baseline['database_metadata'], 'repair_database_metadata_changed');
    C::equal($after['trigger_sha256'], $baseline['trigger_sha256'], 'repair_triggers_changed');
    foreach ($baseline['tables'] as $table => $state) {
        C::equal($after['tables'][$table]['schema_sha256'], $state['schema_sha256'], 'repair_schema_changed');
        if (!in_array($table, $insertTables, true)) { C::equal($after['tables'][$table], $state, 'repair_unrelated_table_changed'); }
        else { C::equal(array_values(array_diff($oldRows[$table], repairRowHashes($wpdb, $table))), [], 'repair_existing_row_changed'); }
    }
    C::equal($after['tables'][$tables->items()]['rows'], $baseline['tables'][$tables->items()]['rows'] + 342, 'repair_item_total_wrong');
    C::equal($after['tables'][$tables->libraryCatalogContexts()]['rows'], $baseline['tables'][$tables->libraryCatalogContexts()]['rows'] + count($newContexts), 'repair_context_total_wrong');
    $catalogAfter = repairCatalog($app, $library);
    $newItems = []; $repairMappings = [];
    foreach ($ledger->snapshot($run->id())->observations() as $observation) {
        $targets = array_values(array_filter($observation->mappings(), static fn($m) => $m->targetType() === 'item'));
        C::equal(count($targets), 1, 'repair_item_mapping_ambiguous');
        $id = $targets[0]->targetId(); C::require(!isset($newItems[$id]) && isset($catalogAfter['items'][$id]), 'repair_item_duplicate_or_invisible');
        $newItems[$id] = true; $repairMappings[] = ['source_id' => $observation->sourceId(), 'item_id' => $id];
    }
    $links = (new ItemRepairVerifier())->verify($eligibleWorkIds, $repairMappings,
        array_map(static fn($e) => CurrentV1CatalogSourceIds::item($e['source_id']), $source->review['copy_exclusions']),
        $oldLinks, $catalogAfter['items']);
    C::equal(count($newItems), 342, 'repair_new_identity_count_wrong');
    C::equal(array_intersect_key($catalogBefore['items'], $newItems), [], 'repair_reused_existing_item');
    C::equal(array_intersect_key($catalogAfter['items'], $catalogBefore['items']), $catalogBefore['items'], 'repair_previous_catalog_changed');
    C::equal($catalogAfter['item_count'], $catalogBefore['item_count'] + 342, 'repair_catalog_total_wrong');
    $coverageAfter = repairSourceCoverage($inspection, $oldLinks + $links, $catalogAfter, $source->review['copy_exclusions']);
    $expectedNora = [...$coverageBefore['nora_visible_book_ids'], ...$noraApproved]; sort($expectedNora, SORT_STRING);
    C::equal($coverageAfter['nora_visible_book_ids'], $expectedNora, 'repair_nora_visibility_wrong');
    C::equal(count($coverageAfter['nora_visible_book_ids']), 59, 'repair_nora_total_wrong');
    C::equal($coverageAfter['nora_excluded_book_ids'], $coverageBefore['nora_excluded_book_ids'], 'repair_nora_exclusions_changed');
    foreach (REPAIR_EXAMPLES as $title) {
        C::require(isset($coverageAfter['examples'][$title]), 'repair_source_example_missing');
    }
    // Per-Copy Item requirements are verified above. Example visibility must not
    // turn a correctly excluded sibling Copy into a required Item.
    foreach (REPAIR_EXAMPLES as $title) { C::require(isset($catalogAfter['examples'][$title]), 'repair_example_not_visible'); }
    $mackade = $catalogAfter['examples'][REPAIR_EXAMPLES[0]];
    C::require(count($mackade) >= 1 && in_array('Nora Roberts', $mackade[0]['authors'], true)
        && in_array(['name' => 'MacKade Brothers', 'position' => '1'], $mackade[0]['series'], true), 'repair_mackade_relation_wrong');
    C::equal($target->fingerprint(), $after, 'repair_application_read_wrote_database');
    $result = ['status' => 'VERIFIED_WRITES_REMAIN_BLOCKED', 'candidate_sha' => $config['candidate_sha'], 'run_id' => $run->id(), 'packet_sha256' => $argv[3],
        'items_created' => count($newItems), 'item_links' => $links, 'catalog_before' => $catalogBefore, 'catalog_after' => $catalogAfter,
        'new_context_count' => count($newContexts), 'new_context_book_types' => array_count_values($newContexts),
        'source_coverage_before' => $coverageBefore, 'source_coverage_after' => $coverageAfter,
        'reconciliation' => $reconciliation->toArray(), 'fingerprint' => $after, 'existing_rows_unchanged' => true, 'application_read_verified' => true];
    repairSave($output . '/completed.json', $result);
    echo json_encode(['status' => $result['status'], 'run_id' => $run->id(), 'items_created' => 342, 'catalog_after' => array_diff_key($catalogAfter, ['items' => true]), 'database_sha256' => $after['database_sha256']], JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $failure) {
    $reason = $failure instanceof RehearsalFailure ? $failure->reason : 'repair_execution_failed';
    if ($writesStarted) {
        try {
            repairSave($output . '/failure.json', ['reason' => $reason, 'throwable_type' => get_class($failure), 'message_sha256' => hash('sha256', $failure->getMessage())]);
        } catch (Throwable) {
            fwrite(STDERR, "Repair failure evidence write failed; continuing the authorized rollback.\n");
        }
        // This exact owner request explicitly mandates restoring the verified
        // PRE-CLASSIFICATION-REPAIR point after any material repair failure.
        $target->assertWritesBlocked();
        C::equal(hash_file('sha256', $config['backup_path']), $backup['backup']['sha256'], 'repair_rollback_backup_changed');
        $transport->import($config['backup_path']);
        C::equal($target->fingerprint(), $baseline, 'repair_rollback_fingerprint_mismatch');
        repairSave($output . '/rolled-back.json', ['status' => 'POST-CUTOVER-FIX-01 — ROLLED BACK', 'fingerprint' => $baseline]);
        fwrite(STDERR, "POST-CUTOVER-FIX-01 — ROLLED BACK; writes remain blocked\n");
    } else { fwrite(STDERR, json_encode(['status' => 'HOLD', 'reason' => $reason], JSON_THROW_ON_ERROR) . "\n"); }
    exit(1);
} finally { $target?->release(); }
