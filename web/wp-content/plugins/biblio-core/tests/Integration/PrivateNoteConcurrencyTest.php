<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use RuntimeException;

final class PrivateNoteConcurrencyTest extends PersistenceIntegrationTestCase
{
    public function testDivergentConcurrentUpdatesHaveOneWinnerAndOneStaleResult(): void
    {
        $this->persistNote('race-divergent');
        $results = $this->race([
            ['update', '<p>Winnaar A</p>'],
            ['update', '<p>Winnaar B</p>'],
        ], 'race-divergent');
        $statuses = array_column($results, 'status');
        sort($statuses);

        self::assertSame(['stale', 'updated'], $statuses);
        self::assertSame(2, $this->storedVersion('race-divergent'));
        self::assertContains(
            $this->storedContent('race-divergent'),
            ['<p>Winnaar A</p>', '<p>Winnaar B</p>']
        );
    }

    public function testEqualConcurrentUpdatesConvergeToOneWriteAndCurrentNoOp(): void
    {
        $this->persistNote('race-equal');
        $results = $this->race([
            ['update', '<p>Gelijk</p>'],
            ['update', '<p>Gelijk</p>'],
        ], 'race-equal');

        self::assertSame(['updated', 'updated'], array_column($results, 'status'));
        self::assertSame(2, $this->storedVersion('race-equal'));
        self::assertSame('<p>Gelijk</p>', $this->storedContent('race-equal'));
    }

    public function testConcurrentUpdateAndDeleteHaveOneConsistentWinner(): void
    {
        $this->persistNote('race-delete');
        $results = $this->race([
            ['update', '<p>Update</p>'],
            ['delete', 'unused'],
        ], 'race-delete');
        $statuses = array_column($results, 'status');

        self::assertTrue(
            in_array($statuses, [
                ['updated', 'stale'],
                ['not_available', 'deleted'],
            ], true),
            'Unexpected update/delete outcomes: ' . json_encode($statuses)
        );

        $row = $this->database->get_row($this->database->prepare(
            "SELECT note_content, note_version FROM `{$this->tableNames->privateNotes()}` "
            . "WHERE private_note_id = %s",
            'race-delete'
        ));

        if ($row === null) {
            self::assertContains('deleted', $statuses);
        } else {
            self::assertSame('<p>Update</p>', $row->note_content);
            self::assertSame(2, (int) $row->note_version);
        }
    }

    /** @param list<array{0: string, 1: string}> $operations */
    private function race(array $operations, string $noteId): array
    {
        $directory = sys_get_temp_dir() . '/biblio-note-race-' . bin2hex(random_bytes(8));

        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Could not create Private Note race directory.');
        }

        $releasePath = $directory . '/release';
        $readyPaths = [$directory . '/a-ready', $directory . '/b-ready'];
        $workers = [];

        try {
            foreach ($operations as $index => [$action, $content]) {
                $workers[] = $this->startWorker(
                    $action,
                    $noteId,
                    $content,
                    $readyPaths[$index],
                    $releasePath
                );
            }

            $this->awaitWorkers($workers, $readyPaths);

            if (file_put_contents($releasePath, 'release') === false) {
                throw new RuntimeException('Could not release Private Note workers.');
            }

            return [
                $this->finishWorker($workers[0]),
                $this->finishWorker($workers[1]),
            ];
        } finally {
            foreach ($workers as $worker) {
                $this->terminateWorker($worker);
            }

            foreach ([...$readyPaths, $releasePath] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }

            rmdir($directory);
        }
    }

    private function startWorker(
        string $action,
        string $noteId,
        string $content,
        string $readyPath,
        string $releasePath
    ): array {
        $pipes = [];
        $process = proc_open([
            PHP_BINARY,
            __DIR__ . '/Support/PrivateNoteMutationWorker.php',
            $action,
            $noteId,
            $content,
            'race-user',
            $readyPath,
            $releasePath,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start Private Note race worker.');
        }

        return ['process' => $process, 'pipes' => $pipes];
    }

    private function awaitWorkers(array $workers, array $readyPaths): void
    {
        $deadline = microtime(true) + 15;

        while (!is_file($readyPaths[0]) || !is_file($readyPaths[1])) {
            foreach ($workers as $worker) {
                if (!proc_get_status($worker['process'])['running']) {
                    throw new RuntimeException(
                        'Private Note worker exited early: '
                        . stream_get_contents($worker['pipes'][2])
                    );
                }
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Private Note workers missed barrier.');
            }

            usleep(10_000);
        }
    }

    private function finishWorker(array $worker): array
    {
        $output = stream_get_contents($worker['pipes'][1]);
        $error = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);

        if ($exitCode !== 0) {
            throw new RuntimeException("Private Note worker failed: {$error}");
        }

        return json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
    }

    private function terminateWorker(array $worker): void
    {
        if (!is_resource($worker['process'])) {
            return;
        }

        $status = proc_get_status($worker['process']);

        if ($status['running']) {
            proc_terminate($worker['process']);
            proc_close($worker['process']);
        }
    }

    private function persistNote(string $id): void
    {
        $this->database->insert($this->tableNames->works(), [
            'work_id' => 'race-work',
            'work_title' => 'Race Work',
        ], ['%s', '%s']);
        $result = $this->database->insert($this->tableNames->privateNotes(), [
            'private_note_id' => $id,
            'user_id' => 'race-user',
            'work_id' => 'race-work',
            'reading_round_id' => null,
            'note_content' => '<p>Start</p>',
            'created_at' => '2026-08-22 10:00:00.000000',
            'updated_at' => '2026-08-22 10:00:00.000000',
            'note_version' => 1,
        ], ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d']);
        self::assertSame(1, $result, $this->database->last_error);
    }

    private function storedVersion(string $id): int
    {
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT note_version FROM `{$this->tableNames->privateNotes()}` "
            . "WHERE private_note_id = %s",
            $id
        ));
    }

    private function storedContent(string $id): string
    {
        return (string) $this->database->get_var($this->database->prepare(
            "SELECT note_content FROM `{$this->tableNames->privateNotes()}` "
            . "WHERE private_note_id = %s",
            $id
        ));
    }
}
