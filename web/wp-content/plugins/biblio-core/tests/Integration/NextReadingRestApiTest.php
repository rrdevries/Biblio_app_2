<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Infrastructure\WordPress\Rest\RestApi;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class NextReadingRestApiTest extends PersistenceIntegrationTestCase
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

    public function testListIsAuthenticatedEmptyOwnerScopedAndAllowlisted(): void
    {
        $anonymous = $this->server->dispatch($this->request(
            "GET",
            "/biblio/v1/me/next-reading"
        ));
        self::assertSame(401, $anonymous->get_status());

        $empty = $this->data($this->dispatchAs($this->actorId, $this->request(
            "GET",
            "/biblio/v1/me/next-reading"
        )));
        self::assertSame(["list_version", "entries"], array_keys($empty));
        self::assertSame(1, $empty["list_version"]);
        self::assertSame([], $empty["entries"]);

        $this->seedWork("work-private", "Privé werk");
        $added = $this->add($this->actorId, "work-private", null);
        self::assertCount(1, $added["entries"]);

        $foreign = $this->data($this->dispatchAs($this->otherId, $this->request(
            "GET",
            "/biblio/v1/me/next-reading"
        )));
        self::assertSame([], $foreign["entries"]);

        $entry = $added["entries"][0];
        self::assertSame(
            ["entry_id", "position", "work", "preferred_source"],
            array_keys($entry)
        );
        self::assertSame(
            ["work_id", "title"],
            array_keys($entry["work"])
        );
        self::assertArrayNotHasKey("user_id", $entry);
        self::assertArrayNotHasKey("created_at", $entry);
    }

    public function testAddAllowsWorkItemLoanAndFullyIdenticalDuplicates(): void
    {
        $this->seedLibrary("library-own", "Eigen kast", $this->actorId);
        $this->seedWork("work-add", "Boek voor toevoegen");
        $this->seedItem("item-add", "library-own", "work-add");
        $this->seedLoan("loan-add", $this->actorId, "work-add");

        $workOnly = $this->add($this->actorId, "work-add", null);
        $item = $this->add($this->actorId, "work-add", [
            "type" => "library_item",
            "library_id" => "library-own",
            "item_id" => "item-add",
        ]);
        $loan = $this->add($this->actorId, "work-add", [
            "type" => "external_loan",
            "external_loan_id" => "loan-add",
        ]);
        $duplicate = $this->add($this->actorId, "work-add", [
            "type" => "external_loan",
            "external_loan_id" => "loan-add",
        ]);

        self::assertSame(2, $workOnly["list_version"]);
        self::assertSame(3, $item["list_version"]);
        self::assertSame(4, $loan["list_version"]);
        self::assertSame(5, $duplicate["list_version"]);
        self::assertCount(4, $duplicate["entries"]);
        self::assertCount(4, array_unique(array_column(
            $duplicate["entries"],
            "entry_id"
        )));
        self::assertSame("none", $duplicate["entries"][0]["preferred_source"]["state"]);
        self::assertSame("library_item", $duplicate["entries"][1]["preferred_source"]["type"]);
        self::assertSame("external_loan", $duplicate["entries"][2]["preferred_source"]["type"]);
        self::assertSame(
            $duplicate["entries"][2]["preferred_source"],
            $duplicate["entries"][3]["preferred_source"]
        );

        $unknownFields = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/next-reading",
            [
                "work_id" => "work-add",
                "preferred_source" => null,
                "expected_version" => 1,
            ]
        ));
        self::assertSame(400, $unknownFields->get_status());

        $this->seedWork("work-other", "Ander werk");
        $mismatch = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/next-reading",
            [
                "work_id" => "work-other",
                "preferred_source" => [
                    "type" => "library_item",
                    "library_id" => "library-own",
                    "item_id" => "item-add",
                ],
            ]
        ));
        self::assertSame(404, $mismatch->get_status());

        $foreignLibrary = "library-foreign";
        $this->seedLibrary($foreignLibrary, "Verborgen kast", $this->otherId);
        $this->seedItem("item-foreign", $foreignLibrary, "work-add");
        $foreign = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/next-reading",
            [
                "work_id" => "work-add",
                "preferred_source" => [
                    "type" => "library_item",
                    "library_id" => $foreignLibrary,
                    "item_id" => "item-foreign",
                ],
            ]
        ));
        self::assertSame(404, $foreign->get_status());

        $missing = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/next-reading",
            ["work_id" => "work-missing", "preferred_source" => null]
        ));
        self::assertSame(404, $missing->get_status());
    }

    public function testUnavailableProjectionLeaksNoSourceMetadata(): void
    {
        $this->seedLibrary("library-source-loss", "Bronverlies", $this->actorId);
        $this->seedWork("work-source-loss", "Bron verdwijnt");
        $this->seedItem("item-source-loss", "library-source-loss", "work-source-loss");
        $added = $this->add($this->actorId, "work-source-loss", [
            "type" => "library_item",
            "library_id" => "library-source-loss",
            "item_id" => "item-source-loss",
        ]);
        self::assertSame("available", $added["entries"][0]["preferred_source"]["state"]);

        self::assertSame(1, $this->database->delete(
            $this->tableNames->items(),
            ["item_id" => "item-source-loss"],
            ["%s"]
        ));

        $list = $this->data($this->dispatchAs($this->actorId, $this->request(
            "GET",
            "/biblio/v1/me/next-reading"
        )));
        $source = $list["entries"][0]["preferred_source"];
        self::assertSame([
            "state" => "unavailable",
            "label" => "Voorkeursbron niet beschikbaar",
        ], $source);
        self::assertStringNotContainsString(
            "item-source-loss",
            (string) wp_json_encode($list)
        );
        self::assertSame(2, $list["list_version"]);
    }

    public function testRemoveUndoExpiryPrivacyAndStaleAreServerAuthoritative(): void
    {
        $this->seedWork("work-remove", "Verwijderbaar");
        $first = $this->add($this->actorId, "work-remove", null);
        $second = $this->add($this->actorId, "work-remove", null);
        $firstId = $first["entries"][0]["entry_id"];

        $stale = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "DELETE",
            "/biblio/v1/me/next-reading/{$firstId}",
            ["expected_version" => 2]
        ));
        self::assertSame(409, $stale->get_status());
        self::assertSame("biblio_next_reading_list_stale", $stale->get_data()["code"]);

        $removedResponse = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "DELETE",
            "/biblio/v1/me/next-reading/{$firstId}",
            ["expected_version" => $second["list_version"]]
        ));
        self::assertSame(200, $removedResponse->get_status());
        $removed = $this->data($removedResponse);
        self::assertSame(["list", "undo"], array_keys($removed));
        self::assertSame(4, $removed["list"]["list_version"]);
        self::assertCount(1, $removed["list"]["entries"]);
        self::assertSame(["token", "expires_at"], array_keys($removed["undo"]));
        self::assertNotSame("", $removed["undo"]["token"]);
        self::assertStringNotContainsString(
            hash("sha256", $removed["undo"]["token"]),
            (string) wp_json_encode($removed)
        );

        $foreign = $this->dispatchAs($this->otherId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/next-reading/undo",
            ["undo_token" => $removed["undo"]["token"]]
        ));
        self::assertSame(409, $foreign->get_status());
        self::assertSame("biblio_next_reading_undo_unavailable", $foreign->get_data()["code"]);

        $restored = $this->data($this->dispatchAs($this->actorId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/next-reading/undo",
            ["undo_token" => $removed["undo"]["token"]]
        )));
        self::assertSame(5, $restored["list_version"]);
        self::assertSame($firstId, $restored["entries"][0]["entry_id"]);

        $used = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/next-reading/undo",
            ["undo_token" => $removed["undo"]["token"]]
        ));
        self::assertSame(409, $used->get_status());

        $malformed = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/next-reading/undo",
            ["undo_token" => ""]
        ));
        self::assertSame(400, $malformed->get_status());

        $removeAgain = $this->data($this->dispatchAs(
            $this->actorId,
            $this->jsonRequest(
                "DELETE",
                "/biblio/v1/me/next-reading/{$firstId}",
                ["expected_version" => 5]
            )
        ));
        $this->database->query(
            "UPDATE `{$this->tableNames->nextReadingUndo()}` "
            . "SET created_at = '1999-01-01 00:00:00.000000', "
            . "expires_at = '2000-01-01 00:00:00.000000'"
        );
        $expired = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/next-reading/undo",
            ["undo_token" => $removeAgain["undo"]["token"]]
        ));
        self::assertSame(409, $expired->get_status());

        $unknown = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "DELETE",
            "/biblio/v1/me/next-reading/entry-unknown",
            ["expected_version" => 6]
        ));
        $foreignEntry = $this->dispatchAs($this->otherId, $this->jsonRequest(
            "DELETE",
            "/biblio/v1/me/next-reading/{$second["entries"][1]["entry_id"]}",
            ["expected_version" => 1]
        ));
        self::assertSame(404, $unknown->get_status());
        self::assertSame($unknown->get_data(), $foreignEntry->get_data());
    }

    public function testReorderAndPreferredSourceMutationsUseVersions(): void
    {
        $this->seedLibrary("library-pref", "Voorkeur", $this->actorId);
        $this->seedWork("work-pref", "Voorkeurswerk");
        $this->seedWork("work-wrong", "Verkeerd werk");
        $this->seedItem("item-pref", "library-pref", "work-pref");
        $this->seedItem("item-wrong", "library-pref", "work-wrong");
        $this->seedLoan("loan-pref", $this->actorId, "work-pref");
        $list = $this->add($this->actorId, "work-pref", null);
        $list = $this->add($this->actorId, "work-pref", null);
        $ids = array_column($list["entries"], "entry_id");

        $reordered = $this->data($this->dispatchAs($this->actorId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/next-reading/reorder",
            [
                "ordered_entry_ids" => [$ids[1], $ids[0]],
                "expected_version" => $list["list_version"],
            ]
        )));
        self::assertSame([$ids[1], $ids[0]], array_column($reordered["entries"], "entry_id"));
        self::assertSame(4, $reordered["list_version"]);

        $badOrder = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/next-reading/reorder",
            ["ordered_entry_ids" => [$ids[1], $ids[1]], "expected_version" => 4]
        ));
        self::assertSame(422, $badOrder->get_status());

        $staleOrder = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/next-reading/reorder",
            ["ordered_entry_ids" => [$ids[0], $ids[1]], "expected_version" => 3]
        ));
        self::assertSame(409, $staleOrder->get_status());

        $setItem = $this->preference($ids[1], 4, [
            "type" => "library_item",
            "library_id" => "library-pref",
            "item_id" => "item-pref",
        ]);
        self::assertSame(5, $setItem["list_version"]);
        self::assertSame("library_item", $setItem["entries"][0]["preferred_source"]["type"]);

        $setLoan = $this->preference($ids[1], 5, [
            "type" => "external_loan",
            "external_loan_id" => "loan-pref",
        ]);
        self::assertSame(6, $setLoan["list_version"]);
        self::assertSame("external_loan", $setLoan["entries"][0]["preferred_source"]["type"]);

        $cleared = $this->data($this->dispatchAs($this->actorId, $this->jsonRequest(
            "DELETE",
            "/biblio/v1/me/next-reading/{$ids[1]}/preferred-source",
            ["expected_version" => 6]
        )));
        self::assertSame(7, $cleared["list_version"]);
        self::assertSame("none", $cleared["entries"][0]["preferred_source"]["state"]);

        $mismatch = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "PATCH",
            "/biblio/v1/me/next-reading/{$ids[1]}/preferred-source",
            [
                "preferred_source" => [
                    "type" => "library_item",
                    "library_id" => "library-pref",
                    "item_id" => "item-wrong",
                ],
                "expected_version" => 7,
            ]
        ));
        self::assertSame(404, $mismatch->get_status());

        $stale = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "PATCH",
            "/biblio/v1/me/next-reading/{$ids[1]}/preferred-source",
            [
                "preferred_source" => [
                    "type" => "external_loan",
                    "external_loan_id" => "loan-pref",
                ],
                "expected_version" => 6,
            ]
        ));
        self::assertSame(409, $stale->get_status());

        $foreign = $this->dispatchAs($this->otherId, $this->jsonRequest(
            "PATCH",
            "/biblio/v1/me/next-reading/{$ids[1]}/preferred-source",
            [
                "preferred_source" => [
                    "type" => "external_loan",
                    "external_loan_id" => "loan-pref",
                ],
                "expected_version" => 1,
            ]
        ));
        self::assertSame(404, $foreign->get_status());
    }

    public function testWorkAndSourceDiscoveryAreBoundedStableAndPrivate(): void
    {
        foreach (["01", "02", "03", "04"] as $suffix) {
            $this->seedWork("work-alpha-{$suffix}", "Alpha {$suffix}");
        }
        $this->seedWork("work-beta", "Beta");

        $first = $this->data($this->dispatchAs($this->actorId, $this->queryRequest(
            "/biblio/v1/me/works",
            ["q" => "Alpha", "limit" => 2]
        )));
        self::assertSame(["items", "next_cursor"], array_keys($first));
        self::assertCount(2, $first["items"]);
        self::assertSame(["Alpha 01", "Alpha 02"], array_column($first["items"], "title"));
        self::assertNotNull($first["next_cursor"]);
        self::assertSame(["work_id", "title"], array_keys($first["items"][0]));

        $second = $this->data($this->dispatchAs($this->actorId, $this->queryRequest(
            "/biblio/v1/me/works",
            [
                "q" => "Alpha",
                "limit" => 2,
                "cursor" => $first["next_cursor"],
            ]
        )));
        self::assertSame(["Alpha 03", "Alpha 04"], array_column($second["items"], "title"));
        self::assertNull($second["next_cursor"]);

        $mismatchedCursor = $this->dispatchAs($this->actorId, $this->queryRequest(
            "/biblio/v1/me/works",
            ["q" => "Beta", "cursor" => $first["next_cursor"]]
        ));
        self::assertSame(422, $mismatchedCursor->get_status());

        $tooLarge = $this->dispatchAs($this->actorId, $this->queryRequest(
            "/biblio/v1/me/works",
            ["q" => "Alpha", "limit" => 26]
        ));
        self::assertSame(400, $tooLarge->get_status());

        $this->seedLibrary("library-a", "Kast A", $this->actorId);
        $this->seedLibrary("library-b", "Kast B", $this->actorId, "view_only");
        $this->seedLibrary("library-foreign", "Verborgen", $this->otherId);
        $this->seedItem("item-a", "library-a", "work-alpha-01");
        $this->seedItem("item-b", "library-b", "work-alpha-01");
        $this->seedItem("item-foreign", "library-foreign", "work-alpha-01");
        $this->seedLoan("loan-own", $this->actorId, "work-alpha-01");
        $this->seedLoan("loan-foreign", $this->otherId, "work-alpha-01");

        $options = $this->data($this->dispatchAs($this->actorId, $this->request(
            "GET",
            "/biblio/v1/me/works/work-alpha-01/preferred-source-options"
        )));
        self::assertCount(3, $options["items"]);
        self::assertSame(
            ["item-a", "item-b"],
            array_column(array_slice($options["items"], 0, 2), "item_id")
        );
        self::assertSame("loan-own", $options["items"][2]["external_loan_id"]);
        $serialized = (string) wp_json_encode($options);
        self::assertStringNotContainsString("item-foreign", $serialized);
        self::assertStringNotContainsString("loan-foreign", $serialized);
        self::assertStringNotContainsString((string) $this->actorId, $serialized);
    }

    public function testStartReadingConsumesAtMostOneAndListRemainsConsistent(): void
    {
        $this->seedLibrary("library-start", "Startkast", $this->actorId);
        $this->seedWork("work-start", "Startwerk");
        $this->seedItem("item-start", "library-start", "work-start");
        $list = $this->add($this->actorId, "work-start", [
            "type" => "library_item",
            "library_id" => "library-start",
            "item_id" => "item-start",
        ]);
        $list = $this->add($this->actorId, "work-start", [
            "type" => "library_item",
            "library_id" => "library-start",
            "item_id" => "item-start",
        ]);
        $list = $this->add($this->actorId, "work-start", null);
        self::assertCount(3, $list["entries"]);

        $started = $this->dispatchAs($this->actorId, $this->jsonRequest(
            "POST",
            "/biblio/v1/libraries/library-start/items/item-start/reading-rounds",
            ["started_on" => "2026-09-02"]
        ));
        self::assertSame(201, $started->get_status());

        $after = $this->data($this->dispatchAs($this->actorId, $this->request(
            "GET",
            "/biblio/v1/me/next-reading"
        )));
        self::assertCount(2, $after["entries"]);
        self::assertSame(5, $after["list_version"]);
        self::assertSame("available", $after["entries"][0]["preferred_source"]["state"]);
        self::assertSame("none", $after["entries"][1]["preferred_source"]["state"]);
        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->libraryActivityEvents()}`"
        ));
        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->nextReadingUndo()}`"
        ));
    }

    /** @param null|array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function add(int $userId, string $workId, ?array $source): array
    {
        return $this->data($this->dispatchAs($userId, $this->jsonRequest(
            "POST",
            "/biblio/v1/me/next-reading",
            ["work_id" => $workId, "preferred_source" => $source]
        )));
    }

    /** @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function preference(
        string $entryId,
        int $expectedVersion,
        array $source
    ): array {
        return $this->data($this->dispatchAs($this->actorId, $this->jsonRequest(
            "PATCH",
            "/biblio/v1/me/next-reading/{$entryId}/preferred-source",
            [
                "preferred_source" => $source,
                "expected_version" => $expectedVersion,
            ]
        )));
    }

    private function request(string $method, string $path): WP_REST_Request
    {
        return new WP_REST_Request($method, $path);
    }

    /** @param array<string, mixed> $body */
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

    /** @param array<string, mixed> $query */
    private function queryRequest(string $path, array $query): WP_REST_Request
    {
        $request = $this->request("GET", $path);
        $request->set_query_params($query);

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

    /** @return array<string, mixed> */
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
            "biblio-c7-{$role}-{$suffix}",
            bin2hex(random_bytes(12)),
            "{$role}-{$suffix}@example.test"
        );

        if ($id instanceof WP_Error) {
            throw new RuntimeException($id->get_error_message());
        }

        return $id;
    }

    private function seedWork(string $workId, string $title): void
    {
        if ((int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$this->tableNames->works()}` WHERE work_id = %s",
            $workId
        )) === 0) {
            self::assertSame(1, $this->database->insert(
                $this->tableNames->works(),
                ["work_id" => $workId, "work_title" => $title]
            ), $this->database->last_error);
        }
    }

    private function seedLibrary(
        string $libraryId,
        string $name,
        int $userId,
        string $access = "direct"
    ): void {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->libraries(),
            [
                "library_id" => $libraryId,
                "library_name" => $name,
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
                "management_role" => "member",
                "use_access" => $access,
                "additional_permissions" => "[]",
            ]
        ), $this->database->last_error);
    }

    private function seedItem(
        string $itemId,
        string $libraryId,
        string $workId
    ): void {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->editions(),
            ["edition_id" => "edition-{$itemId}", "work_id" => $workId]
        ), $this->database->last_error);
        self::assertSame(1, $this->database->insert(
            $this->tableNames->items(),
            [
                "item_id" => $itemId,
                "library_id" => $libraryId,
                "edition_id" => "edition-{$itemId}",
                "item_status" => "active",
            ]
        ), $this->database->last_error);
    }

    private function seedLoan(string $loanId, int $userId, string $workId): void
    {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->externalLoans(),
            [
                "external_loan_id" => $loanId,
                "user_id" => (string) $userId,
                "work_id" => $workId,
                "loan_status" => "active",
                "borrowed_at" => "2026-09-01 10:00:00.000000",
                "due_at" => null,
            ]
        ), $this->database->last_error);
    }
}
