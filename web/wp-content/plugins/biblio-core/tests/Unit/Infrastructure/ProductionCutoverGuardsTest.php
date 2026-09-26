<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Unit\Infrastructure;

use Biblio\Core\Application\Migration\Cutover\{ProductionAuthorization, ProductionTestResetAuthorization, RehearsalFailure};
use Biblio\Core\Infrastructure\Migration\WpdbProductionMigrationTarget;
use PHPUnit\Framework\TestCase;

final class ProductionCutoverGuardsTest extends TestCase
{
    public function testResetHasNoDefaultActionAndCannotUseFinalAuthority(): void
    {
        $path = dirname(__DIR__, 7) . '/scripts/migration-test-target-reset.php';
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1', $output, $status);
        self::assertSame(2, $status);
        $packet = ['binding' => ['scope' => 'synthetic-only'], 'backup' => ['phase' => 'PRE_RESET']];
        $digest = ProductionTestResetAuthorization::digest($packet);
        $approval = ['purpose' => 'production-test-target-reset', 'packet_digest' => $digest, 'confirmation' => 'RESET TEST TARGET ' . $digest];
        self::assertSame($packet, (new ProductionTestResetAuthorization($packet, $approval))->packet);
        foreach (['purpose' => 'production-cutover', 'packet_digest' => str_repeat('f', 64), 'confirmation' => 'yes'] as $key => $value) {
            try { new ProductionTestResetAuthorization($packet, array_replace($approval, [$key => $value])); self::fail('Reset authority weakened'); }
            catch (RehearsalFailure) { self::assertTrue(true); }
        }
        foreach (['PRE_APPLY', 'POST_APPLY', null] as $phase) {
            $other = array_replace($packet, ['backup' => ['phase' => $phase]]);
            $hash = ProductionTestResetAuthorization::digest($other);
            try { new ProductionTestResetAuthorization($other, ['purpose'=>'production-test-target-reset', 'packet_digest'=>$hash, 'confirmation'=>'RESET TEST TARGET ' . $hash]); self::fail('Wrong reset backup accepted'); }
            catch (RehearsalFailure $e) { self::assertSame('pre_reset_backup_required', $e->reason); }
        }
    }

    public function testNoDefaultCommandCanApply(): void
    {
        $path = dirname(__DIR__, 7) . '/scripts/migration-production.php';
        self::assertFileExists($path);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path) . ' 2>&1', $output, $status);
        self::assertSame(2, $status);
        self::assertStringContainsString('No default action', implode("\n", $output));
    }
    public function testOnlyExactProductionLocationIsAccepted(): void
    {
        $expected = ['purpose' => 'production', 'project' => 'biblio-v2', 'database' => 'db', 'root' => '/var/www/html', 'url' => 'https://biblio-v2.ddev.site'];
        WpdbProductionMigrationTarget::assertLocation($expected, 'biblio-v2', 'db', '/var/www/html', 'https://biblio-v2.ddev.site');
        foreach (['purpose', 'project', 'database', 'root', 'url'] as $key) {
            $changed = $expected;
            $changed[$key] = 'unknown';
            try { WpdbProductionMigrationTarget::assertLocation($changed, 'biblio-v2', 'db', '/var/www/html', 'https://biblio-v2.ddev.site'); self::fail('Changed identity accepted'); }
            catch (RehearsalFailure) { self::assertTrue(true); }
        }
        foreach (['biblio_cutover_0123456789ab', 'biblio_core_test', 'unknown'] as $database) {
            try { WpdbProductionMigrationTarget::assertLocation($expected, 'biblio-v2', $database, '/var/www/html', 'https://biblio-v2.ddev.site'); self::fail('Wrong DB accepted'); }
            catch (RehearsalFailure) { self::assertTrue(true); }
        }
    }

    public function testAuthorizationBindsEntireTupleAndExactPreBackup(): void
    {
        $packet = ['binding' => ['git_sha' => str_repeat('a', 40), 'package_id' => 'synthetic', 'archive' => str_repeat('b', 64),
            'manifest' => str_repeat('c', 64), 'bundle' => str_repeat('d', 64), 'plan' => str_repeat('e', 64), 'user' => '2', 'library' => 'synthetic-library'],
            'backup' => ['phase' => 'PRE_APPLY', 'sha256' => str_repeat('f', 64)]];
        $digest = ProductionAuthorization::digest($packet);
        $approval = ['purpose' => 'production-cutover', 'packet_digest' => $digest, 'confirmation' => 'AUTHORIZE PRODUCTION ' . $digest];
        self::assertSame($packet, (new ProductionAuthorization($packet, $approval))->packet);
        foreach (array_keys($packet['binding']) as $key) {
            $changed = $packet;
            $changed['binding'][$key] .= '-changed';
            try { new ProductionAuthorization($changed, $approval); self::fail('Changed tuple accepted'); }
            catch (RehearsalFailure) { self::assertTrue(true); }
        }
        foreach (['approved_for_rehearsal', '', 'rehearsal_only'] as $purpose) {
            try { new ProductionAuthorization($packet, array_replace($approval, ['purpose' => $purpose])); self::fail('Nonproduction approval accepted'); }
            catch (RehearsalFailure) { self::assertTrue(true); }
        }
        $missing = $packet;
        unset($missing['backup']);
        $missingDigest = ProductionAuthorization::digest($missing);
        try { new ProductionAuthorization($missing, ['purpose' => 'production-cutover', 'packet_digest' => $missingDigest, 'confirmation' => 'AUTHORIZE PRODUCTION ' . $missingDigest]); self::fail('Missing PRE accepted'); }
        catch (RehearsalFailure $failure) { self::assertSame('pre_apply_backup_required', $failure->reason); }
        $authorization = new ProductionAuthorization($packet, $approval);
        $authorization->assertRestoreConfirmation('RESTORE PRODUCTION ' . $digest);
        $this->expectException(RehearsalFailure::class);
        $authorization->assertRestoreConfirmation('yes');
    }
}
