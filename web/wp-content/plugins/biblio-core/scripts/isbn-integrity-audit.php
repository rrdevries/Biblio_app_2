<?php

use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Infrastructure\Persistence\WordPress\Schema\WpdbIsbnIntegrityAuditor;

global $wpdb;

if (!$wpdb instanceof \wpdb) {
    fwrite(STDERR, "WordPress database connection is unavailable.\n");
    exit(2);
}

$report = (new WpdbIsbnIntegrityAuditor(
    $wpdb,
    new CoreTableNames($wpdb->prefix)
))->audit();

fwrite(
    STDOUT,
    json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"
);
exit($report->hasBlockers() ? 1 : 0);
