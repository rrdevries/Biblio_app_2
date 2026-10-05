<?php

declare(strict_types=1);
use Biblio\Core\Tests\Support\AccountPreparationFixture;
use Biblio\Core\Infrastructure\Persistence\WordPress\CoreTableNames;
use Biblio\Core\Identity\UserId;
if ($argc !== 5) { exit(2); }
[, $accountWorkerAction, $accountWorkerSent, $accountWorkerReady, $accountWorkerRelease] = $argv;
require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/Support/AccountPreparationFixture.php';
file_put_contents($accountWorkerReady, 'ready');
$deadline = microtime(true) + 15;
while (!is_file($accountWorkerRelease)) {
    if (microtime(true) >= $deadline) { throw new RuntimeException('Account barrier timed out.'); }
    usleep(10000);
}
$service = AccountPreparationFixture::service($wpdb, new CoreTableNames($wpdb->prefix));
$target = new UserId('new-race');
$payload = $accountWorkerAction === 'prepare'
    ? ['library_id' => $service->prepare($target)->value()]
    : ['sent' => $service->notify($target, static function () use ($accountWorkerSent): bool {
        file_put_contents($accountWorkerSent, "sent\n", FILE_APPEND | LOCK_EX);
        return true;
    })];
fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR) . "\n");
