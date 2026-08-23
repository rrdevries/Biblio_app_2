<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use RuntimeException;

final class AssessmentConcurrencyTest extends PersistenceIntegrationTestCase
{
    /** @var list<int> */
    private array $wordpressUsers = [];

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        foreach ($this->wordpressUsers as $userId) {
            $this->database->delete(
                $this->database->usermeta,
                ['user_id' => $userId]
            );
            $this->database->delete(
                $this->database->users,
                ['ID' => $userId]
            );
        }

        parent::tearDown();
    }

    public function testConcurrentUnlinkedAndRoundCreationHaveOneWinner(): void
    {
        $author = $this->seedAuthorAndWork();
        $this->insertHistoricalRound($author, 'assessment-round');

        $unlinked = $this->race([
            ['create_rating_work', 'assessment-work', '4', $author],
            ['create_rating_work', 'assessment-work', '4.5', $author],
        ]);
        self::assertSame(['conflict', 'created'], $this->sortedStatuses($unlinked));
        self::assertSame(1, $this->ratingCount());

        $round = $this->race([
            ['create_rating_round', 'assessment-round', '3', $author],
            ['create_rating_round', 'assessment-round', '3.5', $author],
        ]);
        self::assertSame(['conflict', 'created'], $this->sortedStatuses($round));
        self::assertSame(2, $this->ratingCount());
    }

    public function testParallelDivergentAndEqualSourceUpdatesRespectCas(): void
    {
        $author = $this->seedAuthorAndWork();
        $this->insertRating('concurrent-rating', $author, 6);

        $divergent = $this->race([
            ['update_rating', 'concurrent-rating', '4', $author],
            ['update_rating', 'concurrent-rating', '4.5', $author],
        ]);
        self::assertSame(['stale', 'updated'], $this->sortedStatuses($divergent));
        self::assertSame(2, $this->storedVersion('ratings', 'rating', 'concurrent-rating'));

        $this->insertReview('concurrent-review', $author, 'Start');
        $equal = $this->race([
            ['update_review', 'concurrent-review', 'Gelijk', $author],
            ['update_review', 'concurrent-review', 'Gelijk', $author],
        ]);
        self::assertSame(['updated', 'updated'], $this->sortedStatuses($equal));
        self::assertSame(2, $this->storedVersion('reviews', 'review', 'concurrent-review'));
        self::assertSame(
            'Gelijk',
            $this->database->get_var(
                "SELECT review_content FROM `{$this->tableNames->reviews()}` "
                . "WHERE review_id='concurrent-review'"
            )
        );
    }

    public function testConcurrentCrossLibraryPublishHasOneCurrentTarget(): void
    {
        $author = $this->seedAuthorAndWork();
        $this->seedEligibleLibrary('assessment-library-a', $author);
        $this->seedEligibleLibrary('assessment-library-b', $author);
        $this->insertRating('publish-target-rating', $author, 8);

        $results = $this->race([
            ['publish_rating', 'publish-target-rating', 'assessment-library-a', $author],
            ['publish_rating', 'publish-target-rating', 'assessment-library-b', $author],
        ]);

        self::assertSame(['conflict', 'published'], $this->sortedStatuses($results));
        self::assertSame(1, $this->publicationCount());
    }

    public function testPublishVersusDeleteLeavesNoOrphan(): void
    {
        $author = $this->seedAuthorAndWork();
        $this->seedEligibleLibrary('assessment-library', $author);
        $this->insertRating('publish-delete-rating', $author, 8);

        $results = $this->race([
            ['publish_rating', 'publish-delete-rating', 'assessment-library', $author],
            ['delete_rating', 'publish-delete-rating', 'unused', $author],
        ]);

        self::assertContains(
            $this->sortedStatuses($results),
            [
                ['deleted', 'not_available'],
                ['deleted', 'published'],
            ]
        );
        self::assertSame(0, $this->ratingCount());
        self::assertSame(0, $this->publicationCount());
    }

    public function testWithdrawVersusModerateHasOneVersionedWinner(): void
    {
        $author = $this->seedAuthorAndWork();
        $owner = $this->createWordPressUser('assessment-owner');
        $this->seedEligibleLibrary('assessment-library', $author, $owner);
        $this->insertRating('withdraw-moderate-rating', $author, 8);
        $this->insertPublication(
            'withdraw-moderate-publication',
            'assessment-library',
            'withdraw-moderate-rating'
        );

        $results = $this->race([
            ['withdraw', 'withdraw-moderate-publication', 'unused', $author],
            ['moderate', 'withdraw-moderate-publication', 'assessment-library', $owner],
        ]);
        $statuses = $this->sortedStatuses($results);

        self::assertContains(
            $statuses,
            [
                ['stale', 'withdrawn'],
                ['hidden', 'stale'],
            ]
        );
        self::assertSame(
            2,
            $this->storedVersion(
                'contributionPublications',
                'publication',
                'withdraw-moderate-publication'
            )
        );

        $row = $this->database->get_row(
            "SELECT author_status,moderation_status FROM "
            . "`{$this->tableNames->contributionPublications()}` "
            . "WHERE publication_id='withdraw-moderate-publication'"
        );
        self::assertTrue(
            ($row->author_status === 'withdrawn' && $row->moderation_status === 'visible')
            || ($row->author_status === 'active' && $row->moderation_status === 'hidden')
        );
    }

    public function testEligibilityChangeSerializesBeforePublish(): void
    {
        $author = $this->seedAuthorAndWork();
        $this->seedEligibleLibrary('assessment-library', $author);
        $this->insertRating('eligibility-rating', $author, 8);
        $libraryTable = $this->tableNames->libraries();
        $membershipTable = $this->tableNames->memberships();

        self::assertNotFalse($this->database->query('START TRANSACTION'));

        try {
            self::assertSame(
                'assessment-library',
                $this->database->get_var(
                    "SELECT library_id FROM `{$libraryTable}` "
                    . "WHERE library_id='assessment-library' FOR UPDATE"
                )
            );
            self::assertSame(
                1,
                $this->database->update(
                    $membershipTable,
                    ['membership_status' => 'inactive'],
                    [
                        'library_id' => 'assessment-library',
                        'user_id' => (string) $author,
                    ]
                )
            );

            $worker = $this->startWorker(
                ['publish_rating', 'eligibility-rating', 'assessment-library', $author],
                sys_get_temp_dir() . '/assessment-eligibility-ready-' . bin2hex(random_bytes(8)),
                sys_get_temp_dir() . '/assessment-eligibility-release-' . bin2hex(random_bytes(8))
            );

            try {
                $this->awaitPath($worker['readyPath'], [$worker]);
                file_put_contents($worker['releasePath'], 'release');
                $this->awaitPath($worker['readyPath'] . '-started', [$worker]);
                usleep(50_000);
                self::assertNotFalse($this->database->query('COMMIT'));
                $result = $this->finishWorker($worker);
            } finally {
                $this->terminateWorker($worker);
                $this->removeWorkerPaths($worker);
            }
        } catch (\Throwable $exception) {
            $this->database->query('ROLLBACK');
            throw $exception;
        }

        self::assertSame('ineligible', $result['status']);
        self::assertSame(0, $this->publicationCount());
    }

    /**
     * @param list<array{0:string,1:string,2:string,3:int}> $operations
     * @return list<array<string,mixed>>
     */
    private function race(array $operations): array
    {
        $directory = sys_get_temp_dir() . '/biblio-assessment-race-'
            . bin2hex(random_bytes(8));

        if (!mkdir($directory, 0700)) {
            throw new RuntimeException('Could not create Assessment race directory.');
        }

        $releasePath = $directory . '/release';
        $workers = [];

        try {
            foreach ($operations as $index => $operation) {
                $workers[] = $this->startWorker(
                    $operation,
                    $directory . '/' . $index . '-ready',
                    $releasePath
                );
            }

            foreach ($workers as $worker) {
                $this->awaitPath($worker['readyPath'], $workers);
            }

            if (file_put_contents($releasePath, 'release') === false) {
                throw new RuntimeException('Could not release Assessment workers.');
            }

            return [
                $this->finishWorker($workers[0]),
                $this->finishWorker($workers[1]),
            ];
        } finally {
            foreach ($workers as $worker) {
                $this->terminateWorker($worker);
                $this->removeWorkerPaths($worker);
            }

            if (is_file($releasePath)) {
                unlink($releasePath);
            }
            rmdir($directory);
        }
    }

    /** @param array{0:string,1:string,2:string,3:int} $operation */
    private function startWorker(
        array $operation,
        string $readyPath,
        string $releasePath
    ): array {
        [$action, $target, $argument, $actor] = $operation;
        $pipes = [];
        $process = proc_open([
            PHP_BINARY,
            __DIR__ . '/Support/AssessmentMutationWorker.php',
            $action,
            $target,
            $argument,
            (string) $actor,
            $readyPath,
            $releasePath,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Could not start Assessment race worker.');
        }

        return [
            'process' => $process,
            'pipes' => $pipes,
            'readyPath' => $readyPath,
            'releasePath' => $releasePath,
        ];
    }

    /** @param list<array<string,mixed>> $workers */
    private function awaitPath(string $path, array $workers): void
    {
        $deadline = microtime(true) + 15;

        while (!is_file($path)) {
            foreach ($workers as $worker) {
                if (!proc_get_status($worker['process'])['running']) {
                    throw new RuntimeException(
                        'Assessment worker exited early: '
                        . stream_get_contents($worker['pipes'][2])
                    );
                }
            }

            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Assessment workers missed barrier.');
            }

            usleep(10_000);
        }
    }

    /** @return array<string,mixed> */
    private function finishWorker(array $worker): array
    {
        $output = stream_get_contents($worker['pipes'][1]);
        $error = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exitCode = proc_close($worker['process']);

        if ($exitCode !== 0) {
            throw new RuntimeException(
                "Assessment worker failed with {$exitCode}: {$error}"
            );
        }

        $encoded = trim($output);

        if ($encoded === '') {
            throw new RuntimeException(
                "Assessment worker returned no result. STDERR: {$error}"
            );
        }

        try {
            $result = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException(
                "Assessment worker returned invalid JSON: {$encoded}. STDERR: {$error}",
                previous: $exception
            );
        }

        if (($result['status'] ?? null) === 'worker_error') {
            throw new RuntimeException(
                'Assessment worker reported '
                . ($result['class'] ?? 'unknown error')
                . ': '
                . ($result['message'] ?? '')
            );
        }

        return $result;
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

    private function removeWorkerPaths(array $worker): void
    {
        foreach ([
            $worker['readyPath'],
            $worker['readyPath'] . '-started',
        ] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** @param list<array<string,mixed>> $results @return list<string> */
    private function sortedStatuses(array $results): array
    {
        $statuses = array_column($results, 'status');
        sort($statuses);
        return $statuses;
    }

    private function seedAuthorAndWork(): int
    {
        $author = $this->createWordPressUser('assessment-author');
        self::assertSame(
            1,
            $this->database->insert(
                $this->tableNames->works(),
                ['work_id' => 'assessment-work', 'work_title' => 'Assessment Race']
            )
        );
        return $author;
    }

    private function createWordPressUser(string $prefix): int
    {
        $suffix = bin2hex(random_bytes(4));
        $userId = wp_insert_user([
            'user_login' => $prefix . '-' . $suffix,
            'user_pass' => 'secret',
            'display_name' => $prefix,
        ]);
        self::assertIsInt($userId);
        $this->wordpressUsers[] = (int) $userId;
        return (int) $userId;
    }

    private function seedEligibleLibrary(
        string $libraryId,
        int $author,
        ?int $owner = null
    ): void {
        self::assertSame(
            1,
            $this->database->insert(
                $this->tableNames->libraries(),
                [
                    'library_id' => $libraryId,
                    'library_name' => 'Assessmentbibliotheek',
                    'library_type' => 'private_library',
                    'library_status' => 'active',
                ]
            )
        );
        self::assertSame(
            1,
            $this->database->insert(
                $this->tableNames->memberships(),
                [
                    'library_id' => $libraryId,
                    'user_id' => (string) $author,
                    'membership_status' => 'active',
                    'management_role' => 'member',
                    'use_access' => 'view_only',
                    'additional_permissions' => '[]',
                ]
            )
        );

        if ($owner !== null) {
            self::assertSame(
                1,
                $this->database->insert(
                    $this->tableNames->memberships(),
                    [
                        'library_id' => $libraryId,
                        'user_id' => (string) $owner,
                        'membership_status' => 'active',
                        'management_role' => 'owner',
                        'use_access' => 'direct',
                        'additional_permissions' => '[]',
                    ]
                )
            );
        }

        $editionId = $libraryId . '-edition';
        self::assertSame(
            1,
            $this->database->insert(
                $this->tableNames->editions(),
                ['edition_id' => $editionId, 'work_id' => 'assessment-work']
            )
        );
        self::assertSame(
            1,
            $this->database->insert(
                $this->tableNames->items(),
                [
                    'item_id' => $libraryId . '-item',
                    'library_id' => $libraryId,
                    'edition_id' => $editionId,
                    'item_status' => 'active',
                ]
            )
        );
    }

    private function insertRating(string $id, int $author, int $halfUnits): void
    {
        self::assertSame(
            1,
            $this->database->insert(
                $this->tableNames->ratings(),
                [
                    'rating_id' => $id,
                    'user_id' => (string) $author,
                    'work_id' => 'assessment-work',
                    'reading_round_id' => null,
                    'rating_half_units' => $halfUnits,
                    'created_at' => '2026-08-23 10:00:00.000000',
                    'updated_at' => '2026-08-23 10:00:00.000000',
                    'rating_version' => 1,
                ]
            ),
            $this->database->last_error
        );
    }

    private function insertReview(string $id, int $author, string $content): void
    {
        self::assertSame(
            1,
            $this->database->insert(
                $this->tableNames->reviews(),
                [
                    'review_id' => $id,
                    'user_id' => (string) $author,
                    'work_id' => 'assessment-work',
                    'reading_round_id' => null,
                    'review_content' => $content,
                    'created_at' => '2026-08-23 10:00:00.000000',
                    'updated_at' => '2026-08-23 10:00:00.000000',
                    'review_version' => 1,
                ]
            ),
            $this->database->last_error
        );
    }

    private function insertPublication(
        string $id,
        string $libraryId,
        string $ratingId
    ): void {
        self::assertSame(
            1,
            $this->database->insert(
                $this->tableNames->contributionPublications(),
                [
                    'publication_id' => $id,
                    'library_id' => $libraryId,
                    'rating_id' => $ratingId,
                    'review_id' => null,
                    'author_status' => 'active',
                    'moderation_status' => 'visible',
                    'moderation_reason' => null,
                    'moderator_user_id' => null,
                    'moderated_at' => null,
                    'published_at' => '2026-08-23 10:00:00.000000',
                    'updated_at' => '2026-08-23 10:00:00.000000',
                    'publication_version' => 1,
                ]
            ),
            $this->database->last_error
        );
    }

    private function insertHistoricalRound(int $author, string $id): void
    {
        self::assertSame(
            1,
            $this->database->insert(
                $this->tableNames->readingRounds(),
                [
                    'reading_round_id' => $id,
                    'user_id' => (string) $author,
                    'work_id' => 'assessment-work',
                    'item_id' => null,
                    'external_loan_id' => null,
                    'started_at' => null,
                    'round_outcome' => 'completed',
                    'provenance' => 'historical_manual',
                    'reading_started_year' => 2026,
                    'reading_started_month' => 8,
                    'reading_started_day' => 1,
                    'reading_finished_year' => 2026,
                    'reading_finished_month' => 8,
                    'reading_finished_day' => 2,
                    'created_at' => '2026-08-23 10:00:00.000000',
                    'updated_at' => '2026-08-23 10:00:00.000000',
                    'ended_at' => '2026-08-23 10:00:00.000000',
                    'round_version' => 1,
                ]
            )
        );
    }

    private function ratingCount(): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->ratings()}`"
        );
    }

    private function publicationCount(): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->contributionPublications()}`"
        );
    }

    private function storedVersion(string $tableMethod, string $prefix, string $id): int
    {
        $table = $this->tableNames->{$tableMethod}();
        return (int) $this->database->get_var($this->database->prepare(
            "SELECT {$prefix}_version FROM `{$table}` WHERE {$prefix}_id=%s",
            $id
        ));
    }
}
