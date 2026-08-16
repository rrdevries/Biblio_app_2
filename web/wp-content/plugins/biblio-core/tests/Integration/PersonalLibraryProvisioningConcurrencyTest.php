<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Identity\UserId;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbLibraryMembershipRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbPersonalLibraryRepository;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Library\ManagementRole;
use Biblio\Core\Library\MembershipStatus;
use Biblio\Core\Library\UseAccess;
use RuntimeException;

final class PersonalLibraryProvisioningConcurrencyTest extends
    PersistenceIntegrationTestCase
{
    public function testConcurrentCallsReturnOneDesignatedLibrary(): void
    {
        $temporaryDirectory = sys_get_temp_dir()
            . "/biblio-personal-race-"
            . bin2hex(random_bytes(8));

        if (!mkdir($temporaryDirectory, 0700)) {
            throw new RuntimeException("Could not create race directory.");
        }

        $releasePath = $temporaryDirectory . "/release";
        $readyPaths = [
            $temporaryDirectory . "/worker-a-ready",
            $temporaryDirectory . "/worker-b-ready",
        ];
        $workers = [];

        try {
            $workers[] = $this->startWorker(
                "concurrent-user",
                $readyPaths[0],
                $releasePath
            );
            $workers[] = $this->startWorker(
                "concurrent-user",
                $readyPaths[1],
                $releasePath
            );

            $this->awaitBothWorkersAtDesignation($workers, $readyPaths);

            if (file_put_contents($releasePath, "release") === false) {
                throw new RuntimeException("Could not release race barrier.");
            }

            $libraryIds = [
                $this->finishWorker($workers[0]),
                $this->finishWorker($workers[1]),
            ];

            self::assertSame($libraryIds[0], $libraryIds[1]);
            self::assertSame(1, $this->tableCount(
                $this->tableNames->libraries()
            ));
            self::assertSame(1, $this->tableCount(
                $this->tableNames->memberships()
            ));
            self::assertSame(1, $this->tableCount(
                $this->tableNames->personalLibraryDesignations()
            ));

            $userId = new UserId("concurrent-user");
            $libraryId = new LibraryId($libraryIds[0]);
            $designation = (new WpdbPersonalLibraryRepository(
                $this->database,
                $this->tableNames
            ))->findForUser($userId);
            $membership = (new WpdbLibraryMembershipRepository(
                $this->database,
                $this->tableNames
            ))->findFor($libraryId, $userId);

            self::assertNotNull($designation);
            self::assertTrue($libraryId->equals($designation));
            self::assertNotNull($membership);
            self::assertSame(
                MembershipStatus::Active,
                $membership->membership()->status()
            );
            self::assertSame(
                ManagementRole::Owner,
                $membership->membership()->managementRole()
            );
            self::assertSame(
                UseAccess::Direct,
                $membership->membership()->useAccess()
            );
        } finally {
            foreach ($workers as $worker) {
                $this->terminateWorkerIfRunning($worker);
            }

            foreach ([...$readyPaths, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            rmdir($temporaryDirectory);
        }
    }

    private function startWorker(
        string $userId,
        string $readyPath,
        string $releasePath
    ): array {
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                __DIR__ . "/Support/PersonalLibraryProvisioningWorker.php",
                $userId,
                $readyPath,
                $releasePath,
            ],
            [
                1 => ["pipe", "w"],
                2 => ["pipe", "w"],
            ],
            $pipes
        );

        if (!is_resource($process)) {
            throw new RuntimeException("Could not start race worker.");
        }

        return ["process" => $process, "pipes" => $pipes];
    }

    private function awaitBothWorkersAtDesignation(
        array $workers,
        array $readyPaths
    ): void {
        $deadline = microtime(true) + 15;

        while (!is_file($readyPaths[0]) || !is_file($readyPaths[1])) {
            foreach ($workers as $worker) {
                $status = proc_get_status($worker["process"]);

                if (!$status["running"]) {
                    $error = stream_get_contents($worker["pipes"][2]);
                    throw new RuntimeException(
                        "Race worker exited before barrier: " . $error
                    );
                }
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException(
                    "Workers did not reach the forced race window."
                );
            }

            usleep(10_000);
        }
    }

    private function finishWorker(array $worker): string
    {
        $output = stream_get_contents($worker["pipes"][1]);
        $error = stream_get_contents($worker["pipes"][2]);

        fclose($worker["pipes"][1]);
        fclose($worker["pipes"][2]);

        $exitCode = proc_close($worker["process"]);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "Race worker failed with {$exitCode}: " . $error
            );
        }

        return trim($output);
    }

    private function terminateWorkerIfRunning(array $worker): void
    {
        if (!is_resource($worker["process"])) {
            return;
        }

        $status = proc_get_status($worker["process"]);

        if ($status["running"]) {
            proc_terminate($worker["process"]);
            proc_close($worker["process"]);
        }
    }

    private function tableCount(string $table): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$table}`"
        );
    }
}
