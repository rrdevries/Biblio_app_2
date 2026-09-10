<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Infrastructure\WordPress\Rest\RestApi;
use RuntimeException;
use JsonException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class WishlistRestApiTest extends PersistenceIntegrationTestCase
{
    private int $actorId;
    private int $otherId;
    private WP_REST_Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actorId = $this->createUser("actor");
        $this->otherId = $this->createUser("other");
        $this->server = new WP_REST_Server();

        global $wp_rest_server;
        $wp_rest_server = $this->server;

        $api = new RestApi(static fn () =>
            (new ProductionComposition($GLOBALS["wpdb"]))->application());
        $previousHook = $GLOBALS["wp_filter"]["rest_api_init"] ?? null;
        unset($GLOBALS["wp_filter"]["rest_api_init"]);

        try {
            $api->boot();
            do_action("rest_api_init");
        } finally {
            unset($GLOBALS["wp_filter"]["rest_api_init"]);
            if ($previousHook !== null) {
                $GLOBALS["wp_filter"]["rest_api_init"] = $previousHook;
            }
        }
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);
        unset($_SERVER["HTTP_X_WP_NONCE"], $GLOBALS["wp_rest_auth_cookie"]);
        require_once ABSPATH . "wp-admin/includes/user.php";
        wp_delete_user($this->actorId);
        wp_delete_user($this->otherId);
        unset($GLOBALS["wp_rest_server"]);
        parent::tearDown();
    }

    public function testListIsAuthenticatedOwnerScopedOrderedAndAllowlisted(): void
    {
        $anonymous = $this->server->dispatch($this->request(
            "GET",
            "/biblio/v1/me/wishlist"
        ));
        self::assertSame(401, $anonymous->get_status());

        $empty = $this->data($this->dispatchAs($this->actorId, $this->request(
            "GET",
            "/biblio/v1/me/wishlist"
        )));
        self::assertSame(["entries"], array_keys($empty));
        self::assertSame([], $empty["entries"]);

        $this->seedWork("wish-work-a", "Eerste titel");
        $this->seedWork("wish-work-b", "Tweede titel");
        $this->seedAuthor("wish-author-a", "Auteur A", "wish-work-a", 1);
        $first = $this->addWork($this->actorId, "wish-work-a");
        usleep(1_000);
        $second = $this->addWork($this->actorId, "wish-work-b");

        $list = $this->data($this->dispatchAs($this->actorId, $this->request(
            "GET",
            "/biblio/v1/me/wishlist"
        )));
        self::assertSame([
            $second["wishlist_entry_id"],
            $first["wishlist_entry_id"],
        ], array_column($list["entries"], "wishlist_entry_id"));
        self::assertSame([
            "wishlist_entry_id",
            "target_type",
            "work_id",
            "edition_id",
            "display_title",
            "authors",
            "created_at",
            "updated_at",
        ], array_keys($list["entries"][1]));
        self::assertSame("work_only", $list["entries"][1]["target_type"]);
        self::assertNull($list["entries"][1]["edition_id"]);
        self::assertSame("Eerste titel", $list["entries"][1]["display_title"]);
        self::assertSame([[
            "author_id" => "wish-author-a",
            "display_name" => "Auteur A",
        ]], $list["entries"][1]["authors"]);
        self::assertArrayNotHasKey("user_id", $list["entries"][1]);
        self::assertArrayNotHasKey("library_id", $list["entries"][1]);

        $this->seedLibraryOwner("wish-foreign-library", $this->otherId);
        $foreign = $this->data($this->dispatchAs($this->otherId, $this->request(
            "GET",
            "/biblio/v1/me/wishlist"
        )));
        self::assertSame([], $foreign["entries"]);
    }

    public function testAddsAreStrictIdempotentAndKeepEditionIntentSeparate(): void
    {
        $this->seedWork("wish-work-add", "Toevoegen");
        $this->seedEdition("wish-edition-a", "wish-work-add");
        $this->seedEdition("wish-edition-b", "wish-work-add");

        $created = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/wishlist",
            ["target" => ["type" => "work_only", "work_id" => "wish-work-add"]]
        ));
        self::assertSame(201, $created->get_status());
        $entry = $this->data($created);

        $duplicate = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/wishlist",
            ["target" => ["type" => "work_only", "work_id" => "wish-work-add"]]
        ));
        self::assertSame(200, $duplicate->get_status());
        self::assertSame($entry, $this->data($duplicate));
        self::assertSame(1, $this->activeCount());

        $reverse = $this->dispatchAs($this->otherId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/wishlist",
            [
                "target" => [
                    "type" => "edition_specific",
                    "work_id" => "wish-work-add",
                    "edition_id" => "wish-edition-a",
                ],
            ]
        ));
        self::assertSame(201, $reverse->get_status());
        $secondEdition = $this->dispatchAs($this->otherId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/wishlist",
            [
                "target" => [
                    "type" => "edition_specific",
                    "work_id" => "wish-work-add",
                    "edition_id" => "wish-edition-b",
                ],
            ]
        ));
        self::assertSame(201, $secondEdition->get_status());
        $duplicateEdition = $this->dispatchAs($this->otherId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/wishlist",
            [
                "target" => [
                    "type" => "edition_specific",
                    "work_id" => "wish-work-add",
                    "edition_id" => "wish-edition-a",
                ],
            ]
        ));
        self::assertSame(200, $duplicateEdition->get_status());

        $workConflict = $this->dispatchAs($this->otherId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/wishlist",
            ["target" => ["type" => "work_only", "work_id" => "wish-work-add"]]
        ));
        self::assertSame(409, $workConflict->get_status());
        self::assertSame(
            "biblio_wishlist_intent_conflict",
            $workConflict->get_data()["code"]
        );

        $missingWork = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/wishlist",
            ["target" => ["type" => "work_only", "work_id" => "wish-missing"]]
        ));
        self::assertSame(404, $missingWork->get_status());

        $this->seedWork("wish-work-mismatch", "Mismatch");
        $mismatch = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/wishlist",
            ["target" => [
                "type" => "edition_specific",
                "work_id" => "wish-work-mismatch",
                "edition_id" => "wish-edition-a",
            ]]
        ));
        self::assertSame(404, $mismatch->get_status());
    }

    public function testRefinementIsEntryAddressedStrictAndNonEnumerating(): void
    {
        $this->seedWork("wish-work-refine", "Verfijnen");
        $this->seedWork("wish-work-wrong", "Verkeerd");
        $this->seedEdition("wish-edition-refine", "wish-work-refine");
        $this->seedEdition("wish-edition-wrong", "wish-work-wrong");
        $created = $this->addWork($this->actorId, "wish-work-refine");
        $entryId = $created["wishlist_entry_id"];

        $refinedResponse = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "PATCH",
            "/biblio/v1/me/wishlist/{$entryId}",
            ["target" => [
                "type" => "edition_specific",
                "edition_id" => "wish-edition-refine",
            ]]
        ));
        self::assertSame(200, $refinedResponse->get_status());
        $refined = $this->data($refinedResponse);
        self::assertSame($entryId, $refined["wishlist_entry_id"]);
        self::assertSame($created["created_at"], $refined["created_at"]);
        self::assertSame("edition_specific", $refined["target_type"]);
        self::assertSame("wish-edition-refine", $refined["edition_id"]);

        $reused = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "PATCH",
            "/biblio/v1/me/wishlist/{$entryId}",
            ["target" => [
                "type" => "edition_specific",
                "edition_id" => "wish-edition-refine",
            ]]
        ));
        self::assertSame(200, $reused->get_status());
        self::assertSame($refined, $this->data($reused));

        $wrongEdition = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "PATCH",
            "/biblio/v1/me/wishlist/{$entryId}",
            ["target" => [
                "type" => "edition_specific",
                "edition_id" => "wish-edition-wrong",
            ]]
        ));
        self::assertSame(404, $wrongEdition->get_status());

        $foreign = $this->dispatchAs($this->otherId, $this->jsonRequest(
            "PATCH",
            "/biblio/v1/me/wishlist/{$entryId}",
            ["target" => [
                "type" => "edition_specific",
                "edition_id" => "wish-edition-refine",
            ]]
        ));
        $unknown = $this->dispatchAs($this->otherId, $this->jsonRequest(
            "PATCH",
            "/biblio/v1/me/wishlist/wish-unknown",
            ["target" => [
                "type" => "edition_specific",
                "edition_id" => "wish-edition-refine",
            ]]
        ));
        self::assertSame(404, $foreign->get_status());
        self::assertSame($unknown->get_data(), $foreign->get_data());
    }

    public function testMalformedTargetsFailClosed(): void
    {
        $this->seedWork("wish-work-valid", "Geldig");
        $this->seedEdition("wish-edition-valid", "wish-work-valid");
        $invalidBodies = [
            [],
            ["target" => null],
            ["target" => ["work_id" => "wish-work-valid"]],
            ["target" => ["type" => "unknown", "work_id" => "wish-work-valid"]],
            ["target" => ["type" => "work_only", "work_id" => 12]],
            ["target" => [
                "type" => "work_only",
                "work_id" => "wish-work-valid",
                "edition_id" => "wish-edition-valid",
            ]],
            ["target" => [
                "type" => "edition_specific",
                "work_id" => "wish-work-valid",
                "edition_id" => "wish-edition-valid",
                "extra" => true,
            ]],
        ];
        foreach ($invalidBodies as $body) {
            self::assertSame(400, $this->dispatchAs(
                $this->actorId,
                $this->jsonRequest("POST", "/biblio/v1/me/wishlist", $body)
            )->get_status());
        }

        $query = $this->request("GET", "/biblio/v1/me/wishlist");
        $query->set_query_params(["user_id" => $this->otherId]);
        self::assertSame(400, $this->dispatchAs(
            $this->actorId,
            $query
        )->get_status());
    }

    public function testRemoveUsesRemovedHistoryAndHasNoAdjacentSideEffects(): void
    {
        $this->seedWork("wish-work-remove", "Verwijderen");
        $created = $this->addWork($this->actorId, "wish-work-remove");
        $entryId = $created["wishlist_entry_id"];

        $foreign = $this->dispatchAs($this->otherId, $this->request(
            "DELETE",
            "/biblio/v1/me/wishlist/{$entryId}"
        ));
        $unknown = $this->dispatchAs($this->otherId, $this->request(
            "DELETE",
            "/biblio/v1/me/wishlist/wish-unknown"
        ));
        self::assertSame(404, $foreign->get_status());
        self::assertSame($unknown->get_data(), $foreign->get_data());

        $removed = $this->dispatchAs($this->actorId, $this->request(
            "DELETE",
            "/biblio/v1/me/wishlist/{$entryId}"
        ));
        self::assertSame(204, $removed->get_status());
        self::assertNull($removed->get_data());
        self::assertSame("removed", (string) $this->database->get_var(
            $this->database->prepare(
                "SELECT removal_reason FROM `{$this->tableNames->wishlistEntryHistory()}` WHERE wishlist_entry_id=%s",
                $entryId
            )
        ));

        $again = $this->dispatchAs($this->actorId, $this->request(
            "DELETE",
            "/biblio/v1/me/wishlist/{$entryId}"
        ));
        self::assertSame(404, $again->get_status());
        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->nextReadingEntries()}`"
        ));
        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->collectionMemberships()}`"
        ));
        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->migrationSourceObservations()}`"
        ));
        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->contributionPublications()}`"
        ));
    }

    public function testConcurrentRestMutationsPreserveWishlistInvariants(): void
    {
        $this->seedWork("wish-rest-race-work", "Race Work");
        $workResults = $this->race([
            ["add_work", "wish-rest-race-work", "-", "-"],
            ["add_work", "wish-rest-race-work", "-", "-"],
        ]);
        self::assertSame([200, 201], $this->responseStatuses($workResults));
        self::assertSame(
            $workResults[0]["entry_id"],
            $workResults[1]["entry_id"]
        );

        $this->seedWork("wish-rest-race-edition-work", "Edition Race");
        $this->seedEdition(
            "wish-rest-race-edition",
            "wish-rest-race-edition-work"
        );
        $editionResults = $this->race([
            [
                "add_edition",
                "wish-rest-race-edition-work",
                "wish-rest-race-edition",
                "-",
            ],
            [
                "add_edition",
                "wish-rest-race-edition-work",
                "wish-rest-race-edition",
                "-",
            ],
        ]);
        self::assertSame([200, 201], $this->responseStatuses($editionResults));
        self::assertSame(
            $editionResults[0]["entry_id"],
            $editionResults[1]["entry_id"]
        );

        $this->seedWork("wish-rest-race-refine-work", "Refine Race");
        $this->seedEdition(
            "wish-rest-race-refine-edition",
            "wish-rest-race-refine-work"
        );
        $entry = $this->addWork($this->actorId, "wish-rest-race-refine-work");
        $mixedResults = $this->race([
            [
                "refine",
                "wish-rest-race-refine-work",
                "wish-rest-race-refine-edition",
                $entry["wishlist_entry_id"],
            ],
            ["add_work", "wish-rest-race-refine-work", "-", "-"],
        ]);
        $statuses = $this->responseStatuses($mixedResults);
        self::assertTrue(
            $statuses === [200, 200] || $statuses === [200, 409]
        );
        self::assertSame(3, $this->activeCount());
        self::assertSame(0, (int) $this->database->get_var(
            $this->database->prepare(
                "SELECT COUNT(*) FROM `{$this->tableNames->wishlistEntries()}` WHERE work_id=%s AND target_type='work_only'",
                "wish-rest-race-refine-work"
            )
        ));
        self::assertSame(1, (int) $this->database->get_var(
            $this->database->prepare(
                "SELECT COUNT(*) FROM `{$this->tableNames->wishlistEntries()}` WHERE work_id=%s AND edition_id=%s",
                "wish-rest-race-refine-work",
                "wish-rest-race-refine-edition"
            )
        ));
    }

    /** @param list<array{0:string,1:string,2:string,3:string}> $operations
     * @return list<array<string,mixed>>
     */
    private function race(array $operations): array
    {
        $directory = sys_get_temp_dir() . "/biblio-wishlist-rest-race-"
            . bin2hex(random_bytes(8));
        if (!mkdir($directory, 0700)) {
            throw new RuntimeException("Could not create Wishlist REST race directory.");
        }
        $release = $directory . "/release";
        $ready = [$directory . "/a-ready", $directory . "/b-ready"];
        $workers = [];

        try {
            foreach ($operations as $index => [$action, $work, $edition, $entry]) {
                $pipes = [];
                $process = proc_open([
                    PHP_BINARY,
                    __DIR__ . "/Support/WishlistRestMutationWorker.php",
                    $action,
                    (string) $this->actorId,
                    $work,
                    $edition,
                    $entry,
                    $ready[$index],
                    $release,
                ], [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
                if (!is_resource($process)) {
                    throw new RuntimeException("Could not start Wishlist REST worker.");
                }
                $workers[] = ["process" => $process, "pipes" => $pipes];
            }

            $deadline = microtime(true) + 15;
            while (!is_file($ready[0]) || !is_file($ready[1])) {
                foreach ($workers as $worker) {
                    if (!(proc_get_status($worker["process"])["running"])) {
                        throw new RuntimeException(
                            "Wishlist REST worker exited early: "
                            . stream_get_contents($worker["pipes"][2])
                        );
                    }
                }
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException("Wishlist REST workers missed barrier.");
                }
                usleep(10_000);
            }
            if (file_put_contents($release, "release") === false) {
                throw new RuntimeException("Could not release Wishlist REST workers.");
            }

            return [$this->finishWorker($workers[0]), $this->finishWorker($workers[1])];
        } finally {
            foreach ($workers as $worker) {
                if (
                    is_resource($worker["process"])
                    && proc_get_status($worker["process"])["running"]
                ) {
                    proc_terminate($worker["process"]);
                    proc_close($worker["process"]);
                }
            }
            foreach ([...$ready, $release] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /** @param array{process:resource,pipes:array<int,resource>} $worker
     * @return array<string,mixed>
     */
    private function finishWorker(array $worker): array
    {
        $output = stream_get_contents($worker["pipes"][1]);
        $error = stream_get_contents($worker["pipes"][2]);
        fclose($worker["pipes"][1]);
        fclose($worker["pipes"][2]);
        $exit = proc_close($worker["process"]);
        if ($exit !== 0) {
            throw new RuntimeException("Wishlist REST worker failed: {$error}");
        }
        try {
            return json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                "Wishlist REST worker returned invalid JSON: {$output}; {$error}",
                0,
                $exception
            );
        }
    }

    /** @param list<array<string,mixed>> $results @return list<int> */
    private function responseStatuses(array $results): array
    {
        $statuses = array_map(
            static fn (array $result): int => (int) $result["status"],
            $results
        );
        sort($statuses);
        return $statuses;
    }

    /** @return array<string,mixed> */
    private function addWork(int $userId, string $workId): array
    {
        return $this->data($this->dispatchAs($userId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/wishlist",
            ["target" => ["type" => "work_only", "work_id" => $workId]]
        )));
    }

    private function request(string $method, string $path): WP_REST_Request
    {
        return new WP_REST_Request($method, $path);
    }

    /** @param array<string,mixed> $body */
    private function jsonRequest(
        string $method,
        string $path,
        array $body
    ): WP_REST_Request {
        $request = $this->request($method, $path);
        $request->set_header("content-type", "application/json");
        $request->set_body((string) wp_json_encode($body));
        return $request;
    }

    private function dispatchAs(
        int $userId,
        WP_REST_Request $request
    ): WP_REST_Response {
        wp_set_current_user($userId);
        $GLOBALS["wp_rest_auth_cookie"] = true;
        $_SERVER["HTTP_X_WP_NONCE"] = wp_create_nonce("wp_rest");
        $authentication = rest_cookie_check_errors(null);
        if (is_wp_error($authentication)) {
            return rest_convert_error_to_response($authentication);
        }
        return $this->server->dispatch($request);
    }

    /** @return array<string,mixed> */
    private function data(WP_REST_Response $response): array
    {
        self::assertContains($response->get_status(), [200, 201]);
        $payload = $response->get_data();
        self::assertIsArray($payload);
        self::assertIsArray($payload["data"] ?? null);
        return $payload["data"];
    }

    private function createUser(string $role): int
    {
        $suffix = bin2hex(random_bytes(6));
        $id = wp_create_user(
            "biblio-wishlist-{$role}-{$suffix}",
            bin2hex(random_bytes(12)),
            "wishlist-{$role}-{$suffix}@example.test"
        );
        if ($id instanceof WP_Error) {
            throw new RuntimeException($id->get_error_message());
        }
        return $id;
    }

    private function seedWork(string $workId, string $title): void
    {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->works(),
            [
                "work_id" => $workId,
                "work_title" => $title,
                "work_title_status" => "provisional",
            ]
        ), $this->database->last_error);
    }

    private function seedLibraryOwner(string $libraryId, int $userId): void
    {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->libraries(),
            [
                "library_id" => $libraryId,
                "library_name" => "Foreign owner library",
                "library_type" => "private_library",
                "library_status" => "active",
            ]
        ), $this->database->last_error);
        self::assertSame(1, $this->database->insert(
            $this->tableNames->memberships(),
            [
                "library_id" => $libraryId,
                "user_id" => (string) $userId,
                "membership_status" => "active",
                "management_role" => "owner",
                "use_access" => "direct",
                "additional_permissions" => "[]",
            ]
        ), $this->database->last_error);
    }

    private function seedEdition(string $editionId, string $workId): void
    {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->editions(),
            [
                "edition_id" => $editionId,
                "work_id" => $workId,
                "edition_title" => $editionId,
                "explicitly_no_isbn" => 0,
            ]
        ), $this->database->last_error);
    }

    private function seedAuthor(
        string $authorId,
        string $displayName,
        string $workId,
        int $position
    ): void {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->authors(),
            ["author_id" => $authorId, "display_name" => $displayName]
        ), $this->database->last_error);
        self::assertSame(1, $this->database->insert(
            $this->tableNames->workContributors(),
            [
                "work_id" => $workId,
                "author_id" => $authorId,
                "contributor_role" => "author",
                "contributor_position" => $position,
            ]
        ), $this->database->last_error);
    }

    private function activeCount(): int
    {
        return (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->wishlistEntries()}`"
        );
    }
}
