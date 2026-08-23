<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Infrastructure\WordPress\Rest\CatalogCursorCodec;
use Biblio\Core\Infrastructure\WordPress\Rest\RestApi;
use Biblio\Core\Infrastructure\WordPress\Rest\RestErrorMapper;
use Biblio\Core\Infrastructure\WordPress\Rest\RestController;
use Biblio\Core\Infrastructure\WordPress\Rest\RestRequestParser;
use Biblio\Core\Infrastructure\WordPress\Rest\RestResponseSerializer;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class RestApiTest extends PersistenceIntegrationTestCase
{
    private int $actorId;
    private int $otherId;
    private WP_REST_Server $server;
    private RestApi $api;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actorId = $this->createUser("actor");
        $this->otherId = $this->createUser("other");
        $this->server = new WP_REST_Server();

        global $wp_rest_server;
        $wp_rest_server = $this->server;

        $application = (new ProductionComposition($this->database))->application();
        $this->api = new RestApi(static fn () => $application);
        $previousHook = $GLOBALS["wp_filter"]["rest_api_init"] ?? null;
        unset($GLOBALS["wp_filter"]["rest_api_init"]);

        try {
            $this->api->boot();
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
        unset($_SERVER["HTTP_X_WP_NONCE"]);
        unset($GLOBALS["wp_rest_auth_cookie"]);

        require_once ABSPATH . "wp-admin/includes/user.php";
        wp_delete_user($this->actorId);
        wp_delete_user($this->otherId);

        unset($GLOBALS["wp_rest_server"]);

        parent::tearDown();
    }

    public function testRoutesRegisterExactlyOnceAndFailClosedWithoutCore(): void
    {
        $routes = $this->server->get_routes();
        $expected = [
            "/biblio/v1/me/libraries",
            "/biblio/v1/libraries/(?P<library_id>[^/]+)/items",
            "/biblio/v1/libraries/(?P<library_id>[^/]+)/items/(?P<item_id>[^/]+)",
            "/biblio/v1/libraries/(?P<library_id>[^/]+)/items/"
                . "(?P<item_id>[^/]+)/reading-rounds",
        ];

        foreach ($expected as $route) {
            self::assertArrayHasKey($route, $routes);
            foreach ($routes[$route] as $endpoint) {
                if (isset($endpoint["callback"])) {
                    self::assertArrayHasKey("permission_callback", $endpoint);
                }
            }
        }

        $routeCount = count($this->server->get_routes());
        $this->api->registerRoutes();
        self::assertCount($routeCount, $this->server->get_routes());

        $request = new WP_REST_Request(
            "GET",
            "/" . RestController::NAMESPACE . "/me/libraries"
        );
        $anonymous = $this->server->dispatch($request);
        self::assertSame(401, $anonymous->get_status());
        self::assertSame(
            "biblio_authentication_required",
            $anonymous->get_data()["code"]
        );

        $cursors = new CatalogCursorCodec();
        $unavailable = (new RestController(
            static fn () => null,
            new RestRequestParser($cursors),
            new RestResponseSerializer($cursors),
            new RestErrorMapper()
        ))->libraries($request);
        self::assertInstanceOf(WP_Error::class, $unavailable);
        self::assertSame("biblio_core_unavailable", $unavailable->get_error_code());
        self::assertSame(503, $unavailable->get_error_data()["status"]);
    }

    public function testAuthenticatedActorGetsOnlyServerResolvedLibraries(): void
    {
        $this->seedLibrary("library-own", "Mijn bibliotheek", $this->actorId, "owner");
        $this->seedLibrary("library-inactive", "Inactief", $this->actorId, "owner", false);
        $this->seedLibrary("library-other", "Verborgen", $this->otherId, "owner");
        $this->database->insert($this->tableNames->personalLibraryDesignations(), [
            "user_id" => (string) $this->actorId,
            "library_id" => "library-own",
        ]);
        $request = new WP_REST_Request(
            "GET",
            "/biblio/v1/me/libraries"
        );
        $request->set_query_params([
            "user_id" => (string) $this->otherId,
            "capabilities" => ["use_item_directly" => false],
        ]);

        $response = $this->dispatchAsActor($request);
        $data = $this->successData($response);

        self::assertSame(200, $response->get_status());
        self::assertCount(1, $data["libraries"]);
        self::assertSame("library-own", $data["libraries"][0]["library_id"]);
        self::assertTrue($data["libraries"][0]["designated_personal"]);
        self::assertTrue(
            $data["libraries"][0]["capabilities"]["use_item_directly"]
        );
        self::assertArrayNotHasKey("user_id", $data["libraries"][0]);
        self::assertArrayNotHasKey("membership", $data["libraries"][0]);

        $inactive = new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/library-inactive/items"
        );
        $missing = new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/library-missing/items"
        );
        $this->assertEquivalentNotAvailable(
            $this->dispatchAsActor($inactive),
            $this->dispatchAsActor($missing)
        );
    }

    public function testOverviewIsAllowlistedPaginatedAndTenantScoped(): void
    {
        $this->seedLibrary("library-own", "Mijn bibliotheek", $this->actorId, "owner");
        $this->seedLibrary("library-other", "Andere bibliotheek", $this->otherId, "owner");
        $this->seedLibrary("library-empty", "Leeg", $this->actorId, "view_only");
        $this->seedItem("item-alpha", "library-own", "work-alpha", "Alpha");
        $this->seedItem("item-beta", "library-own", "work-beta", "Beta");
        $this->seedItem("item-zulu", "library-own", "work-zulu", "Zulu");
        $this->seedItem("item-other", "library-other", "work-other", "Verborgen");
        $request = new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/library-own/items"
        );
        $request->set_query_params([
            "page_size" => "2",
            "user_id" => (string) $this->otherId,
            "start_reading" => false,
        ]);

        $first = $this->dispatchAsActor($request);
        $firstData = $this->successData($first);

        self::assertSame(200, $first->get_status());
        self::assertSame(
            ["item-alpha", "item-beta"],
            array_column($firstData["items"], "item_id")
        );
        self::assertNotNull($firstData["next_cursor"]);
        self::assertSame([
            "item_id",
            "work_id",
            "edition_id",
            "title",
            "authors",
            "cover_reference",
            "form",
            "location_or_source",
            "reading_status",
            "item_status",
            "capabilities",
        ], array_keys($firstData["items"][0]));
        self::assertTrue($firstData["items"][0]["capabilities"]["start_reading"]);
        self::assertArrayNotHasKey("library_id", $firstData["items"][0]);
        self::assertArrayNotHasKey("user_id", $firstData["items"][0]);

        $request->set_query_params([
            "page_size" => "2",
            "cursor" => $firstData["next_cursor"],
        ]);
        $secondData = $this->successData($this->dispatchAsActor($request));
        self::assertSame(["item-zulu"], array_column($secondData["items"], "item_id"));
        self::assertNull($secondData["next_cursor"]);
        self::assertNotContains("item-other", array_column($secondData["items"], "item_id"));

        $empty = new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/library-empty/items"
        );
        self::assertSame([], $this->successData(
            $this->dispatchAsActor($empty)
        )["items"]);

        $foreign = new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/library-other/items"
        );
        $missing = new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/library-missing/items"
        );
        $this->assertEquivalentNotAvailable(
            $this->dispatchAsActor($foreign),
            $this->dispatchAsActor($missing)
        );
    }

    public function testDetailPreservesUnknownMetadataAndNonEnumeration(): void
    {
        $this->seedLibrary("library-own", "Mijn bibliotheek", $this->actorId, "owner");
        $this->seedLibrary("library-other", "Andere bibliotheek", $this->otherId, "owner");
        $this->seedItem("item-own", "library-own", "work-own", "Boektitel");
        $this->seedItem("item-other", "library-other", "work-other", "Verborgen");
        $request = new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/library-own/items/item-own"
        );
        $request->set_query_params(["item_id" => "item-other"]);

        $response = $this->dispatchAsActor($request);
        $detail = $this->successData($response);

        self::assertSame("item-own", $detail["item_id"]);
        self::assertSame("Boektitel", $detail["title"]);
        self::assertSame(["state" => "unknown", "value" => null], $detail["isbn"]);
        self::assertSame(["state" => "unknown", "values" => []], $detail["authors"]);
        self::assertSame("not_read", $detail["reading"]["status"]);
        self::assertArrayNotHasKey("created_at", $detail);
        self::assertArrayNotHasKey("active_round_user_ids", $detail["reading"]);

        $foreign = new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/library-own/items/item-other"
        );
        $missing = new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/library-own/items/item-missing"
        );
        $this->assertEquivalentNotAvailable(
            $this->dispatchAsActor($foreign),
            $this->dispatchAsActor($missing)
        );
    }

    public function testStartReadingUsesWordPressNonceAndCoreAuthorization(): void
    {
        $this->seedLibrary("library-own", "Mijn bibliotheek", $this->actorId, "owner");
        $this->seedLibrary("library-view", "Kijken", $this->actorId, "view_only");
        $this->seedLibrary("library-other", "Andere", $this->otherId, "owner");
        $this->seedItem("item-own", "library-own", "work-own", "Boek");
        $this->seedItem("item-second", "library-own", "work-second", "Tweede");
        $this->seedItem("item-view", "library-view", "work-view", "Kijken");
        $this->seedItem("item-other", "library-other", "work-other", "Ander");

        $valid = $this->startRequest("library-own", "item-own", [
            "started_on" => "2026-08-23",
        ]);
        $valid->set_query_params([
            "user_id" => (string) $this->otherId,
            "capabilities" => ["start_reading" => false],
        ]);
        $created = $this->dispatchAsActor($valid);
        $round = $this->successData($created);

        self::assertSame(201, $created->get_status());
        self::assertSame("work-own", $round["work_id"]);
        self::assertSame("item-own", $round["source"]["item_id"]);
        self::assertSame("active", $round["lifecycle"]);
        self::assertSame(
            ["year" => 2026, "month" => 8, "day" => 23],
            $round["started_on"]
        );
        self::assertSame(1, $round["version"]);
        self::assertArrayNotHasKey("user_id", $round);
        self::assertArrayNotHasKey("created_at", $round);
        self::assertSame(
            (string) $this->actorId,
            $this->database->get_var(
                "SELECT user_id FROM `{$this->tableNames->readingRounds()}` "
                . "WHERE item_id = 'item-own'"
            )
        );

        $duplicate = $this->dispatchAsActor($valid);
        self::assertSame(409, $duplicate->get_status());
        self::assertSame(
            "biblio_reading_round_already_active_for_source",
            $duplicate->get_data()["code"]
        );

        $missingNonce = $this->dispatchAsActor(
            $this->startRequest("library-own", "item-second", [
                "started_on" => "2026-08-23",
            ]),
            null
        );
        self::assertSame(401, $missingNonce->get_status());
        self::assertSame(
            "biblio_authentication_required",
            $missingNonce->get_data()["code"]
        );

        $invalidNonce = $this->dispatchAsActor(
            $this->startRequest("library-own", "item-second", [
                "started_on" => "2026-08-23",
            ]),
            "not-a-valid-nonce"
        );
        self::assertSame(403, $invalidNonce->get_status());
        self::assertSame("rest_cookie_invalid_nonce", $invalidNonce->get_data()["code"]);

        $viewOnly = $this->dispatchAsActor($this->startRequest(
            "library-view",
            "item-view",
            ["started_on" => "2026-08-23"]
        ));
        $crossLibrary = $this->dispatchAsActor($this->startRequest(
            "library-own",
            "item-other",
            ["started_on" => "2026-08-23"]
        ));
        $this->assertEquivalentNotAvailable($viewOnly, $crossLibrary);

        $spoofedBody = $this->dispatchAsActor($this->startRequest(
            "library-own",
            "item-second",
            [
                "started_on" => "2026-08-23",
                "user_id" => (string) $this->otherId,
                "capabilities" => ["start_reading" => true],
            ]
        ));
        self::assertSame(400, $spoofedBody->get_status());
        self::assertSame(
            "biblio_unknown_request_fields",
            $spoofedBody->get_data()["code"]
        );
    }

    public function testTypedValidationAndErrorMapperNeverLeakInternals(): void
    {
        $this->seedLibrary("library-own", "Mijn bibliotheek", $this->actorId, "owner");

        $badPage = new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/library-own/items"
        );
        $badPage->set_query_params(["page_size" => "101"]);
        $badPageResponse = $this->dispatchAsActor($badPage);
        self::assertSame(400, $badPageResponse->get_status());
        self::assertSame(
            "biblio_invalid_field_syntax",
            $badPageResponse->get_data()["code"]
        );

        $badCursor = new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/library-own/items"
        );
        $badCursor->set_query_params(["cursor" => "***"]);
        self::assertSame(
            "biblio_invalid_field_syntax",
            $this->dispatchAsActor($badCursor)->get_data()["code"]
        );

        $missingDate = $this->dispatchAsActor($this->startRequest(
            "library-own",
            "item-missing",
            []
        ));
        self::assertSame("biblio_missing_required_field", $missingDate->get_data()["code"]);

        $badDate = $this->dispatchAsActor($this->startRequest(
            "library-own",
            "item-missing",
            ["started_on" => "2026-02-30"]
        ));
        self::assertSame("biblio_invalid_field_syntax", $badDate->get_data()["code"]);

        $wrongDateType = $this->dispatchAsActor($this->startRequest(
            "library-own",
            "item-missing",
            ["started_on" => 20260823]
        ));
        self::assertSame("biblio_invalid_field_type", $wrongDateType->get_data()["code"]);

        $scalarBody = new WP_REST_Request(
            "POST",
            "/biblio/v1/libraries/library-own/items/item-missing/reading-rounds"
        );
        $scalarBody->set_header("content-type", "application/json");
        $scalarBody->set_body('"2026-08-23"');
        self::assertSame(
            "biblio_invalid_field_type",
            $this->dispatchAsActor($scalarBody)->get_data()["code"]
        );

        $mapper = new RestErrorMapper();
        $unexpected = $mapper->map(new RuntimeException(
            "SELECT secret FROM wp_users at /private/plugin.php:99"
        ));
        self::assertSame("biblio_internal_error", $unexpected->get_error_code());
        self::assertStringNotContainsString(
            "SELECT",
            $unexpected->get_error_message()
        );
        self::assertStringNotContainsString(
            "/private/",
            $unexpected->get_error_message()
        );

        $private = $mapper->map(new AuthorizationException(
            "Library secret membership details"
        ));
        self::assertSame("biblio_resource_not_available", $private->get_error_code());
        self::assertStringNotContainsString(
            "membership",
            $private->get_error_message()
        );

        $semantic = $mapper->map(new ValidationException(
            "Private semantic validation detail"
        ));
        self::assertSame("biblio_validation_failed", $semantic->get_error_code());
        self::assertSame(422, $semantic->get_error_data()["status"]);
        self::assertStringNotContainsString(
            "Private",
            $semantic->get_error_message()
        );
    }

    private function dispatchAsActor(
        WP_REST_Request $request,
        ?string $nonce = "valid"
    ): WP_REST_Response {
        wp_set_current_user($this->actorId);

        global $wp_rest_auth_cookie;
        $wp_rest_auth_cookie = true;
        unset($_SERVER["HTTP_X_WP_NONCE"]);

        if ($nonce !== null) {
            $_SERVER["HTTP_X_WP_NONCE"] = $nonce === "valid"
                ? wp_create_nonce("wp_rest")
                : $nonce;
        }

        $authentication = rest_cookie_check_errors(null);

        if (is_wp_error($authentication)) {
            return rest_convert_error_to_response($authentication);
        }

        return $this->server->dispatch($request);
    }

    /** @param array<string, mixed> $body */
    private function startRequest(
        string $libraryId,
        string $itemId,
        array $body
    ): WP_REST_Request {
        $request = new WP_REST_Request(
            "POST",
            "/biblio/v1/libraries/{$libraryId}/items/{$itemId}/reading-rounds"
        );
        $request->set_header("content-type", "application/json");
        $request->set_body((string) wp_json_encode($body));

        return $request;
    }

    /** @return array<string, mixed> */
    private function successData(WP_REST_Response $response): array
    {
        $payload = $response->get_data();
        self::assertIsArray($payload);
        self::assertArrayHasKey("data", $payload);
        self::assertIsArray($payload["data"]);

        return $payload["data"];
    }

    private function assertEquivalentNotAvailable(
        WP_REST_Response $left,
        WP_REST_Response $right
    ): void {
        self::assertSame(404, $left->get_status());
        self::assertSame($left->get_status(), $right->get_status());
        self::assertSame($left->get_data(), $right->get_data());
        self::assertSame(
            "biblio_resource_not_available",
            $left->get_data()["code"]
        );
    }

    private function createUser(string $role): int
    {
        $suffix = bin2hex(random_bytes(6));
        $id = wp_create_user(
            "biblio-rest-{$role}-{$suffix}",
            bin2hex(random_bytes(12)),
            "{$role}-{$suffix}@example.test"
        );

        if ($id instanceof WP_Error) {
            throw new RuntimeException($id->get_error_message());
        }

        return $id;
    }

    private function seedLibrary(
        string $libraryId,
        string $name,
        int $userId,
        string $access,
        bool $active = true
    ): void {
        $this->database->insert($this->tableNames->libraries(), [
            "library_id" => $libraryId,
            "library_name" => $name,
            "library_type" => "private_library",
            "library_status" => "active",
        ]);
        $this->database->insert($this->tableNames->memberships(), [
            "library_id" => $libraryId,
            "user_id" => (string) $userId,
            "membership_status" => $active ? "active" : "inactive",
            "management_role" => $access === "owner" ? "owner" : "member",
            "use_access" => $access === "view_only" ? "view_only" : "direct",
            "additional_permissions" => "[]",
        ]);
    }

    private function seedItem(
        string $itemId,
        string $libraryId,
        string $workId,
        string $title
    ): void {
        $this->database->insert($this->tableNames->works(), [
            "work_id" => $workId,
            "work_title" => $title,
        ]);
        $this->database->insert($this->tableNames->editions(), [
            "edition_id" => "edition-{$itemId}",
            "work_id" => $workId,
        ]);
        $this->database->insert($this->tableNames->items(), [
            "item_id" => $itemId,
            "library_id" => $libraryId,
            "edition_id" => "edition-{$itemId}",
            "item_status" => "active",
        ]);
    }
}
