<?php

declare(strict_types=1);
namespace Biblio\Core\Tests\Integration;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Tests\Support\AccountPreparationFixture;
use RuntimeException;
require_once dirname(__DIR__) . "/Support/AccountPreparationFixture.php";
final class AccountPreparationConcurrencyTest extends PersistenceIntegrationTestCase
{
    public function testConcurrentPreparationReusesExactlyOneLibrary(): void
    {
        $results = $this->race([["prepare", "-"], ["prepare", "-"]]);
        self::assertSame($results[0]["library_id"], $results[1]["library_id"]);
        self::assertSame("1", (string) $this->database->get_var("SELECT COUNT(*) FROM `{$this->tableNames->libraries()}`"));
        self::assertSame("1", (string) $this->database->get_var("SELECT COUNT(*) FROM `{$this->tableNames->personalLibraryDesignations()}`"));
    }
    public function testConcurrentNotificationRetrySendsOnce(): void
    {
        AccountPreparationFixture::service($this->database, $this->tableNames)->prepare(new UserId("new-race"));
        $sent = tempnam(sys_get_temp_dir(), "biblio-test-mail-");
        try {
            $results = $this->race([["notify", $sent], ["notify", $sent]]);
            self::assertTrue($results[0]["sent"]);
            self::assertTrue($results[1]["sent"]);
            self::assertSame("sent\n", file_get_contents($sent));
        } finally { unlink($sent); }
    }
    /** @param list<array{0:string,1:string}> $operations */
    private function race(array $operations): array
    {
        $directory = sys_get_temp_dir() . "/biblio-account-race-"
            . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException("Could not create Account preparation race directory.");
        }
        $release = $directory . "/release";
        $ready = [$directory . "/a-ready", $directory . "/b-ready"];
        $workers = [];

        try {
            foreach ($operations as $index => [$action, $edition]) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    __DIR__ . "/Support/AccountPreparationWorker.php",
                    $action,
                    $edition,
                    $ready[$index],
                    $release,
                ], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
                if (!is_resource($process)) {
                    throw new RuntimeException("Could not start Account preparation worker.");
                }
                $workers[] = ["process" => $process, "pipes" => $pipes];
            }

            $deadline = microtime(true) + 15;
            while (!is_file($ready[0]) || !is_file($ready[1])) {
                foreach ($workers as $worker) {
                    if (!proc_get_status($worker["process"])["running"]) {
                        throw new RuntimeException(
                            "Account preparation worker exited early: "
                                . stream_get_contents($worker["pipes"][2])
                        );
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException("Account preparation workers missed barrier.");
                }
                usleep(10_000);
            }
            if (file_put_contents($release, "release") === false) {
                throw new RuntimeException("Could not release Account preparation workers.");
            }

            return [$this->finish($workers[0]), $this->finish($workers[1])];
        } finally {
            foreach ($workers as $worker) {
                if (is_resource($worker["process"]) && proc_get_status($worker["process"])["running"]) {
                    proc_terminate($worker["process"]);
                    proc_close($worker["process"]);
                }
            }
            foreach ([...$ready, $release] as $path) {
                if (is_file($path)) { unlink($path); }
            }
            rmdir($directory);
        }
    }

    /** @param array{process:resource,pipes:array<int,resource>} $worker */
    private function finish(array $worker): array
    {
        $output = stream_get_contents($worker["pipes"][1]);
        $error = stream_get_contents($worker["pipes"][2]);
        fclose($worker["pipes"][1]);
        fclose($worker["pipes"][2]);
        $exit = proc_close($worker["process"]);
        if ($exit !== 0) {
            throw new RuntimeException("Account preparation worker failed: {$error}");
        }
        return json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
    }

}
