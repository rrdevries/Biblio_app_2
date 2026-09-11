<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Application\Notes\Read\PrivateNoteViewCursor;
use Biblio\Core\Application\Metadata\{CandidateClassifier,FirstSufficientMetadataLookupService,MetadataCandidate,MetadataCandidateId,MetadataLookupId,MetadataLookupSnapshot,MetadataMatchMethod};
use Biblio\Core\Application\Metadata\Discovery\{BibliographicCandidateType,BibliographicDiscoveryCandidate,BibliographicDiscoveryQuery,BibliographicDiscoverySnapshot,BibliographicTextQuery};
use Biblio\Core\Application\Metadata\Search\{
    BibliographicAuthorReference,
    BibliographicAuthorSearchResult,
    BibliographicAuthorSelectorCodec,
    BibliographicProviderEntityIdentity,
    BibliographicSearchCursorCodec,
    BibliographicTextSearchQuery
};
use Biblio\Core\Catalog\AuthorId;
use Biblio\Core\Catalog\{CanonicalIsbnIdentity,Isbn13};
use Biblio\Core\Identity\UserId;
use Biblio\Core\Exception\AuthorizationException;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbMetadataLookupSnapshotRepository;
use Biblio\Core\Infrastructure\Persistence\WordPress\WpdbBibliographicDiscoverySnapshotRepository;
use Biblio\Core\Infrastructure\Metadata\ConfigurationErrorMetadataProvider;
use Biblio\Core\Infrastructure\Metadata\RuntimeMetadataProviderConfiguration;
use Biblio\Core\Infrastructure\WordPress\Rest\CatalogCursorCodec;
use Biblio\Core\Infrastructure\WordPress\Rest\PrivateNoteCursorCodec;
use Biblio\Core\Infrastructure\WordPress\Rest\ReadingHistoryCursorCodec;
use Biblio\Core\Infrastructure\WordPress\Rest\RestApi;
use Biblio\Core\Infrastructure\WordPress\Rest\RestErrorMapper;
use Biblio\Core\Infrastructure\WordPress\Rest\RestController;
use Biblio\Core\Infrastructure\WordPress\Rest\RestRequestParser;
use Biblio\Core\Infrastructure\WordPress\Rest\RestResponseSerializer;
use Biblio\Core\Notes\PrivateNoteId;
use DateTimeImmutable;
use RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $application = (new ProductionComposition(
            $this->database,
            metadataLookup: new FirstSufficientMetadataLookupService(
                new CandidateClassifier(),
                new ConfigurationErrorMetadataProvider("open_library"),
                new ConfigurationErrorMetadataProvider("google_books")
            ),
            providerConfiguration: new RuntimeMetadataProviderConfiguration(
                static fn (string $name): bool => false,
                static fn (string $name): mixed => null,
                static fn (string $name): mixed => false
            )
        ))->application();
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
            "/biblio/v1/me/reading-rounds/(?P<reading_round_id>[^/]+)/end",
            "/biblio/v1/me/works/(?P<work_id>[^/]+)/reading-history",
            "/biblio/v1/me/works/(?P<work_id>[^/]+)/private-notes",
            "/biblio/v1/me/private-notes/(?P<private_note_id>[^/]+)",
            "/biblio/v1/me/next-reading",
            "/biblio/v1/me/next-reading/(?P<entry_id>[^/]+)",
            "/biblio/v1/me/next-reading/undo",
            "/biblio/v1/me/next-reading/reorder",
            "/biblio/v1/me/next-reading/(?P<entry_id>[^/]+)/preferred-source",
            "/biblio/v1/me/wishlist",
            "/biblio/v1/me/wishlist/(?P<wishlist_entry_id>[^/]+)",
            "/biblio/v1/me/works",
            "/biblio/v1/me/bibliographic-discoveries",
            "/biblio/v1/me/bibliographic-searches",
            "/biblio/v1/me/bibliographic-author-works",
            "/biblio/v1/me/bibliographic-discoveries/"
                . "(?P<discovery_id>[^/]+)/materializations",
            "/biblio/v1/me/works/(?P<work_id>[^/]+)/preferred-source-options",
            "/biblio/v1/libraries/(?P<library_id>[^/]+)/works/"
                . "(?P<work_id>[^/]+)/assessments",
            "/biblio/v1/libraries/(?P<library_id>[^/]+)/items",
            "/biblio/v1/libraries/(?P<library_id>[^/]+)/catalog",
            "/biblio/v1/libraries/(?P<library_id>[^/]+)/classification-options",
            "/biblio/v1/libraries/(?P<library_id>[^/]+)/metadata-lookups",
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

        self::assertCount(25, array_filter(
            array_keys($routes),
            static fn (string $route): bool => str_starts_with(
                $route,
                "/biblio/v1/"
            )
        ));
        $endRoute = $routes[
            "/biblio/v1/me/reading-rounds/(?P<reading_round_id>[^/]+)/end"
        ];
        $endMethods = [];

        foreach ($endRoute as $endpoint) {
            if (isset($endpoint["callback"])) {
                $endMethods = $endpoint["methods"];
            }
        }

        self::assertArrayHasKey("POST", $endMethods);
        self::assertArrayNotHasKey("GET", $endMethods);

        $historyRoute = $routes[
            "/biblio/v1/me/works/(?P<work_id>[^/]+)/reading-history"
        ];
        $historyMethods = [];

        foreach ($historyRoute as $endpoint) {
            if (isset($endpoint["callback"])) {
                $historyMethods = $endpoint["methods"];
            }
        }

        self::assertArrayHasKey("GET", $historyMethods);
        self::assertArrayNotHasKey("POST", $historyMethods);

        $metadataLookupMethods = $this->routeMethods($routes[
            "/biblio/v1/libraries/(?P<library_id>[^/]+)/metadata-lookups"
        ]);
        self::assertSame(["POST"], $metadataLookupMethods);
        self::assertSame(["POST"], $this->routeMethods($routes[
            "/biblio/v1/me/bibliographic-discoveries"
        ]));
        self::assertSame(["POST"], $this->routeMethods($routes[
            "/biblio/v1/me/bibliographic-searches"
        ]));
        self::assertSame(["POST"], $this->routeMethods($routes[
            "/biblio/v1/me/bibliographic-author-works"
        ]));
        self::assertSame(["POST"], $this->routeMethods($routes[
            "/biblio/v1/me/bibliographic-discoveries/"
                . "(?P<discovery_id>[^/]+)/materializations"
        ]));
        self::assertSame(["GET", "POST"], $this->routeMethods($routes[
            "/biblio/v1/libraries/(?P<library_id>[^/]+)/items"
        ]));

        $noteCollectionMethods = $this->routeMethods(
            $routes[
                "/biblio/v1/me/works/(?P<work_id>[^/]+)/private-notes"
            ]
        );
        self::assertSame(["GET", "POST"], $noteCollectionMethods);
        $noteMemberMethods = $this->routeMethods(
            $routes[
                "/biblio/v1/me/private-notes/(?P<private_note_id>[^/]+)"
            ]
        );
        self::assertSame(["DELETE", "PATCH"], $noteMemberMethods);

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
        $historyCursors = new ReadingHistoryCursorCodec();
        $privateNoteCursors = new PrivateNoteCursorCodec();
        $unavailable = (new RestController(
            static fn () => null,
            new RestRequestParser(
                $cursors,
                $historyCursors,
                $privateNoteCursors
            ),
            new RestResponseSerializer(
                $cursors,
                $historyCursors,
                $privateNoteCursors
            ),
            new RestErrorMapper()
        ))->libraries($request);
        self::assertInstanceOf(WP_Error::class, $unavailable);
        self::assertSame("biblio_core_unavailable", $unavailable->get_error_code());
        self::assertSame(503, $unavailable->get_error_data()["status"]);

        $endUnavailable = (new RestController(
            static fn () => null,
            new RestRequestParser(
                $cursors,
                $historyCursors,
                $privateNoteCursors
            ),
            new RestResponseSerializer(
                $cursors,
                $historyCursors,
                $privateNoteCursors
            ),
            new RestErrorMapper()
        ))->endReading($this->endRequest("round-unavailable", [
            "outcome" => "completed",
            "finished_on" => "2026-08-29",
            "expected_version" => 1,
        ]));
        self::assertInstanceOf(WP_Error::class, $endUnavailable);
        self::assertSame(
            "biblio_core_unavailable",
            $endUnavailable->get_error_code()
        );
        self::assertSame(503, $endUnavailable->get_error_data()["status"]);

        $historyUnavailable = (new RestController(
            static fn () => null,
            new RestRequestParser(
                $cursors,
                $historyCursors,
                $privateNoteCursors
            ),
            new RestResponseSerializer(
                $cursors,
                $historyCursors,
                $privateNoteCursors
            ),
            new RestErrorMapper()
        ))->readingHistory($this->historyRequest("work-unavailable"));
        self::assertInstanceOf(WP_Error::class, $historyUnavailable);
        self::assertSame(
            "biblio_core_unavailable",
            $historyUnavailable->get_error_code()
        );
        self::assertSame(503, $historyUnavailable->get_error_data()["status"]);

        $privateNotesUnavailable = (new RestController(
            static fn () => null,
            new RestRequestParser(
                $cursors,
                $historyCursors,
                $privateNoteCursors
            ),
            new RestResponseSerializer(
                $cursors,
                $historyCursors,
                $privateNoteCursors
            ),
            new RestErrorMapper()
        ))->privateNotes($this->privateNotesRequest("work-unavailable"));
        self::assertInstanceOf(WP_Error::class, $privateNotesUnavailable);
        self::assertSame(
            "biblio_core_unavailable",
            $privateNotesUnavailable->get_error_code()
        );
        self::assertSame(
            503,
            $privateNotesUnavailable->get_error_data()["status"]
        );
    }

    public function testBibliographicDiscoveryRestIsStrictAuthenticatedAndTyped(): void
    {
        $this->seedWork("rest-bibliographic-work", "The Dispossessed");
        $request = new WP_REST_Request(
            "POST",
            "/biblio/v1/me/bibliographic-discoveries"
        );
        $request->set_header("content-type", "application/json");
        $request->set_body((string) wp_json_encode([
            "query" => "  The   Dispossessed  ",
        ]));

        $response = $this->dispatchAsActor($request);

        self::assertSame(200, $response->get_status());
        $data = $response->get_data()["data"];
        self::assertSame("text", $data["query"]["type"]);
        self::assertSame(
            "The Dispossessed",
            $data["query"]["normalized"]
        );
        self::assertNull($data["discovery_id"]);
        self::assertSame(
            "local_work",
            $data["results"][0]["type"]
        );
        self::assertSame([
            "can_add_work_only" => true,
            "can_add_edition_specific" => false,
        ], $data["results"][0]["capabilities"]);
        self::assertNull($data["results"][0]["provider_evidence"]);
        self::assertSame([
            "configuration_error",
            "configuration_error",
        ], array_column($data["provider_attempts"], "status"));

        $unknown = new WP_REST_Request(
            "POST",
            "/biblio/v1/me/bibliographic-discoveries"
        );
        $unknown->set_header("content-type", "application/json");
        $unknown->set_body((string) wp_json_encode([
            "query" => "The Dispossessed",
            "provider" => "open_library",
        ]));
        $rejected = $this->dispatchAsActor($unknown);
        self::assertSame(400, $rejected->get_status());
        self::assertSame(
            "biblio_unknown_request_fields",
            $rejected->get_data()["code"]
        );

        wp_set_current_user(0);
        $anonymous = $this->server->dispatch($request);
        self::assertSame(401, $anonymous->get_status());
    }

    public function testBibliographicSearchRestIsAuthenticatedGroupedAndPartialFailureSafe(): void
    {
        $this->seedWork("rest-search-work", "The Dispossessed");
        self::assertSame(1, $this->database->insert($this->tableNames->authors(), [
            "author_id" => "rest-search-author",
            "display_name" => "Ursula K. Le Guin",
        ]));
        self::assertSame(1, $this->database->insert(
            $this->tableNames->workContributors(),
            [
                "work_id" => "rest-search-work",
                "author_id" => "rest-search-author",
                "contributor_role" => "author",
                "contributor_position" => 1,
            ]
        ));

        $request = $this->bibliographicSearchRequest([
            "query" => "  Ursula   Le Guin ",
        ]);
        $response = $this->dispatchAsActor($request);

        self::assertSame(200, $response->get_status());
        $data = $this->successData($response);
        self::assertSame(["query", "authors", "works"], array_keys($data));
        self::assertSame("Ursula Le Guin", $data["query"]);
        self::assertSame("rest-search-author", $data["authors"]["items"][0]["author_id"]);
        self::assertSame(
            ["result_id", "result_kind", "author_id", "display_name", "author_selector"],
            array_keys($data["authors"]["items"][0])
        );
        $salt = constant("AUTH_SALT");
        self::assertIsString($salt);
        $selectedAuthor = (new BibliographicAuthorSelectorCodec(
            hash("sha256", $salt . ":bibliographic-author-selector-v1")
        ))->decode($data["authors"]["items"][0]["author_selector"]);
        self::assertSame("rest-search-author", $selectedAuthor->authorId()?->value());
        self::assertNull($selectedAuthor->providerIdentity());
        self::assertSame("rest-search-work", $data["works"]["items"][0]["work_id"]);
        self::assertSame(
            "configuration_error",
            $data["authors"]["provider_attempts"][0]["status"]
        );
        self::assertSame(
            "configuration_error",
            $data["works"]["provider_attempts"][0]["status"]
        );
        foreach (["isbn", "publisher", "publication_date", "language", "library_id", "user_id"] as $field) {
            self::assertArrayNotHasKey($field, $data["works"]["items"][0]);
        }

        wp_set_current_user(0);
        self::assertSame(401, $this->server->dispatch($request)->get_status());

        foreach ([
            ["query" => "Ursula Le Guin", "provider" => "open_library"],
            ["query" => "9780441172719"],
        ] as $body) {
            self::assertSame(
                400,
                $this->dispatchAsActor(
                    $this->bibliographicSearchRequest($body)
                )->get_status()
            );
        }
    }

    public function testBibliographicSearchRestPaginatesGroupsIndependentlyAndRejectsCursorMisuse(): void
    {
        for ($position = 1; $position <= 12; $position++) {
            self::assertSame(1, $this->database->insert($this->tableNames->authors(), [
                "author_id" => sprintf("rest-page-author-%02d", $position),
                "display_name" => sprintf("Pageable Author %02d", $position),
            ]));
            $this->seedWork(
                sprintf("rest-page-work-%02d", $position),
                sprintf("Pageable Work %02d", $position)
            );
        }

        $first = $this->successData($this->dispatchAsActor(
            $this->bibliographicSearchRequest(["query" => "Pageable"])
        ));
        self::assertCount(10, $first["authors"]["items"]);
        self::assertCount(10, $first["works"]["items"]);
        self::assertIsString($first["authors"]["next_cursor"]);
        self::assertIsString($first["works"]["next_cursor"]);

        $authors = $this->successData($this->dispatchAsActor(
            $this->bibliographicSearchRequest([
                "query" => "Pageable",
                "author_cursor" => $first["authors"]["next_cursor"],
            ])
        ));
        self::assertCount(2, $authors["authors"]["items"]);
        self::assertCount(10, $authors["works"]["items"]);

        $works = $this->successData($this->dispatchAsActor(
            $this->bibliographicSearchRequest([
                "query" => "Pageable",
                "work_cursor" => $first["works"]["next_cursor"],
            ])
        ));
        self::assertCount(10, $works["authors"]["items"]);
        self::assertCount(2, $works["works"]["items"]);

        foreach ([
            [
                "query" => "Pageable",
                "work_cursor" => $first["authors"]["next_cursor"],
            ],
            [
                "query" => "Different query",
                "author_cursor" => $first["authors"]["next_cursor"],
            ],
        ] as $body) {
            $rejected = $this->dispatchAsActor(
                $this->bibliographicSearchRequest($body)
            );
            self::assertSame(400, $rejected->get_status());
            self::assertSame(
                "biblio_invalid_field_syntax",
                $rejected->get_data()["code"]
            );
        }

        $query = new BibliographicTextSearchQuery("Pageable");
        $salt = constant("AUTH_SALT");
        self::assertIsString($salt);
        $cursor = (new BibliographicSearchCursorCodec(
            hash("sha256", $salt . ":bibliographic-text-search-v1")
        ))->encode((new BibliographicAuthorSearchResult(
            BibliographicAuthorReference::canonical(new AuthorId("rest-page-author-01")),
            "Pageable Author 01",
            0
        ))->cursor($query));
        $wrongSlot = $this->dispatchAsActor($this->bibliographicSearchRequest([
            "query" => "Pageable",
            "work_cursor" => $cursor,
        ]));
        self::assertSame(400, $wrongSlot->get_status());
    }

    public function testBibliographicAuthorWorksRestAcceptsEverySelectorFormAndRetainsLocalOnFailure(): void
    {
        self::assertSame(1, $this->database->insert($this->tableNames->authors(), [
            "author_id" => "rest-author-works",
            "display_name" => "Selected Author",
        ]));
        $this->seedWork("rest-author-work", "Selected Work");
        self::assertSame(1, $this->database->insert(
            $this->tableNames->workContributors(),
            [
                "work_id" => "rest-author-work",
                "author_id" => "rest-author-works",
                "contributor_role" => "author",
                "contributor_position" => 1,
            ]
        ));
        $canonical = BibliographicAuthorReference::canonical(
            new AuthorId("rest-author-works")
        );
        $provider = BibliographicProviderEntityIdentity::author(
            "open_library",
            "/authors/OL1A"
        );

        $canonicalResponse = $this->dispatchAsActor(
            $this->bibliographicAuthorWorksRequest([
                "author_selector" => $this->authorSelectorCodec()->encode($canonical),
                "cursor" => null,
            ])
        );
        self::assertSame(200, $canonicalResponse->get_status());
        $canonicalData = $this->successData($canonicalResponse);
        self::assertSame(["items", "next_cursor", "provider_attempts"], array_keys($canonicalData));
        self::assertSame("rest-author-work", $canonicalData["items"][0]["work_id"]);
        self::assertNull($canonicalData["items"][0]["provider_identity"]);
        self::assertSame([], $canonicalData["provider_attempts"]);

        $failedComposite = $this->successData($this->dispatchAsActor(
            $this->bibliographicAuthorWorksRequest([
                "author_selector" => $this->authorSelectorCodec()->encode(
                    BibliographicAuthorReference::canonical(
                        new AuthorId("rest-author-works"),
                        $provider
                    )
                ),
            ])
        ));
        self::assertSame("rest-author-work", $failedComposite["items"][0]["work_id"]);
        self::assertSame("configuration_error", $failedComposite["provider_attempts"][0]["status"]);
        self::assertSame("configuration", $failedComposite["provider_attempts"][0]["failure_reason"]);

        $this->rebuildApiWithOpenLibraryConfiguration();
        $httpFixture = static function (mixed $preempt, array $arguments, string $url): array {
            self::assertStringContainsString(
                "/authors/OL1A/works.json?limit=",
                $url
            );
            self::assertSame("GET", $arguments["method"] ?? "GET");
            return [
                "headers" => [],
                "body" => (string) wp_json_encode([
                    "links" => ["author" => "/authors/OL1A"],
                    "size" => 1,
                    "entries" => [[
                        "key" => "/works/OL99W",
                        "title" => "External Selected Work",
                        "authors" => [[
                            "author" => ["key" => "/authors/OL1A"],
                        ]],
                    ]],
                ]),
                "response" => ["code" => 200, "message" => "OK"],
                "cookies" => [],
                "filename" => null,
            ];
        };
        add_filter("pre_http_request", $httpFixture, 10, 3);
        try {
            $providerData = $this->successData($this->dispatchAsActor(
                $this->bibliographicAuthorWorksRequest([
                    "author_selector" => $this->authorSelectorCodec()->encode(
                        BibliographicAuthorReference::external($provider)
                    ),
                ])
            ));
            self::assertSame("External Selected Work", $providerData["items"][0]["title"]);
            self::assertNull($providerData["items"][0]["work_id"]);
            self::assertSame([
                "provider_key" => "open_library",
                "record_id" => "/works/OL99W",
            ], $providerData["items"][0]["provider_identity"]);
            self::assertSame("candidates", $providerData["provider_attempts"][0]["status"]);

            $compositeData = $this->successData($this->dispatchAsActor(
                $this->bibliographicAuthorWorksRequest([
                    "author_selector" => $this->authorSelectorCodec()->encode(
                        BibliographicAuthorReference::canonical(
                            new AuthorId("rest-author-works"),
                            $provider
                        )
                    ),
                ])
            ));
            self::assertSame(
                ["Selected Work", "External Selected Work"],
                array_column($compositeData["items"], "title")
            );
            self::assertSame("candidates", $compositeData["provider_attempts"][0]["status"]);
        } finally {
            remove_filter("pre_http_request", $httpFixture, 10);
        }

        wp_set_current_user(0);
        self::assertSame(401, $this->server->dispatch(
            $this->bibliographicAuthorWorksRequest([
                "author_selector" => $this->authorSelectorCodec()->encode($canonical),
            ])
        )->get_status());
    }

    public function testBibliographicAuthorWorksRestRejectsUntrustedFieldsAndCursorCrossBinding(): void
    {
        foreach (["rest-cursor-author-a", "rest-cursor-author-b"] as $authorId) {
            self::assertSame(1, $this->database->insert($this->tableNames->authors(), [
                "author_id" => $authorId,
                "display_name" => $authorId,
            ]));
        }
        for ($position = 1; $position <= 11; $position++) {
            $workId = sprintf("rest-author-page-work-%02d", $position);
            $this->seedWork($workId, sprintf("Author Page Work %02d", $position));
            self::assertSame(1, $this->database->insert(
                $this->tableNames->workContributors(),
                [
                    "work_id" => $workId,
                    "author_id" => "rest-cursor-author-a",
                    "contributor_role" => "author",
                    "contributor_position" => 1,
                ]
            ));
        }
        $selectorA = $this->authorSelectorCodec()->encode(
            BibliographicAuthorReference::canonical(new AuthorId("rest-cursor-author-a"))
        );
        $selectorB = $this->authorSelectorCodec()->encode(
            BibliographicAuthorReference::canonical(new AuthorId("rest-cursor-author-b"))
        );
        $first = $this->successData($this->dispatchAsActor(
            $this->bibliographicAuthorWorksRequest(["author_selector" => $selectorA])
        ));
        self::assertCount(10, $first["items"]);
        self::assertIsString($first["next_cursor"]);

        $second = $this->successData($this->dispatchAsActor(
            $this->bibliographicAuthorWorksRequest([
                "author_selector" => $selectorA,
                "cursor" => $first["next_cursor"],
            ])
        ));
        self::assertCount(1, $second["items"]);
        self::assertNull($second["next_cursor"]);

        self::assertSame(400, $this->dispatchAsActor(
            $this->bibliographicAuthorWorksRequest([
                "author_selector" => $selectorB,
                "cursor" => $first["next_cursor"],
            ])
        )->get_status());

        foreach ([
            [],
            ["author_selector" => 42],
            ["author_selector" => $selectorA, "author_id" => "rest-cursor-author-a"],
            ["author_selector" => $selectorA, "provider" => "open_library"],
            ["author_selector" => $selectorA, "name" => "Selected Author"],
            ["author_selector" => $selectorA, "library_id" => "library-a"],
            ["author_selector" => $selectorA, "user_id" => $this->actorId],
            ["author_selector" => $selectorA . "x"],
            ["author_selector" => $selectorA, "cursor" => false],
        ] as $body) {
            self::assertSame(400, $this->dispatchAsActor(
                $this->bibliographicAuthorWorksRequest($body)
            )->get_status());
        }

        $queryRequest = $this->bibliographicAuthorWorksRequest([
            "author_selector" => $selectorA,
        ]);
        $queryRequest->set_query_params(["library_id" => "library-a"]);
        self::assertSame(400, $this->dispatchAsActor($queryRequest)->get_status());

        foreach (["{", "{\"author_selector\":\"\xC3\x28\"}"] as $body) {
            self::assertSame(400, $this->dispatchAsActor(
                $this->bibliographicAuthorWorksRawRequest($body)
            )->get_status());
        }
    }

    public function testBibliographicSearchRestRejectsMalformedJsonUtf8AndUnexpectedQueryInput(): void
    {
        foreach ([
            ["", "biblio_missing_required_field"],
            ["[]", "biblio_invalid_field_type"],
            ["{", "rest_invalid_json"],
            ["{\"query\":\"\xC3\x28\"}", "rest_invalid_json"],
            [(string) wp_json_encode([
                "query" => str_repeat("x", 101),
            ]), "biblio_invalid_field_syntax"],
            [(string) wp_json_encode([
                "query" => "Dune",
                "author_cursor" => [],
            ]), "biblio_invalid_field_type"],
        ] as [$body, $code]) {
            $response = $this->dispatchAsActor(
                $this->bibliographicSearchRawRequest($body)
            );
            self::assertSame(400, $response->get_status());
            self::assertSame($code, $response->get_data()["code"]);
        }

        $queryFields = $this->bibliographicSearchRequest(["query" => "Dune"]);
        $queryFields->set_query_params(["user_id" => "7"]);
        $response = $this->dispatchAsActor($queryFields);
        self::assertSame(400, $response->get_status());
        self::assertSame(
            "biblio_unknown_request_fields",
            $response->get_data()["code"]
        );
    }

    public function testBibliographicMaterializationRequiresDesignatedPersonalOwnership(): void
    {
        $query = BibliographicDiscoveryQuery::text(
            new BibliographicTextQuery("Unauthorized Work")
        );
        $candidate = BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalWork,
            "open_library",
            "/works/OL900W",
            "/works/OL900W",
            new DateTimeImmutable("2026-09-10T12:00:00+00:00"),
            MetadataMatchMethod::TextSearch,
            $query,
            "Unauthorized Work",
            null,
            null,
            ["Synthetic Author"],
            [],
            [],
            null,
            null,
            null,
            0
        );
        $discovery = new MetadataLookupId(
            "lookup-99999999999999999999999999999999"
        );
        (new WpdbBibliographicDiscoverySnapshotRepository(
            $this->database,
            $this->tableNames
        ))->save(new BibliographicDiscoverySnapshot(
            $discovery,
            new UserId((string) $this->actorId),
            $query,
            new DateTimeImmutable("2026-09-10T12:00:00+00:00"),
            new DateTimeImmutable("2099-09-10T12:30:00+00:00"),
            [$candidate]
        ));
        $request = new WP_REST_Request(
            "POST",
            "/biblio/v1/me/bibliographic-discoveries/"
                . $discovery->value() . "/materializations"
        );
        $request->set_header("content-type", "application/json");
        $request->set_body((string) wp_json_encode([
            "candidate_id" => $candidate->id(),
            "intent" => "work_only",
        ]));

        $response = $this->dispatchAsActor($request);

        self::assertSame(404, $response->get_status());
        self::assertSame("biblio_resource_not_available", $response->get_data()["code"]);
        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->works()}`"
        ));

        wp_set_current_user($this->actorId);
        (new ProductionComposition($this->database))->application()
            ->personalLibraries()
            ->ensure();
        $authorized = $this->dispatchAsActor($request);
        self::assertSame(201, $authorized->get_status());
        self::assertStringStartsWith(
            "work-",
            $authorized->get_data()["data"]["work_id"]
        );
        self::assertNull($authorized->get_data()["data"]["edition_id"]);
        self::assertFalse($authorized->get_data()["data"]["reused"]);
        $wishlistRequest = new WP_REST_Request("POST", "/biblio/v1/me/wishlist");
        $wishlistRequest->set_header("content-type", "application/json");
        $wishlistRequest->set_body((string) wp_json_encode([
            "target" => [
                "type" => "work_only",
                "work_id" => $authorized->get_data()["data"]["work_id"],
            ],
        ]));
        $wishlist = $this->dispatchAsActor($wishlistRequest);
        self::assertSame(201, $wishlist->get_status());
        self::assertSame(
            $authorized->get_data()["data"]["work_id"],
            $wishlist->get_data()["data"]["work_id"]
        );
        self::assertSame(1, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->wishlistEntries()}`"
        ));
        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->items()}`"
        ));
        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->libraryCatalogContexts()}`"
        ));
        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->libraryActivityEvents()}`"
        ));

        $titleOnly = BibliographicDiscoveryCandidate::external(
            BibliographicCandidateType::ExternalEdition,
            "google_books",
            "title-only-volume",
            null,
            new DateTimeImmutable("2026-09-10T12:00:00+00:00"),
            MetadataMatchMethod::TextSearch,
            $query,
            "Title Only",
            null,
            null,
            [],
            [],
            [],
            null,
            null,
            null,
            0
        );
        $titleOnlyDiscovery = new MetadataLookupId(
            "lookup-89898989898989898989898989898989"
        );
        (new WpdbBibliographicDiscoverySnapshotRepository(
            $this->database,
            $this->tableNames
        ))->save(new BibliographicDiscoverySnapshot(
            $titleOnlyDiscovery,
            new UserId((string) $this->actorId),
            $query,
            new DateTimeImmutable("2026-09-10T12:00:00+00:00"),
            new DateTimeImmutable("2099-09-10T12:30:00+00:00"),
            [$titleOnly]
        ));
        $rejectedRequest = new WP_REST_Request(
            "POST",
            "/biblio/v1/me/bibliographic-discoveries/"
                . $titleOnlyDiscovery->value() . "/materializations"
        );
        $rejectedRequest->set_header("content-type", "application/json");
        $rejectedRequest->set_body((string) wp_json_encode([
            "candidate_id" => $titleOnly->id(),
            "intent" => "work_and_edition",
        ]));
        $insufficient = $this->dispatchAsActor($rejectedRequest);
        self::assertSame(422, $insufficient->get_status());
        self::assertSame("biblio_validation_failed", $insufficient->get_data()["code"]);
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

    public function testAddBookMetadataLookupIsLocalFirstAuthorizedAndReadOnly(): void
    {
        $this->seedLibrary(
            "library-add-book",
            "Toevoegen",
            $this->actorId,
            "owner"
        );
        $this->seedLibrary(
            "library-view-only",
            "Alleen bekijken",
            $this->actorId,
            "view_only"
        );
        $this->database->insert($this->tableNames->works(), [
            "work_id" => "work-existing-isbn",
            "work_title" => "Abstracte titel",
            "work_title_status" => "provisional",
        ]);
        $this->database->insert($this->tableNames->editions(), [
            "edition_id" => "edition-existing-isbn",
            "work_id" => "work-existing-isbn",
            "edition_title" => "Concrete titel",
            "isbn_10" => "0306406152",
            "isbn_13" => "9780306406157",
            "explicitly_no_isbn" => 0,
        ]);
        $this->database->insert(
            $this->tableNames->editionIdentifierClaims(),
            [
                "canonical_isbn_13" => "9780306406157",
                "edition_id" => "edition-existing-isbn",
            ]
        );
        $before = $this->addBookPersistenceCounts();

        $response = $this->dispatchAsActor($this->metadataLookupRequest(
            "library-add-book",
            ["identifier" => "0-306-40615-2"]
        ));
        $data = $this->successData($response);

        self::assertSame(200, $response->get_status());
        self::assertSame("existing_edition", $data["status"]);
        self::assertSame("9780306406157", $data["identifier"]["isbn_13"]);
        self::assertSame("edition-existing-isbn", $data["local_matches"][0]
            ["edition_id"]);
        self::assertSame("work-existing-isbn", $data["local_matches"][0]
            ["work_id"]);
        self::assertSame([], $data["candidates"]);
        self::assertSame($before, $this->addBookPersistenceCounts());

        $providerFailure = $this->dispatchAsActor($this->metadataLookupRequest(
            "library-add-book",
            ["identifier" => "9780441172719"]
        ));
        $failureData = $this->successData($providerFailure);
        self::assertSame(200, $providerFailure->get_status());
        self::assertSame("provider_failure", $failureData["status"]);
        self::assertTrue($failureData["manual_available"]);
        self::assertTrue($failureData["retry_available"]);
        self::assertSame($before, $this->addBookPersistenceCounts());

        $viewOnly = $this->dispatchAsActor($this->metadataLookupRequest(
            "library-view-only",
            ["identifier" => "9780306406157"]
        ));
        $missing = $this->dispatchAsActor($this->metadataLookupRequest(
            "library-missing",
            ["identifier" => "9780306406157"]
        ));
        $this->assertEquivalentNotAvailable($viewOnly, $missing);

        $invalid = $this->dispatchAsActor($this->metadataLookupRequest(
            "library-add-book",
            ["identifier" => "not-an-isbn"]
        ));
        self::assertSame(422, $invalid->get_status());
        self::assertSame("biblio_validation_failed", $invalid->get_data()["code"]);
        self::assertSame($before, $this->addBookPersistenceCounts());

        foreach ([
            [],
            ["identifier" => 9780306406157],
            ["identifier" => "9780306406157", "user_id" => $this->otherId],
        ] as $body) {
            $badRequest = $this->dispatchAsActor($this->metadataLookupRequest(
                "library-add-book",
                $body
            ));
            self::assertSame(400, $badRequest->get_status());
        }
    }

    public function testExistingEditionLookupReturnsOnlyCurrentLibraryItems(): void
    {
        $this->seedLibrary("library-context-a", "A", $this->actorId, "owner");
        $this->seedLibrary("library-context-b", "B", $this->otherId, "owner");
        $this->database->insert($this->tableNames->works(), [
            "work_id" => "work-shared-context",
            "work_title" => "Shared Work",
        ]);
        $this->database->insert($this->tableNames->editions(), [
            "edition_id" => "edition-shared-context",
            "work_id" => "work-shared-context",
            "edition_title" => "Shared Edition",
            "isbn_13" => "9780306406157",
            "explicitly_no_isbn" => 0,
        ]);
        $this->database->insert($this->tableNames->authors(), [
            "author_id" => "author-shared-context",
            "display_name" => "Veilige auteur",
        ]);
        $this->database->insert($this->tableNames->workContributors(), [
            "work_id" => "work-shared-context",
            "author_id" => "author-shared-context",
            "contributor_role" => "author",
            "contributor_position" => 1,
        ]);
        $this->database->insert(
            $this->tableNames->editionIdentifierClaims(),
            [
                "canonical_isbn_13" => "9780306406157",
                "edition_id" => "edition-shared-context",
            ]
        );
        $this->database->insert($this->tableNames->locations(), [
            "library_id" => "library-context-a",
            "location_id" => "location-a",
            "display_name" => "Kast A",
        ]);
        foreach ([
            ["item-a1", "library-context-a", "INV-A1", "location-a"],
            ["item-a2", "library-context-a", "INV-A2", null],
            ["item-b1", "library-context-b", "INV-FOREIGN", null],
        ] as [$itemId, $libraryId, $inventory, $locationId]) {
            $this->database->insert($this->tableNames->items(), [
                "item_id" => $itemId,
                "library_id" => $libraryId,
                "edition_id" => "edition-shared-context",
                "item_status" => "active",
                "inventory_number" => $inventory,
                "location_id" => $locationId,
            ]);
        }

        $response = $this->dispatchAsActor($this->metadataLookupRequest(
            "library-context-a",
            ["identifier" => "9780306406157"]
        ));
        $data = $this->successData($response);
        $match = $data["local_matches"][0];

        self::assertSame(200, $response->get_status());
        self::assertSame(2, $match["existing_item_count"]);
        self::assertSame("Shared Edition", $match["edition_title"]);
        self::assertSame("9780306406157", $match["canonical_isbn"]);
        self::assertSame([[
            "author_id" => "author-shared-context",
            "display_name" => "Veilige auteur",
        ]], $match["authors"]);
        self::assertSame(
            ["item-a1", "item-a2"],
            array_column($match["existing_items"], "item_id")
        );
        self::assertSame("INV-A1", $match["existing_items"][0]
            ["inventory_number"]);
        self::assertSame("location-a", $match["existing_items"][0]
            ["location"]["location_id"]);
        self::assertSame("Kast A", $match["existing_items"][0]
            ["location"]["display_name"]);
        self::assertNull($match["existing_items"][1]["location"]);
        self::assertStringNotContainsString(
            "INV-FOREIGN",
            (string) wp_json_encode($data)
        );
    }

    public function testClassificationOptionsAreAllowlistedAndLibraryScoped(): void
    {
        $this->seedLibrary("library-options", "Opties", $this->actorId, "owner");
        $this->seedLibrary("library-options-foreign", "Verborgen", $this->otherId, "owner");
        foreach ([
            [$this->tableNames->libraryBookTypes(), "book_type_id", "book-local", "Leesboek", "active"],
            [$this->tableNames->libraryGenres(), "genre_id", "genre-local", "Roman", "active"],
            [$this->tableNames->librarySubjects(), "subject_id", "subject-local", "Geschiedenis", "active"],
            [$this->tableNames->libraryGenres(), "genre_id", "genre-inactive", "Oud", "inactive"],
        ] as [$table, $column, $id, $name, $status]) {
            self::assertSame(1, $this->database->insert($table, [
                "library_id" => "library-options",
                $column => $id,
                "display_name" => $name,
                "normalized_name" => mb_strtolower($name),
                "term_status" => $status,
                "seed_key" => $id === "book-local" ? "book_type.reading_book" : null,
            ]));
        }
        self::assertSame(1, $this->database->insert(
            $this->tableNames->libraryBookTypes(),
            [
                "library_id" => "library-options-foreign",
                "book_type_id" => "book-foreign",
                "display_name" => "Verborgen type",
                "normalized_name" => "verborgen type",
                "term_status" => "active",
            ]
        ));

        $response = $this->dispatchAsActor(new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/library-options/classification-options"
        ));
        $data = $this->successData($response);

        self::assertSame(200, $response->get_status());
        self::assertSame(
            ["library_id", "book_types", "genres", "subjects"],
            array_keys($data)
        );
        self::assertSame([[
            "book_type_id" => "book-local",
            "display_name" => "Leesboek",
        ]], $data["book_types"]);
        self::assertSame([[
            "genre_id" => "genre-local",
            "display_name" => "Roman",
        ]], $data["genres"]);
        self::assertSame([[
            "subject_id" => "subject-local",
            "display_name" => "Geschiedenis",
        ]], $data["subjects"]);
        $serialized = (string) wp_json_encode($data);
        self::assertStringNotContainsString("book-foreign", $serialized);
        self::assertStringNotContainsString("genre-inactive", $serialized);
        self::assertStringNotContainsString("seed_key", $serialized);
        self::assertStringNotContainsString("normalized_name", $serialized);

        $foreign = $this->dispatchAsActor(new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/library-options-foreign/classification-options"
        ));
        $missing = $this->dispatchAsActor(new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/library-options-missing/classification-options"
        ));
        $this->assertEquivalentNotAvailable($foreign, $missing);
    }

    public function testAddBookCommitCreatesThenReusesEditionAndRetainsDifference(): void
    {
        $this->seedLibrary("library-commit", "Commit", $this->actorId, "owner");
        $this->seedBookType("library-commit", "book-commit");

        $first = $this->dispatchAsActor($this->addBookCommitRequest(
            "library-commit",
            [
                "identifier" => "0-306-40615-2",
                "selection" => ["type" => "manual"],
                "observed_fields" => [
                    "title" => "Concrete titel",
                    "subtitle" => "Fysiek gecontroleerd",
                ],
                "classification" => [
                    "book_type_id" => "book-commit",
                    "genre_ids" => [],
                    "subject_ids" => [],
                ],
                "item" => ["inventory_number" => "INV-1"],
            ]
        ));
        $firstData = $this->successData($first);

        self::assertSame(201, $first->get_status());
        self::assertFalse($firstData["existing_edition"]);
        self::assertSame("Concrete titel", $firstData["edition_title"]);
        self::assertSame("provisional", $firstData["work_title_status"]);

        $second = $this->dispatchAsActor($this->addBookCommitRequest(
            "library-commit",
            [
                "identifier" => "9780306406157",
                "selection" => ["type" => "manual"],
                "observed_fields" => ["title" => "Afwijkende boektitel"],
                "classification" => [
                    "book_type_id" => "book-commit",
                    "genre_ids" => [],
                    "subject_ids" => [],
                ],
                "item" => ["inventory_number" => "INV-2"],
            ]
        ));
        $secondData = $this->successData($second);

        self::assertSame(201, $second->get_status());
        self::assertTrue($secondData["existing_edition"]);
        self::assertSame($firstData["edition_id"], $secondData["edition_id"]);
        self::assertSame($firstData["work_id"], $secondData["work_id"]);
        self::assertSame(1, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->editions()}`"
        ));
        self::assertSame(2, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->items()}`"
        ));
        self::assertSame("Concrete titel", $this->database->get_var(
            "SELECT edition_title FROM `{$this->tableNames->editions()}`"
        ));
        self::assertSame(1, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->metadataUserObservations()}` "
                . "WHERE correction_proposal=1"
        ));
        self::assertSame((string) $this->actorId, $this->database->get_var(
            "SELECT actor_user_id FROM `{$this->tableNames->metadataUserObservations()}` "
                . "WHERE correction_proposal=1"
        ));

        $this->seedLibrary(
            "library-commit-view-only",
            "Alleen bekijken",
            $this->actorId,
            "view_only"
        );
        $denied = $this->dispatchAsActor($this->addBookCommitRequest(
            "library-commit-view-only",
            [
                "identifier" => "9780140328721",
                "selection" => ["type" => "manual"],
                "observed_fields" => ["title" => "Denied"],
                "classification" => [
                    "book_type_id" => "not-resolved",
                    "genre_ids" => [],
                    "subject_ids" => [],
                ],
                "item" => [],
            ]
        ));
        self::assertSame(404, $denied->get_status());
        self::assertSame(2, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->items()}`"
        ));
    }

    public function testAmbiguousLocalEditionRequiresAndRevalidatesExplicitChoice(): void
    {
        $this->seedLibrary("library-ambiguous", "Ambiguous", $this->actorId, "owner");
        $this->seedBookType("library-ambiguous", "book-ambiguous");
        foreach (["a", "b"] as $suffix) {
            $this->database->insert($this->tableNames->works(), [
                "work_id" => "work-ambiguous-{$suffix}",
                "work_title" => "Work {$suffix}",
            ]);
            $this->database->insert($this->tableNames->authors(), [
                "author_id" => "author-ambiguous-{$suffix}",
                "display_name" => "Auteur {$suffix}",
            ]);
            $this->database->insert($this->tableNames->workContributors(), [
                "work_id" => "work-ambiguous-{$suffix}",
                "author_id" => "author-ambiguous-{$suffix}",
                "contributor_role" => "author",
                "contributor_position" => 1,
            ]);
        }
        $this->database->insert($this->tableNames->editions(), [
            "edition_id" => "edition-ambiguous-a",
            "work_id" => "work-ambiguous-a",
            "edition_title" => "Edition A",
            "isbn_10" => "0306406152",
            "explicitly_no_isbn" => 0,
        ]);
        $this->database->insert($this->tableNames->editions(), [
            "edition_id" => "edition-ambiguous-b",
            "work_id" => "work-ambiguous-b",
            "edition_title" => "Edition B",
            "isbn_13" => "9780306406157",
            "explicitly_no_isbn" => 0,
        ]);

        $lookup = $this->dispatchAsActor($this->metadataLookupRequest(
            "library-ambiguous",
            ["identifier" => "9780306406157"]
        ));
        $lookupData = $this->successData($lookup);
        self::assertSame("local_ambiguous", $lookupData["status"]);
        self::assertSame(
            ["Edition A", "Edition B"],
            array_column($lookupData["local_matches"], "edition_title")
        );
        self::assertSame(
            ["9780306406157", "9780306406157"],
            array_column($lookupData["local_matches"], "canonical_isbn")
        );
        self::assertSame(
            ["Auteur a", "Auteur b"],
            array_map(
                static fn (array $match): string =>
                    $match["authors"][0]["display_name"],
                $lookupData["local_matches"]
            )
        );

        $body = [
            "identifier" => "9780306406157",
            "selection" => [
                "type" => "existing_edition",
                "edition_id" => "edition-ambiguous-b",
            ],
            "observed_fields" => [],
            "classification" => [
                "book_type_id" => "book-ambiguous",
                "genre_ids" => [],
                "subject_ids" => [],
            ],
            "item" => [],
        ];
        $unselectedBody = $body;
        $unselectedBody["selection"] = ["type" => "manual"];
        $unselected = $this->dispatchAsActor($this->addBookCommitRequest(
            "library-ambiguous",
            $unselectedBody
        ));
        self::assertSame(422, $unselected->get_status());
        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->items()}`"
        ));

        $first = $this->dispatchAsActor($this->addBookCommitRequest(
            "library-ambiguous",
            $body
        ));
        $second = $this->dispatchAsActor($this->addBookCommitRequest(
            "library-ambiguous",
            $body
        ));

        self::assertSame(201, $first->get_status());
        self::assertSame(201, $second->get_status());
        self::assertSame(
            "edition-ambiguous-b",
            $this->successData($first)["edition_id"]
        );
        self::assertSame(2, (int) $this->database->get_var(
            $this->database->prepare(
                "SELECT COUNT(*) FROM `{$this->tableNames->items()}` "
                    . "WHERE library_id=%s AND edition_id=%s",
                "library-ambiguous",
                "edition-ambiguous-b"
            )
        ));
        self::assertSame(0, (int) $this->database->get_var(
            $this->database->prepare(
                "SELECT COUNT(*) FROM `{$this->tableNames->items()}` "
                    . "WHERE library_id=%s AND edition_id=%s",
                "library-ambiguous",
                "edition-ambiguous-a"
            )
        ));
        self::assertSame(2, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->editions()}`"
        ));

        $body["selection"]["edition_id"] = "edition-manipulated";
        $invalid = $this->dispatchAsActor($this->addBookCommitRequest(
            "library-ambiguous",
            $body
        ));
        self::assertSame(422, $invalid->get_status());
        self::assertSame("biblio_validation_failed", $invalid->get_data()["code"]);
        self::assertSame(2, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->items()}`"
        ));
    }

    public function testManualAddBookCanChooseExistingWorkOrCreateProvisionalWork(): void
    {
        $this->seedLibrary("library-work-choice", "Work", $this->actorId, "owner");
        $this->seedBookType("library-work-choice", "book-work-choice");
        $this->database->insert($this->tableNames->works(), [
            "work_id" => "work-explicit",
            "work_title" => "Existing central title",
            "work_title_status" => "librarian_confirmed",
        ]);
        $baseBody = [
            "identifier" => null,
            "observed_fields" => ["title" => "Concrete Edition title"],
            "classification" => [
                "book_type_id" => "book-work-choice",
                "genre_ids" => [],
                "subject_ids" => [],
            ],
            "item" => [],
        ];

        $linkedBody = $baseBody;
        $linkedBody["selection"] = [
            "type" => "manual",
            "work_id" => "work-explicit",
        ];
        $linked = $this->dispatchAsActor($this->addBookCommitRequest(
            "library-work-choice",
            $linkedBody
        ));
        $linkedData = $this->successData($linked);

        self::assertSame(201, $linked->get_status());
        self::assertSame("work-explicit", $linkedData["work_id"]);
        self::assertSame("Concrete Edition title", $linkedData["edition_title"]);
        self::assertSame("librarian_confirmed", $linkedData["work_title_status"]);
        self::assertSame("Existing central title", $this->database->get_var(
            "SELECT work_title FROM `{$this->tableNames->works()}` "
                . "WHERE work_id='work-explicit'"
        ));

        $unlinkedBody = $baseBody;
        $unlinkedBody["observed_fields"]["title"] = "Another Edition";
        $unlinkedBody["selection"] = ["type" => "manual"];
        $unlinked = $this->dispatchAsActor($this->addBookCommitRequest(
            "library-work-choice",
            $unlinkedBody
        ));
        $unlinkedData = $this->successData($unlinked);

        self::assertSame(201, $unlinked->get_status());
        self::assertNotSame("work-explicit", $unlinkedData["work_id"]);
        self::assertSame("provisional", $unlinkedData["work_title_status"]);

        $invalidBody = $baseBody;
        $invalidBody["selection"] = [
            "type" => "manual",
            "work_id" => "work-missing",
        ];
        $invalid = $this->dispatchAsActor($this->addBookCommitRequest(
            "library-work-choice",
            $invalidBody
        ));
        self::assertSame(422, $invalid->get_status());
        self::assertSame(2, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->items()}`"
        ));
    }

    public function testReviewedSnapshotCommitsWithoutProviderAndIsScopedAndExpiring(): void
    {
        $this->seedLibrary("library-snapshot", "Snapshot", $this->actorId, "owner");
        $this->seedBookType("library-snapshot", "book-snapshot");
        $identity = CanonicalIsbnIdentity::fromIsbn(new Isbn13("9780441172719"));
        $candidate = new MetadataCandidate(
            "open_library",
            "record-reviewed",
            new DateTimeImmutable("2026-09-06T10:00:00+00:00"),
            MetadataMatchMethod::ExactIsbn,
            $identity,
            $identity,
            "Dune",
            null,
            ["Frank Herbert"],
            ["eng"],
            ["Publisher"],
            "1965",
            412,
            "Hardcover",
            null
        );
        $lookupId = new MetadataLookupId(
            "lookup-11111111111111111111111111111111"
        );
        $snapshots = new WpdbMetadataLookupSnapshotRepository(
            $this->database,
            $this->tableNames
        );
        $snapshots->save(new MetadataLookupSnapshot(
            $lookupId,
            new UserId((string) $this->actorId),
            new \Biblio\Core\Library\LibraryId("library-snapshot"),
            $identity,
            new DateTimeImmutable("2026-09-06T10:00:00+00:00"),
            new DateTimeImmutable("2030-09-06T10:30:00+00:00"),
            [$candidate]
        ));

        $body = [
            "identifier" => "9780441172719",
            "selection" => [
                "type" => "candidate",
                "lookup_id" => $lookupId->value(),
                "candidate_id" => MetadataCandidateId::fromCandidate($candidate)->value(),
            ],
            "observed_fields" => [],
            "classification" => [
                "book_type_id" => "book-snapshot",
                "genre_ids" => [],
                "subject_ids" => [],
            ],
            "item" => [],
        ];
        $response = $this->dispatchAsActor(
            $this->addBookCommitRequest("library-snapshot", $body)
        );

        self::assertSame(201, $response->get_status());
        self::assertSame("Dune", $this->successData($response)["edition_title"]);
        self::assertSame(1, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->editionMetadataProvenance()}`"
        ));
        self::assertSame("accepted_unchanged", $this->database->get_var(
            "SELECT confirmation_state FROM `{$this->tableNames->editionMetadataProvenance()}`"
        ));
        self::assertGreaterThan(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->metadataFieldEvidence()}`"
        ));
        self::assertSame(2, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->metadataFieldStates()}` "
                . "WHERE metadata_record_id LIKE 'edition-evidence:%'"
        ));

        $expiredLookup = new MetadataLookupId(
            "lookup-33333333333333333333333333333333"
        );
        $snapshots->save(new MetadataLookupSnapshot(
            $expiredLookup,
            new UserId((string) $this->actorId),
            new \Biblio\Core\Library\LibraryId("library-snapshot"),
            $identity,
            new DateTimeImmutable("2020-09-06T10:00:00+00:00"),
            new DateTimeImmutable("2020-09-06T10:30:00+00:00"),
            [$candidate]
        ));
        $body["selection"]["lookup_id"] = $expiredLookup->value();
        $expired = $this->dispatchAsActor(
            $this->addBookCommitRequest("library-snapshot", $body)
        );
        self::assertSame(409, $expired->get_status());
        self::assertSame(
            "biblio_metadata_lookup_snapshot_unavailable",
            $expired->get_data()["code"]
        );

        $otherActorLookup = new MetadataLookupId(
            "lookup-44444444444444444444444444444444"
        );
        $snapshots->save(new MetadataLookupSnapshot(
            $otherActorLookup,
            new UserId((string) $this->otherId),
            new \Biblio\Core\Library\LibraryId("library-snapshot"),
            $identity,
            new DateTimeImmutable("2026-09-06T10:00:00+00:00"),
            new DateTimeImmutable("2030-09-06T10:30:00+00:00"),
            [$candidate]
        ));
        $body["selection"]["lookup_id"] = $otherActorLookup->value();
        self::assertSame(409, $this->dispatchAsActor(
            $this->addBookCommitRequest("library-snapshot", $body)
        )->get_status());

        $this->seedLibrary(
            "library-snapshot-other",
            "Andere context",
            $this->actorId,
            "owner"
        );
        $otherLibraryLookup = new MetadataLookupId(
            "lookup-55555555555555555555555555555555"
        );
        $snapshots->save(new MetadataLookupSnapshot(
            $otherLibraryLookup,
            new UserId((string) $this->actorId),
            new \Biblio\Core\Library\LibraryId("library-snapshot-other"),
            $identity,
            new DateTimeImmutable("2026-09-06T10:00:00+00:00"),
            new DateTimeImmutable("2030-09-06T10:30:00+00:00"),
            [$candidate]
        ));
        $body["selection"]["lookup_id"] = $otherLibraryLookup->value();
        self::assertSame(409, $this->dispatchAsActor(
            $this->addBookCommitRequest("library-snapshot", $body)
        )->get_status());

        $body["selection"]["lookup_id"] = $lookupId->value();
        $body["identifier"] = "9780140328721";
        self::assertSame(409, $this->dispatchAsActor(
            $this->addBookCommitRequest("library-snapshot", $body)
        )->get_status()
        );
    }

    public function testAddBookEvidenceFailureRollsBackEntireCatalogMutation(): void
    {
        $this->seedLibrary("library-rollback", "Rollback", $this->actorId, "owner");
        $this->seedBookType("library-rollback", "book-rollback");
        $trigger = $this->database->prefix . "biblio_mh_b5b_observation_fail";
        $observations = $this->tableNames->metadataUserObservations();
        self::assertNotFalse($this->database->query(
            "CREATE TRIGGER `{$trigger}` BEFORE INSERT ON `{$observations}` "
                . "FOR EACH ROW SIGNAL SQLSTATE '45000' "
                . "SET MESSAGE_TEXT='forced MH-B5B evidence failure'"
        ));

        try {
            $response = $this->dispatchAsActor($this->addBookCommitRequest(
                "library-rollback",
                [
                    "identifier" => "9780306406157",
                    "selection" => ["type" => "manual"],
                    "observed_fields" => ["title" => "Rollback title"],
                    "classification" => [
                        "book_type_id" => "book-rollback",
                        "genre_ids" => [],
                        "subject_ids" => [],
                    ],
                    "item" => [],
                ]
            ));

            self::assertSame(500, $response->get_status());
            foreach ([
                $this->tableNames->works(),
                $this->tableNames->editions(),
                $this->tableNames->items(),
                $this->tableNames->editionIdentifierClaims(),
                $this->tableNames->metadataFieldStates(),
                $this->tableNames->metadataFieldValues(),
                $this->tableNames->metadataUserObservations(),
                $this->tableNames->libraryCatalogContexts(),
                $this->tableNames->libraryActivityEvents(),
            ] as $table) {
                self::assertSame(0, (int) $this->database->get_var(
                    "SELECT COUNT(*) FROM `{$table}`"
                ), $table);
            }
        } finally {
            $this->database->query("DROP TRIGGER IF EXISTS `{$trigger}`");
        }
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
            "read_date_known",
            "item_status",
            "capabilities",
        ], array_keys($firstData["items"][0]));
        self::assertSame(
            ["view_item", "start_reading"],
            array_keys($firstData["items"][0]["capabilities"])
        );
        self::assertArrayNotHasKey(
            "active_reading_round",
            $firstData["items"][0]
        );
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

    public function testCatalogQueryRouteIsTypedPaginatedPrivateAndNonEnumerating(): void
    {
        $this->seedLibrary("library-own", "Mijn bibliotheek", $this->actorId, "owner");
        $this->seedLibrary("library-second", "Tweede bibliotheek", $this->actorId, "owner");
        $this->seedLibrary("library-other", "Andere bibliotheek", $this->otherId, "owner");
        $this->database->insert($this->tableNames->locations(), [
            "library_id" => "library-other",
            "location_id" => "location-foreign",
            "display_name" => "Verborgen locatie",
        ]);
        $this->seedItem("item-alpha", "library-own", "work-alpha", "Alpha");
        $this->seedItem("item-beta", "library-own", "work-beta", "Béta");
        $this->seedItem("item-archived", "library-own", "work-archived", "Archief");
        $this->database->insert($this->tableNames->memberships(), [
            "library_id" => "library-own",
            "user_id" => (string) $this->otherId,
            "membership_status" => "active",
            "management_role" => "member",
            "use_access" => "view_only",
            "additional_permissions" => "[]",
        ]);
        $this->seedRound(
            "round-actor-read",
            $this->actorId,
            "work-alpha",
            "item-alpha",
            outcome: "completed"
        );
        $this->seedRound(
            "round-other-reading",
            $this->otherId,
            "work-beta",
            "item-beta"
        );
        self::assertSame(1, $this->database->update(
            $this->tableNames->items(),
            ["item_status" => "archived"],
            ["item_id" => "item-archived"]
        ));

        $first = $this->dispatchAsActor($this->catalogRequest("library-own", [
            "page_size" => "1",
        ]));
        $firstData = $this->successData($first);
        self::assertSame(200, $first->get_status());
        self::assertSame(["library", "items", "next_cursor"], array_keys($firstData));
        self::assertSame(["item-alpha"], array_column($firstData["items"], "item_id"));
        self::assertNotNull($firstData["next_cursor"]);
        self::assertSame([
            "item_id", "work_id", "edition_id", "title", "item_status",
            "inventory_number", "authors", "series", "location",
            "classification", "collection_ids", "reading_status",
            "read_date_known",
            "contained_match_title",
        ], array_keys($firstData["items"][0]));
        self::assertArrayNotHasKey("user_id", $firstData["items"][0]);
        self::assertArrayNotHasKey("notes", $firstData["items"][0]);
        self::assertArrayNotHasKey("version", $firstData["items"][0]);
        self::assertSame("read", $firstData["items"][0]["reading_status"]);

        $next = $this->successData($this->dispatchAsActor(
            $this->catalogRequest("library-own", [
                "page_size" => "1",
                "cursor" => $firstData["next_cursor"],
            ])
        ));
        self::assertSame(["item-beta"], array_column($next["items"], "item_id"));
        self::assertSame("not_read", $next["items"][0]["reading_status"]);
        self::assertNull($next["next_cursor"]);

        $otherActor = $this->successData($this->dispatchAsUser(
            $this->catalogRequest("library-own", ["search" => "Beta"]),
            $this->otherId
        ));
        self::assertSame("reading", $otherActor["items"][0]["reading_status"]);

        $search = $this->successData($this->dispatchAsActor(
            $this->catalogRequest("library-own", ["search" => "Beta"])
        ));
        self::assertSame(["item-beta"], array_column($search["items"], "item_id"));

        $mixed = $this->successData($this->dispatchAsActor(
            $this->catalogRequest("library-own", [
                "archive_scope" => "active_and_archived",
            ])
        ));
        self::assertContains("item-archived", array_column($mixed["items"], "item_id"));
        self::assertSame(
            "archived",
            $mixed["items"][array_search(
                "item-archived",
                array_column($mixed["items"], "item_id"),
                true
            )]["item_status"]
        );

        $empty = $this->dispatchAsActor($this->catalogRequest("library-own", [
            "author_ids" => ["author-unknown"],
        ]));
        self::assertSame(200, $empty->get_status());
        self::assertSame([], $this->successData($empty)["items"]);
        self::assertNull($this->successData($empty)["next_cursor"]);

        $foreignFilter = $this->dispatchAsActor($this->catalogRequest(
            "library-own",
            ["location_ids" => ["location-foreign"]]
        ));
        $unknownFilter = $this->dispatchAsActor($this->catalogRequest(
            "library-own",
            ["location_ids" => ["location-unknown"]]
        ));
        self::assertSame(200, $foreignFilter->get_status());
        self::assertSame(
            $this->successData($unknownFilter),
            $this->successData($foreignFilter)
        );

        $foreign = $this->dispatchAsActor($this->catalogRequest("library-other"));
        $missing = $this->dispatchAsActor($this->catalogRequest("library-missing"));
        $this->assertEquivalentNotAvailable($foreign, $missing);

        $anonymous = $this->dispatchAsUser(
            $this->catalogRequest("library-own"),
            0,
            null
        );
        self::assertSame(401, $anonymous->get_status());
        self::assertSame("biblio_authentication_required", $anonymous->get_data()["code"]);

        $badNonce = $this->dispatchAsActor(
            $this->catalogRequest("library-own"),
            "invalid"
        );
        self::assertSame(403, $badNonce->get_status());
        self::assertSame("rest_cookie_invalid_nonce", $badNonce->get_data()["code"]);

        $changedQueryCursor = $this->dispatchAsActor($this->catalogRequest(
            "library-own",
            [
                "page_size" => "1",
                "sort" => "author",
                "cursor" => $firstData["next_cursor"],
            ]
        ));
        self::assertSame(422, $changedQueryCursor->get_status());
        self::assertSame(
            "biblio_validation_failed",
            $changedQueryCursor->get_data()["code"]
        );

        $otherLibraryCursor = $this->dispatchAsActor($this->catalogRequest(
            "library-second",
            [
                "page_size" => "1",
                "cursor" => $firstData["next_cursor"],
            ]
        ));
        self::assertSame(422, $otherLibraryCursor->get_status());

        $otherActorCursor = $this->dispatchAsUser(
            $this->catalogRequest("library-own", [
                "page_size" => "1",
                "cursor" => $firstData["next_cursor"],
            ]),
            $this->otherId
        );
        self::assertSame(422, $otherActorCursor->get_status());

        $changedFilterCursor = $this->dispatchAsActor($this->catalogRequest(
            "library-own",
            [
                "page_size" => "1",
                "author_ids" => ["author-unknown"],
                "cursor" => $firstData["next_cursor"],
            ]
        ));
        self::assertSame(422, $changedFilterCursor->get_status());
    }

    public function testCatalogQueryRouteAcceptsOnlyTheCanonicalTransportSurface(): void
    {
        $this->seedLibrary("library-own", "Mijn bibliotheek", $this->actorId, "owner");
        $this->seedItem("item-alpha", "library-own", "work-alpha", "Alpha");

        foreach ([
            ["reading_statuses" => ["not_read"]],
            ["author_ids" => ["author-unknown"]],
            ["series_ids" => ["series-unknown"]],
            ["location_ids" => ["location-unknown"]],
            ["book_type_ids" => ["book-unknown"]],
            ["genre_ids" => ["genre-unknown"]],
            ["subject_ids" => ["subject-unknown"]],
            ["collection_ids" => ["collection-unknown"]],
            ["without_collection" => "true"],
            ["sort" => "title"],
            ["sort" => "author"],
            ["sort" => "series", "series_ids" => ["series-unknown"]],
            ["page_size" => "1"],
            ["page_size" => "100"],
            ["archive_scope" => "active_only"],
        ] as $parameters) {
            self::assertSame(
                200,
                $this->dispatchAsActor(
                    $this->catalogRequest("library-own", $parameters)
                )->get_status(),
                (string) wp_json_encode($parameters)
            );
        }

        foreach ([
            ["user_id" => (string) $this->otherId],
            ["search" => ""],
            ["author_ids" => "author-a"],
            ["collection_ids" => ["collection-a"], "without_collection" => "true"],
            ["sort" => "newest"],
            ["sort" => "series"],
            ["page_size" => "0"],
            ["page_size" => "101"],
            ["page_size" => "1.0"],
            ["archive_scope" => "archived_only"],
            ["cursor" => "not-a-valid-cursor"],
        ] as $parameters) {
            $response = $this->dispatchAsActor(
                $this->catalogRequest("library-own", $parameters)
            );
            self::assertContains($response->get_status(), [400, 422]);
            self::assertNotSame("biblio_internal_error", $response->get_data()["code"]);
            self::assertStringNotContainsString(
                "not-a-valid-cursor",
                (string) wp_json_encode($response->get_data())
            );
        }
    }

    public function testCatalogTransportReportsBoundedWordPressAndCoreQueryCounts(): void
    {
        $this->seedLibrary("library-query-count", "Query count", $this->actorId, "owner");
        $this->seedRichCatalogItem(
            "library-query-count",
            "item-rich-a",
            "work-rich-a",
            "Alpha rich",
            "1"
        );
        $this->seedRichCatalogItem(
            "library-query-count",
            "item-rich-b",
            "work-rich-b",
            "Beta rich",
            "2"
        );

        $authenticationBefore = $this->database->num_queries;
        wp_set_current_user($this->actorId);
        global $wp_rest_auth_cookie;
        $wp_rest_auth_cookie = true;
        unset($_SERVER["HTTP_X_WP_NONCE"]);
        $_SERVER["HTTP_X_WP_NONCE"] = wp_create_nonce("wp_rest");
        self::assertTrue(rest_cookie_check_errors(null));
        self::assertSame(2, $this->database->num_queries - $authenticationBefore);

        $before = $this->database->num_queries;
        $first = $this->server->dispatch($this->catalogRequest(
            "library-query-count",
            ["page_size" => "1"]
        ));
        $firstCalls = $this->database->num_queries - $before;
        $firstData = $this->successData($first);

        self::assertSame(200, $first->get_status());
        self::assertSame(16, $firstCalls);
        self::assertSame("author-work-rich-a", $firstData["items"][0]["authors"][0]["author_id"]);
        self::assertSame("series-work-rich-a", $firstData["items"][0]["series"][0]["series_id"]);
        self::assertSame("location-query-count", $firstData["items"][0]["location"]["location_id"]);
        self::assertSame("book-query-count", $firstData["items"][0]["classification"]["book_type_id"]);
        self::assertSame(["collection-query-count"], $firstData["items"][0]["collection_ids"]);

        $before = $this->database->num_queries;
        $next = $this->server->dispatch($this->catalogRequest(
            "library-query-count",
            [
                "page_size" => "1",
                "cursor" => $firstData["next_cursor"],
            ]
        ));
        $nextCalls = $this->database->num_queries - $before;

        self::assertSame(200, $next->get_status());
        self::assertSame(17, $nextCalls);
        self::assertSame(["item-rich-b"], array_column(
            $this->successData($next)["items"],
            "item_id"
        ));
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
        self::assertSame([
            "book_types" => [],
            "genres" => [],
            "subjects" => [],
        ], $detail["classification"]);
        self::assertSame([], $detail["collections"]);
        self::assertSame([
            "contributions" => [],
            "aggregate" => ["average" => null, "voter_count" => 0],
            "next_cursor" => null,
            "own_not_visible" => [],
        ], $detail["assessments"]);
        self::assertSame("not_read", $detail["reading"]["status"]);
        self::assertNull($detail["active_reading_round"]);
        self::assertFalse($detail["capabilities"]["end_reading"]);
        self::assertTrue($detail["capabilities"]["start_reading"]);
        self::assertArrayNotHasKey("created_at", $detail);
        self::assertArrayNotHasKey("active_round_user_ids", $detail["reading"]);
        self::assertArrayNotHasKey("reading_history", $detail);

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

    public function testDetailProjectsOnlyAssignedClassificationFromItsLibrary(): void
    {
        $this->seedLibrary("detail-class-a", "Bibliotheek A", $this->actorId, "owner");
        $this->seedLibrary("detail-class-b", "Bibliotheek B", $this->actorId, "owner");
        $this->seedItem("detail-class-item-a", "detail-class-a", "detail-shared-work", "Gedeeld boek");
        $this->database->insert($this->tableNames->items(), [
            "item_id" => "detail-class-item-b",
            "library_id" => "detail-class-b",
            "edition_id" => "edition-detail-class-item-a",
            "item_status" => "active",
        ]);
        $this->seedClassificationAssignment(
            "detail-class-a",
            "detail-shared-work",
            ["type-a", "Leesboek", "leesboek", "inactive"],
            [
                ["genre-z", "Thriller", "thriller", "active"],
                ["genre-a", "Historisch", "historisch", "inactive"],
            ],
            [["subject-a", "Tweede Wereldoorlog", "tweede wereldoorlog", "active"]]
        );
        $this->seedClassificationAssignment(
            "detail-class-b",
            "detail-shared-work",
            ["type-b", "Naslagwerk", "naslagwerk", "active"],
            [["genre-b", "Wetenschap", "wetenschap", "active"]],
            []
        );

        $detailA = $this->successData($this->dispatchAsActor(new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/detail-class-a/items/detail-class-item-a"
        )));
        $detailB = $this->successData($this->dispatchAsActor(new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/detail-class-b/items/detail-class-item-b"
        )));

        self::assertSame([
            "book_types" => [[
                "book_type_id" => "type-a",
                "display_name" => "Leesboek",
            ]],
            "genres" => [[
                "genre_id" => "genre-a",
                "display_name" => "Historisch",
            ], [
                "genre_id" => "genre-z",
                "display_name" => "Thriller",
            ]],
            "subjects" => [[
                "subject_id" => "subject-a",
                "display_name" => "Tweede Wereldoorlog",
            ]],
        ], $detailA["classification"]);
        self::assertSame("type-b", $detailB["classification"]["book_types"][0]["book_type_id"]);
        self::assertSame("Wetenschap", $detailB["classification"]["genres"][0]["display_name"]);
        self::assertNotContains(
            "Naslagwerk",
            array_column($detailA["classification"]["book_types"], "display_name")
        );
        self::assertArrayNotHasKey("term_status", $detailA["classification"]["book_types"][0]);
        self::assertArrayNotHasKey("normalized_name", $detailA["classification"]["genres"][0]);
    }

    public function testDetailProjectsOnlyActiveCollectionsForExactItemAndLibrary(): void
    {
        $this->seedLibrary("detail-collections-a", "Collecties A", $this->actorId, "owner");
        $this->seedLibrary("detail-collections-b", "Collecties B", $this->actorId, "owner");
        $this->seedItem("detail-collection-item-a", "detail-collections-a", "detail-collection-work", "Gedeeld boek");
        $this->database->insert($this->tableNames->items(), [
            "item_id" => "detail-collection-sibling",
            "library_id" => "detail-collections-a",
            "edition_id" => "edition-detail-collection-item-a",
            "item_status" => "active",
        ]);
        $this->database->insert($this->tableNames->items(), [
            "item_id" => "detail-collection-item-b",
            "library_id" => "detail-collections-b",
            "edition_id" => "edition-detail-collection-item-a",
            "item_status" => "active",
        ]);
        $this->seedDetailCollection("detail-collections-a", "detail-collection-second", "Tweede", 2);
        $this->seedDetailCollection("detail-collections-a", "detail-collection-first", "Eerste", 1);
        $this->seedDetailCollection("detail-collections-a", "detail-collection-sibling-only", "Ander exemplaar", 3);
        $this->seedDetailCollection("detail-collections-a", "detail-collection-archived", "Archief", 4, "archived");
        $this->seedDetailCollection("detail-collections-b", "detail-collection-foreign", "Andere Library", 1);
        $this->seedDetailCollectionMembership("detail-collections-a", "detail-membership-second", "detail-collection-second", "detail-collection-item-a", 1);
        $this->seedDetailCollectionMembership("detail-collections-a", "detail-membership-first", "detail-collection-first", "detail-collection-item-a", 1);
        $this->seedDetailCollectionMembership("detail-collections-a", "detail-membership-sibling", "detail-collection-sibling-only", "detail-collection-sibling", 1);
        $this->seedDetailCollectionMembership("detail-collections-a", "detail-membership-archived", "detail-collection-archived", "detail-collection-item-a", 1);
        $this->seedDetailCollectionMembership("detail-collections-b", "detail-membership-foreign", "detail-collection-foreign", "detail-collection-item-b", 1);

        $detail = $this->successData($this->dispatchAsActor(new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/detail-collections-a/items/detail-collection-item-a"
        )));

        self::assertSame([
            ["collection_id" => "detail-collection-first", "display_name" => "Eerste"],
            ["collection_id" => "detail-collection-second", "display_name" => "Tweede"],
        ], $detail["collections"]);
        self::assertSame(
            ["collection_id", "display_name"],
            array_keys($detail["collections"][0])
        );
        self::assertStringNotContainsString(
            "detail-collection-foreign",
            (string) wp_json_encode($detail)
        );
        self::assertStringNotContainsString(
            "detail-collection-sibling-only",
            (string) wp_json_encode($detail)
        );
        self::assertStringNotContainsString(
            "detail-collection-archived",
            (string) wp_json_encode($detail)
        );
    }

    public function testReadingHistoryRequiresCookieNonceAndReturnsEmptyWithoutOracle(): void
    {
        $request = $this->historyRequest("history-unknown-work");

        $anonymous = $this->server->dispatch($request);
        self::assertSame(401, $anonymous->get_status());
        self::assertSame(
            "biblio_authentication_required",
            $anonymous->get_data()["code"]
        );

        $missingNonce = $this->dispatchAsActor($request, null);
        self::assertSame(401, $missingNonce->get_status());
        self::assertSame(
            "biblio_authentication_required",
            $missingNonce->get_data()["code"]
        );

        $invalidNonce = $this->dispatchAsActor($request, "invalid");
        self::assertSame(403, $invalidNonce->get_status());
        self::assertSame(
            "rest_cookie_invalid_nonce",
            $invalidNonce->get_data()["code"]
        );

        $unknown = $this->dispatchAsActor($request);
        self::assertSame(200, $unknown->get_status());
        self::assertSame(
            ["items" => [], "next_cursor" => null],
            $this->successData($unknown)
        );
    }

    public function testReadingHistoryIsOwnerScopedAllowlistedAndPrecisionAware(): void
    {
        $this->seedLibrary(
            "history-library",
            "History",
            $this->actorId,
            "owner"
        );
        $this->seedItem(
            "history-item",
            "history-library",
            "history-work",
            "History Work"
        );
        $this->seedExternalLoan(
            "history-loan",
            $this->actorId,
            "history-work"
        );
        $this->seedHistoryRound(
            "history-round-item",
            $this->actorId,
            "history-work",
            "completed",
            "source_started",
            [2026, 8, 1],
            [2026, 8, 29],
            itemId: "history-item"
        );
        $this->seedHistoryRound(
            "history-round-loan",
            $this->actorId,
            "history-work",
            "stopped",
            "source_started",
            [2026, 7, 1],
            [2026, 7, null],
            externalLoanId: "history-loan"
        );
        $this->seedHistoryRound(
            "history-round-manual",
            $this->actorId,
            "history-work",
            "completed",
            "historical_manual",
            [2025, null, null],
            [2025, null, null]
        );
        $this->seedHistoryRound(
            "history-round-legacy",
            $this->actorId,
            "history-work",
            "stopped",
            "legacy_source_started",
            null,
            [2024, 2, 3]
        );
        $this->seedHistoryRound(
            "history-round-foreign-same-work",
            $this->otherId,
            "history-work",
            "completed",
            "historical_manual",
            null,
            [2026, 8, 30]
        );

        $this->seedLibrary(
            "history-private-library",
            "Foreign history",
            $this->otherId,
            "owner"
        );
        $this->database->insert($this->tableNames->memberships(), [
            "library_id" => "history-private-library",
            "user_id" => (string) $this->actorId,
            "membership_status" => "active",
            "management_role" => "manager",
            "use_access" => "direct",
            "additional_permissions" => "[]",
        ]);
        $this->seedItem(
            "history-private-item",
            "history-private-library",
            "history-private-work",
            "Foreign Work"
        );
        $this->seedHistoryRound(
            "history-round-private",
            $this->otherId,
            "history-private-work",
            "completed",
            "source_started",
            [2026, 1, 1],
            [2026, 1, 2],
            itemId: "history-private-item"
        );

        $roundCount = (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->readingRounds()}`"
        );
        $eventCount = (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->libraryActivityEvents()}`"
        );
        $response = $this->dispatchAsActor(
            $this->historyRequest("history-work")
        );
        $data = $this->successData($response);

        self::assertSame(200, $response->get_status());
        self::assertSame(["items", "next_cursor"], array_keys($data));
        self::assertNull($data["next_cursor"]);
        self::assertCount(4, $data["items"]);
        self::assertSame([
            "outcome",
            "started_on",
            "finished_on",
            "source_type",
            "historical_registration",
        ], array_keys($data["items"][0]));
        self::assertSame([
            "outcome" => "completed",
            "started_on" => ["year" => 2026, "month" => 8, "day" => 1],
            "finished_on" => ["year" => 2026, "month" => 8, "day" => 29],
            "source_type" => "library_item",
            "historical_registration" => false,
        ], $data["items"][0]);
        self::assertSame("external_loan", $data["items"][1]["source_type"]);
        self::assertSame(
            ["year" => 2026, "month" => 7, "day" => null],
            $data["items"][1]["finished_on"]
        );
        self::assertSame(
            ["year" => 2025, "month" => null, "day" => null],
            $data["items"][2]["started_on"]
        );
        self::assertTrue($data["items"][2]["historical_registration"]);
        self::assertNull($data["items"][3]["started_on"]);
        self::assertSame("unknown", $data["items"][3]["source_type"]);

        foreach ($data["items"] as $item) {
            foreach ([
                "user_id",
                "library_id",
                "work_id",
                "item_id",
                "edition_id",
                "external_loan_id",
                "reading_round_id",
                "version",
                "provenance",
                "created_at",
                "updated_at",
                "ended_at",
            ] as $forbidden) {
                self::assertArrayNotHasKey($forbidden, $item);
            }
        }

        self::assertSame(
            ["items" => [], "next_cursor" => null],
            $this->successData($this->dispatchAsActor(
                $this->historyRequest("history-private-work")
            ))
        );
        self::assertSame(
            $roundCount,
            (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$this->tableNames->readingRounds()}`"
            )
        );
        self::assertSame(
            $eventCount,
            (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$this->tableNames->libraryActivityEvents()}`"
            )
        );
    }

    public function testReadingHistoryCursorRoundTripRescopesAndRejectsInvalidInput(): void
    {
        $this->seedWork("history-page-work", "Paged History");
        $this->seedWork("history-empty-work", "Empty History");

        for ($day = 1; $day <= 12; ++$day) {
            $this->seedHistoryRound(
                sprintf("history-page-%02d", $day),
                $this->actorId,
                "history-page-work",
                $day % 2 === 0 ? "completed" : "stopped",
                "historical_manual",
                [2026, 1, $day],
                [2026, 8, 29]
            );
        }
        $this->seedHistoryRound(
            "history-page-00-other-actor",
            $this->otherId,
            "history-page-work",
            "completed",
            "historical_manual",
            [2025, 12, 31],
            [2026, 8, 29]
        );

        $default = $this->successData($this->dispatchAsActor(
            $this->historyRequest("history-page-work")
        ));
        self::assertCount(10, $default["items"]);
        self::assertNotNull($default["next_cursor"]);

        $maximum = $this->successData($this->dispatchAsActor(
            $this->historyRequest("history-page-work", ["limit" => 50])
        ));
        self::assertCount(12, $maximum["items"]);
        self::assertNull($maximum["next_cursor"]);

        $exact = $this->successData($this->dispatchAsActor(
            $this->historyRequest("history-page-work", ["limit" => 12])
        ));
        self::assertCount(12, $exact["items"]);
        self::assertNull($exact["next_cursor"]);

        $seenDays = [];
        $cursor = null;

        do {
            $request = $this->historyRequest("history-page-work", [
                "limit" => "5",
                ...($cursor === null ? [] : ["cursor" => $cursor]),
            ]);
            $page = $this->successData($this->dispatchAsActor($request));
            $seenDays = [
                ...$seenDays,
                ...array_map(
                    static fn (array $item): int =>
                        $item["started_on"]["day"],
                    $page["items"]
                ),
            ];
            $cursor = $page["next_cursor"];
        } while ($cursor !== null);

        self::assertSame(range(12, 1), $seenDays);
        self::assertCount(12, array_unique($seenDays));

        $first = $this->successData($this->dispatchAsActor(
            $this->historyRequest("history-page-work", ["limit" => 5])
        ));
        self::assertIsString($first["next_cursor"]);
        self::assertStringNotContainsString(
            "history-page-",
            $first["next_cursor"]
        );
        self::assertSame(
            ["items" => [], "next_cursor" => null],
            $this->successData($this->dispatchAsActor($this->historyRequest(
                "history-empty-work",
                ["limit" => 5, "cursor" => $first["next_cursor"]]
            )))
        );
        $otherActorPage = $this->successData($this->dispatchAsUser(
            $this->historyRequest("history-page-work", [
                "limit" => 5,
                "cursor" => $first["next_cursor"],
            ]),
            $this->otherId
        ));
        self::assertCount(1, $otherActorPage["items"]);
        self::assertSame(31, $otherActorPage["items"][0]["started_on"]["day"]);

        foreach ([
            ["limit" => "0"],
            ["limit" => "51"],
            ["limit" => "1.5"],
            ["limit" => []],
            ["cursor" => "***"],
            ["cursor" => rtrim(strtr(base64_encode('{"v":2}'), "+/", "-_"), "=")],
            ["user_id" => (string) $this->otherId],
        ] as $query) {
            $invalid = $this->dispatchAsActor(
                $this->historyRequest("history-page-work", $query)
            );
            self::assertSame(400, $invalid->get_status());
        }

        $malformedWork = $this->dispatchAsActor(
            $this->historyRequest(str_repeat("w", 192))
        );
        self::assertSame(400, $malformedWork->get_status());
        self::assertSame(
            "biblio_invalid_field_syntax",
            $malformedWork->get_data()["code"]
        );
    }

    public function testDetailSerializesOnlyActorOwnedExactItemActiveRound(): void
    {
        $this->seedLibrary(
            "library-source-exact",
            "Bronexact",
            $this->actorId,
            "manager"
        );
        $this->seedItem(
            "item-source-a",
            "library-source-exact",
            "work-source",
            "Zelfde Work"
        );
        $this->seedItem(
            "item-source-b",
            "library-source-exact",
            "work-source",
            "Zelfde Work"
        );
        $this->seedRound(
            "round-source-a",
            $this->actorId,
            "work-source",
            "item-source-a",
            null,
            null,
            5
        );
        $this->seedRound(
            "round-foreign-b",
            $this->otherId,
            "work-source",
            "item-source-b"
        );
        $this->seedRound(
            "round-ended-b",
            $this->actorId,
            "work-source",
            "item-source-b",
            null,
            "completed"
        );
        $this->seedExternalLoan("loan-source", $this->actorId, "work-source");
        $this->seedRound(
            "round-external",
            $this->actorId,
            "work-source",
            null,
            "loan-source"
        );

        $itemARequest = new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/library-source-exact/items/item-source-a"
        );
        $itemARequest->set_query_params(["user_id" => (string) $this->otherId]);
        $itemA = $this->successData($this->dispatchAsActor($itemARequest));

        self::assertSame([
            "library",
            "item_id",
            "work_id",
            "edition_id",
            "title",
            "authors",
            "cover_reference",
            "isbn",
            "language",
            "publisher",
            "publication_date",
            "series",
            "form",
            "location",
            "condition",
            "acquisition",
            "availability",
            "classification",
            "collections",
            "assessments",
            "item_status",
            "reading",
            "active_reading_round",
            "capabilities",
        ], array_keys($itemA));
        self::assertSame([
            "reading_round_id",
            "version",
            "started_on",
        ], array_keys($itemA["active_reading_round"]));
        self::assertSame("round-source-a", $itemA["active_reading_round"]["reading_round_id"]);
        self::assertSame(5, $itemA["active_reading_round"]["version"]);
        self::assertSame([
            "year" => 2026,
            "month" => 8,
            "day" => 1,
        ], $itemA["active_reading_round"]["started_on"]);
        self::assertSame([
            "view_item" => true,
            "start_reading" => false,
            "end_reading" => true,
        ], $itemA["capabilities"]);
        self::assertArrayNotHasKey("user_id", $itemA["active_reading_round"]);
        self::assertArrayNotHasKey("provenance", $itemA["active_reading_round"]);
        self::assertArrayNotHasKey("created_at", $itemA["active_reading_round"]);
        self::assertArrayNotHasKey("updated_at", $itemA["active_reading_round"]);
        self::assertArrayNotHasKey("ended_at", $itemA["active_reading_round"]);
        self::assertArrayNotHasKey("source", $itemA["active_reading_round"]);

        $itemB = $this->successData($this->dispatchAsActor(new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/library-source-exact/items/item-source-b"
        )));
        self::assertNull($itemB["active_reading_round"]);
        self::assertFalse($itemB["capabilities"]["end_reading"]);
        self::assertTrue($itemB["capabilities"]["start_reading"]);
        self::assertSame("reading", $itemB["reading"]["status"]);
        self::assertSame(2, $itemB["reading"]["active_rounds"]);
        self::assertSame(1, $itemB["reading"]["completed_rounds"]);
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

    public function testEndReadingRequiresAuthenticationAndWordPressNonce(): void
    {
        $request = $this->endRequest("round-auth", [
            "outcome" => "completed",
            "finished_on" => "2026-08-29",
            "expected_version" => 1,
        ]);

        $anonymous = $this->server->dispatch($request);
        self::assertSame(401, $anonymous->get_status());
        self::assertSame(
            "biblio_authentication_required",
            $anonymous->get_data()["code"]
        );

        $missingNonce = $this->dispatchAsActor($request, null);
        self::assertSame(401, $missingNonce->get_status());
        self::assertSame(
            "biblio_authentication_required",
            $missingNonce->get_data()["code"]
        );

        $invalidNonce = $this->dispatchAsActor($request, "invalid");
        self::assertSame(403, $invalidNonce->get_status());
        self::assertSame(
            "rest_cookie_invalid_nonce",
            $invalidNonce->get_data()["code"]
        );
    }

    public function testOwnerCanCompleteAndStopWithMinimalEndedResponse(): void
    {
        $this->seedLibrary("library-end", "Eindigen", $this->actorId, "owner");
        $this->seedItem("item-complete", "library-end", "work-complete", "Compleet");
        $this->seedItem("item-stop", "library-end", "work-stop", "Gestopt");
        $this->seedRound(
            "round-complete",
            $this->actorId,
            "work-complete",
            "item-complete"
        );
        $this->seedRound(
            "round-stop",
            $this->actorId,
            "work-stop",
            "item-stop"
        );

        $completedResponse = $this->dispatchAsActor($this->endRequest(
            "round-complete",
            [
                "outcome" => "completed",
                "finished_on" => "2026-08-29",
                "expected_version" => 1,
            ]
        ));
        $stoppedResponse = $this->dispatchAsActor($this->endRequest(
            "round-stop",
            [
                "outcome" => "stopped",
                "finished_on" => "2026-08-28",
                "expected_version" => 1,
            ]
        ));

        self::assertSame(200, $completedResponse->get_status());
        self::assertSame([
            "reading_round_id" => "round-complete",
            "lifecycle" => "ended",
            "outcome" => "completed",
            "finished_on" => ["year" => 2026, "month" => 8, "day" => 29],
            "version" => 2,
        ], $this->successData($completedResponse));
        self::assertSame(200, $stoppedResponse->get_status());
        self::assertSame([
            "reading_round_id" => "round-stop",
            "lifecycle" => "ended",
            "outcome" => "stopped",
            "finished_on" => ["year" => 2026, "month" => 8, "day" => 28],
            "version" => 2,
        ], $this->successData($stoppedResponse));

        self::assertSame([
            "round-complete" => ["round_outcome" => "completed", "round_version" => "2"],
            "round-stop" => ["round_outcome" => "stopped", "round_version" => "2"],
        ], $this->storedEndTruth("round-complete", "round-stop"));
        $completedTruth = $this->storedRound("round-complete");
        self::assertSame("2026", $completedTruth["reading_finished_year"]);
        self::assertSame("8", $completedTruth["reading_finished_month"]);
        self::assertSame("29", $completedTruth["reading_finished_day"]);
        $stoppedTruth = $this->storedRound("round-stop");
        self::assertSame("2026", $stoppedTruth["reading_finished_year"]);
        self::assertSame("8", $stoppedTruth["reading_finished_month"]);
        self::assertSame("28", $stoppedTruth["reading_finished_day"]);
        self::assertSame(
            0,
            (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$this->tableNames->libraryActivityEvents()}`"
            )
        );
    }

    public function testIdenticalCompletedAndStoppedRetriesAreSuccessfulNoOps(): void
    {
        $this->seedLibrary("library-retry", "Retry", $this->actorId, "owner");
        $this->seedItem("item-retry-complete", "library-retry", "work-retry-complete", "Compleet");
        $this->seedItem("item-retry-stop", "library-retry", "work-retry-stop", "Gestopt");
        $this->seedRound(
            "round-retry-complete",
            $this->actorId,
            "work-retry-complete",
            "item-retry-complete"
        );
        $this->seedRound(
            "round-retry-stop",
            $this->actorId,
            "work-retry-stop",
            "item-retry-stop"
        );

        foreach ([
            ["round-retry-complete", "completed", "2026-08-29"],
            ["round-retry-stop", "stopped", "2026-08-28"],
        ] as [$roundId, $outcome, $finishedOn]) {
            $request = $this->endRequest($roundId, [
                "outcome" => $outcome,
                "finished_on" => $finishedOn,
                "expected_version" => 1,
            ]);
            $first = $this->dispatchAsActor($request);
            $truthAfterFirst = $this->storedRound($roundId);
            $retry = $this->dispatchAsActor($request);

            self::assertSame(200, $first->get_status());
            self::assertSame(200, $retry->get_status());
            self::assertSame(2, $this->successData($first)["version"]);
            self::assertSame($this->successData($first), $this->successData($retry));
            self::assertSame($truthAfterFirst, $this->storedRound($roundId));
            self::assertSame("2", $truthAfterFirst["round_version"]);
        }
    }

    public function testDivergentStaleEndIntentionsMapToConflict(): void
    {
        $this->seedLibrary("library-stale", "Stale", $this->actorId, "owner");
        $scenarios = [
            ["a", "completed", "2026-08-20", "stopped", "2026-08-20"],
            ["b", "completed", "2026-08-20", "completed", "2026-08-21"],
            ["c", "stopped", "2026-08-20", "completed", "2026-08-20"],
        ];

        foreach ($scenarios as [$suffix, $firstOutcome, $firstDate, $retryOutcome, $retryDate]) {
            $itemId = "item-stale-{$suffix}";
            $workId = "work-stale-{$suffix}";
            $roundId = "round-stale-{$suffix}";
            $this->seedItem($itemId, "library-stale", $workId, "Stale {$suffix}");
            $this->seedRound(
                $roundId,
                $this->actorId,
                $workId,
                $itemId
            );

            $first = $this->dispatchAsActor($this->endRequest($roundId, [
                "outcome" => $firstOutcome,
                "finished_on" => $firstDate,
                "expected_version" => 1,
            ]));
            $truthAfterFirst = $this->storedRound($roundId);
            $retry = $this->dispatchAsActor($this->endRequest($roundId, [
                "outcome" => $retryOutcome,
                "finished_on" => $retryDate,
                "expected_version" => 1,
            ]));

            self::assertSame(200, $first->get_status());
            self::assertSame(409, $retry->get_status());
            self::assertSame(
                "biblio_reading_round_stale",
                $retry->get_data()["code"]
            );
            self::assertSame($truthAfterFirst, $this->storedRound($roundId));
            self::assertSame("2", $truthAfterFirst["round_version"]);
        }
    }

    public function testCurrentVersionLifecycleAndDateValidationRemainCoreOwned(): void
    {
        $this->seedLibrary("library-domain", "Domein", $this->actorId, "owner");

        foreach (["current", "early", "future"] as $suffix) {
            $this->seedItem(
                "item-domain-{$suffix}",
                "library-domain",
                "work-domain-{$suffix}",
                "Domein {$suffix}"
            );
            $this->seedRound(
                "round-domain-{$suffix}",
                $this->actorId,
                "work-domain-{$suffix}",
                "item-domain-{$suffix}"
            );
        }

        self::assertSame(200, $this->dispatchAsActor($this->endRequest(
            "round-domain-current",
            [
                "outcome" => "completed",
                "finished_on" => "2026-08-20",
                "expected_version" => 1,
            ]
        ))->get_status());
        $currentVersionDivergent = $this->dispatchAsActor($this->endRequest(
            "round-domain-current",
            [
                "outcome" => "stopped",
                "finished_on" => "2026-08-20",
                "expected_version" => 2,
            ]
        ));
        self::assertSame(422, $currentVersionDivergent->get_status());
        self::assertSame(
            "biblio_validation_failed",
            $currentVersionDivergent->get_data()["code"]
        );

        $beforeStart = $this->dispatchAsActor($this->endRequest(
            "round-domain-early",
            [
                "outcome" => "completed",
                "finished_on" => "2026-07-31",
                "expected_version" => 1,
            ]
        ));
        self::assertSame(422, $beforeStart->get_status());

        $future = $this->dispatchAsActor($this->endRequest(
            "round-domain-future",
            [
                "outcome" => "completed",
                "finished_on" => "2026-08-30",
                "expected_version" => 1,
            ]
        ));
        self::assertSame(200, $future->get_status());
        self::assertSame(
            ["year" => 2026, "month" => 8, "day" => 30],
            $this->successData($future)["finished_on"]
        );
    }

    public function testUnknownForeignAndLibraryManagerRoundsAreNonEnumerating(): void
    {
        $this->seedLibrary("library-private", "Privé", $this->otherId, "owner");
        $this->database->insert($this->tableNames->memberships(), [
            "library_id" => "library-private",
            "user_id" => (string) $this->actorId,
            "membership_status" => "active",
            "management_role" => "manager",
            "use_access" => "direct",
            "additional_permissions" => "[]",
        ]);
        $this->seedItem(
            "item-private",
            "library-private",
            "work-private",
            "Privé"
        );
        $this->seedRound(
            "round-private",
            $this->otherId,
            "work-private",
            "item-private"
        );
        $body = [
            "outcome" => "completed",
            "finished_on" => "2026-08-29",
            "expected_version" => 1,
        ];

        $unknown = $this->dispatchAsActor($this->endRequest(
            "round-unknown",
            $body
        ));
        $foreign = $this->dispatchAsActor($this->endRequest(
            "round-private",
            $body
        ));

        $this->assertEquivalentNotAvailable($unknown, $foreign);
        $foreignTruth = $this->storedRound("round-private");
        self::assertNull($foreignTruth["round_outcome"]);
        self::assertSame("1", $foreignTruth["round_version"]);
    }

    public function testPrivateNotesRequireCookieNonceAndHideForeignCollections(): void
    {
        $this->seedWork("notes-private-work", "Privénotities");
        $this->seedPrivateNote(
            "notes-foreign",
            $this->otherId,
            "notes-private-work",
            "<p>Niet voor actor</p>"
        );
        $this->seedLibrary(
            "notes-private-library",
            "Privé",
            $this->otherId,
            "owner"
        );
        self::assertSame(1, $this->database->insert(
            $this->tableNames->memberships(),
            [
                "library_id" => "notes-private-library",
                "user_id" => (string) $this->actorId,
                "membership_status" => "active",
                "management_role" => "manager",
                "use_access" => "direct",
                "additional_permissions" => "[]",
            ]
        ));

        $anonymous = $this->server->dispatch(
            $this->privateNotesRequest("notes-private-work")
        );
        self::assertSame(401, $anonymous->get_status());
        self::assertSame(
            "biblio_authentication_required",
            $anonymous->get_data()["code"]
        );

        $missingNonce = $this->dispatchAsActor(
            $this->privateNotesRequest("notes-private-work"),
            null
        );
        self::assertSame(401, $missingNonce->get_status());

        $invalidNonce = $this->dispatchAsActor(
            $this->privateNotesRequest("notes-private-work"),
            "invalid"
        );
        self::assertSame(403, $invalidNonce->get_status());
        self::assertSame(
            "rest_cookie_invalid_nonce",
            $invalidNonce->get_data()["code"]
        );

        $foreignOnly = $this->dispatchAsActor(
            $this->privateNotesRequest("notes-private-work")
        );
        self::assertSame(200, $foreignOnly->get_status());
        self::assertSame(
            ["items" => [], "next_cursor" => null],
            $this->successData($foreignOnly)
        );

        $unknownWork = $this->dispatchAsActor(
            $this->privateNotesRequest("notes-unknown-work")
        );
        self::assertSame(200, $unknownWork->get_status());
        self::assertSame(
            $this->successData($foreignOnly),
            $this->successData($unknownWork)
        );
    }

    public function testPrivateNotesCollectionIsAllowlistedOrderedAndPaginated(): void
    {
        $this->seedWork("notes-work", "Notities");
        $this->seedWork("notes-other-work", "Andere Work");
        $this->seedPrivateNote(
            "notes-z",
            $this->actorId,
            "notes-work",
            "<p><strong>Z</strong></p>",
            "2026-08-31 12:00:00.200000"
        );
        $this->seedPrivateNote(
            "notes-m",
            $this->actorId,
            "notes-work",
            "<p><em>M</em></p>",
            "2026-08-31 12:00:00.200000"
        );
        $this->seedPrivateNote(
            "notes-a",
            $this->actorId,
            "notes-work",
            "<blockquote>A</blockquote>",
            "2026-08-31 12:00:00.100000"
        );
        $this->seedPrivateNote(
            "notes-other-work",
            $this->actorId,
            "notes-other-work",
            "<p>Andere Work</p>",
            "2026-08-31 13:00:00.000000"
        );
        $this->seedPrivateNote(
            "notes-other-user",
            $this->otherId,
            "notes-work",
            "<p>Andere actor</p>",
            "2026-08-31 14:00:00.000000"
        );

        $first = $this->dispatchAsActor($this->privateNotesRequest(
            "notes-work",
            ["limit" => "2"]
        ));
        $firstData = $this->successData($first);

        self::assertSame(200, $first->get_status());
        self::assertSame(
            ["items", "next_cursor"],
            array_keys($firstData)
        );
        self::assertSame(
            ["notes-z", "notes-m"],
            array_column($firstData["items"], "private_note_id")
        );
        self::assertSame(
            ["private_note_id", "content_html", "version"],
            array_keys($firstData["items"][0])
        );
        self::assertSame(
            "<p><strong>Z</strong></p>",
            $firstData["items"][0]["content_html"]
        );
        self::assertSame(1, $firstData["items"][0]["version"]);
        self::assertIsString($firstData["next_cursor"]);

        $second = $this->dispatchAsActor($this->privateNotesRequest(
            "notes-work",
            [
                "limit" => "2",
                "cursor" => $firstData["next_cursor"],
            ]
        ));
        $secondData = $this->successData($second);
        self::assertSame(
            ["notes-a"],
            array_column($secondData["items"], "private_note_id")
        );
        self::assertNull($secondData["next_cursor"]);
        self::assertSame(3, count(array_unique(array_merge(
            array_column($firstData["items"], "private_note_id"),
            array_column($secondData["items"], "private_note_id")
        ))));
        self::assertNotContains(
            "notes-other-user",
            array_column($firstData["items"], "private_note_id")
        );
        self::assertNotContains(
            "notes-other-work",
            array_column($firstData["items"], "private_note_id")
        );
    }

    public function testPrivateNotesDefaultMaximumAndInvalidLimits(): void
    {
        $this->seedWork("notes-limit-work", "Limieten");

        for ($index = 1; $index <= 101; $index++) {
            $this->seedPrivateNote(
                sprintf("notes-limit-%03d", $index),
                $this->actorId,
                "notes-limit-work",
                "<p>Notitie {$index}</p>",
                sprintf("2026-08-31 12:00:%02d.%06d", $index % 60, $index)
            );
        }

        $default = $this->successData($this->dispatchAsActor(
            $this->privateNotesRequest("notes-limit-work")
        ));
        self::assertCount(50, $default["items"]);
        self::assertIsString($default["next_cursor"]);

        $maximum = $this->successData($this->dispatchAsActor(
            $this->privateNotesRequest(
                "notes-limit-work",
                ["limit" => "100"]
            )
        ));
        self::assertCount(100, $maximum["items"]);
        self::assertIsString($maximum["next_cursor"]);

        foreach (["0", "101", "1.5"] as $invalidLimit) {
            $response = $this->dispatchAsActor($this->privateNotesRequest(
                "notes-limit-work",
                ["limit" => $invalidLimit]
            ));
            self::assertSame(400, $response->get_status());
        }

        $wrongType = $this->dispatchAsActor($this->privateNotesRequest(
            "notes-limit-work",
            ["limit" => true]
        ));
        self::assertSame(400, $wrongType->get_status());
        self::assertSame(
            "biblio_invalid_field_type",
            $wrongType->get_data()["code"]
        );
    }

    public function testPrivateNotesCursorIsOpaqueVersionedAndNeverAuthorizes(): void
    {
        $this->seedWork("notes-cursor-work-a", "Cursor A");
        $this->seedWork("notes-cursor-work-b", "Cursor B");
        $this->seedPrivateNote(
            "notes-cursor-a-top",
            $this->actorId,
            "notes-cursor-work-a",
            "<p>A boven</p>",
            "2026-08-31 12:00:00.000000"
        );
        $this->seedPrivateNote(
            "notes-cursor-a-next",
            $this->actorId,
            "notes-cursor-work-a",
            "<p>A vervolg</p>",
            "2026-08-31 11:00:00.000000"
        );
        $this->seedPrivateNote(
            "notes-cursor-b",
            $this->actorId,
            "notes-cursor-work-b",
            "<p>Work B</p>",
            "2026-08-31 11:00:00.000000"
        );
        $this->seedPrivateNote(
            "notes-cursor-other",
            $this->otherId,
            "notes-cursor-work-a",
            "<p>Andere actor</p>",
            "2026-08-31 11:00:00.000000"
        );

        $first = $this->successData($this->dispatchAsActor(
            $this->privateNotesRequest(
                "notes-cursor-work-a",
                ["limit" => "1"]
            )
        ));
        $cursor = $first["next_cursor"];
        self::assertIsString($cursor);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', $cursor);
        self::assertStringNotContainsString("notes-cursor-a-top", $cursor);
        self::assertStringNotContainsString("2026-08-31", $cursor);

        $otherActor = $this->successData($this->dispatchAsUser(
            $this->privateNotesRequest(
                "notes-cursor-work-a",
                ["limit" => "10", "cursor" => $cursor]
            ),
            $this->otherId
        ));
        self::assertSame(
            ["notes-cursor-other"],
            array_column($otherActor["items"], "private_note_id")
        );

        $otherWork = $this->successData($this->dispatchAsActor(
            $this->privateNotesRequest(
                "notes-cursor-work-b",
                ["limit" => "10", "cursor" => $cursor]
            )
        ));
        self::assertSame(
            ["notes-cursor-b"],
            array_column($otherWork["items"], "private_note_id")
        );

        $codec = new PrivateNoteCursorCodec();
        $typed = new PrivateNoteViewCursor(
            new DateTimeImmutable("2026-08-31T12:00:00.123456+00:00"),
            new PrivateNoteId("notes-cursor-roundtrip")
        );
        $decoded = $codec->decode($codec->encode($typed));
        self::assertSame(
            "2026-08-31 12:00:00.123456",
            $decoded->beforeUpdatedAt()->format("Y-m-d H:i:s.u")
        );
        self::assertSame(
            "notes-cursor-roundtrip",
            $decoded->beforeId()->value()
        );

        foreach (["not-valid!", $cursor . "A", $this->encodedJson([
            "v" => 2,
            "u" => "2026-08-31T12:00:00.000000Z",
            "i" => "notes-cursor-a-top",
        ])] as $invalidCursor) {
            $invalid = $this->dispatchAsActor($this->privateNotesRequest(
                "notes-cursor-work-a",
                ["cursor" => $invalidCursor]
            ));
            self::assertSame(400, $invalid->get_status());
            self::assertSame(
                "biblio_invalid_field_syntax",
                $invalid->get_data()["code"]
            );
        }
    }

    public function testPrivateNotesPostCreatesOwnerWorkNoteWithSafeAllowlist(): void
    {
        $this->seedWork("notes-create-work", "Nieuwe notitie");
        $content = "<p><strong>Sterk</strong><br><em>Nadruk</em></p>"
            . "<ul><li>Eén</li></ul><ol><li>Twee</li></ol>"
            . "<blockquote>Citaat</blockquote>";
        $response = $this->dispatchAsActor($this->privateNoteRequest(
            "POST",
            "/biblio/v1/me/works/notes-create-work/private-notes",
            ["content" => $content]
        ));
        $note = $this->successData($response);

        self::assertSame(201, $response->get_status());
        self::assertSame(
            ["private_note_id", "content_html", "version"],
            array_keys($note)
        );
        self::assertSame($content, $note["content_html"]);
        self::assertSame(1, $note["version"]);
        self::assertIsString($note["private_note_id"]);

        $stored = $this->storedPrivateNote($note["private_note_id"]);
        self::assertSame((string) $this->actorId, $stored["user_id"]);
        self::assertSame("notes-create-work", $stored["work_id"]);
        self::assertNull($stored["reading_round_id"]);
        self::assertSame($content, $stored["note_content"]);
        self::assertSame("1", $stored["note_version"]);
    }

    public function testPrivateNotesPostRejectsMalformedInjectedAndUnsafeBodies(): void
    {
        $this->seedWork("notes-invalid-work", "Ongeldig");
        $path = "/biblio/v1/me/works/notes-invalid-work/private-notes";
        $transportCases = [
            ["{", "rest_invalid_json"],
            ["[]", "biblio_invalid_field_type"],
            ["{}", "biblio_missing_required_field"],
            [(string) wp_json_encode(["content" => true]), "biblio_invalid_field_type"],
            [(string) wp_json_encode([
                "content" => "<p>Injectie</p>",
                "user_id" => (string) $this->otherId,
            ]), "biblio_unknown_request_fields"],
            [(string) wp_json_encode([
                "content" => "<p>Injectie</p>",
                "library_id" => "library-other",
            ]), "biblio_unknown_request_fields"],
            [(string) wp_json_encode([
                "content" => "<p>Injectie</p>",
                "reading_round_id" => "round-other",
            ]), "biblio_unknown_request_fields"],
        ];

        foreach ($transportCases as [$body, $code]) {
            $response = $this->dispatchAsActor(
                $this->privateNoteRawRequest("POST", $path, $body)
            );
            self::assertSame(400, $response->get_status());
            self::assertSame($code, $response->get_data()["code"]);
        }

        $unknownQuery = $this->privateNoteRequest(
            "POST",
            $path,
            ["content" => "<p>Query</p>"]
        );
        $unknownQuery->set_query_params(["user_id" => (string) $this->otherId]);
        self::assertSame(
            400,
            $this->dispatchAsActor($unknownQuery)->get_status()
        );

        $validationCases = [
            "",
            '<p onclick="alert(1)">Attribuut</p>',
            "<script>alert(1)</script>",
            "<p>NUL\0byte</p>",
            "<p>" . str_repeat("x", 65536) . "</p>",
        ];

        foreach ($validationCases as $content) {
            $response = $this->dispatchAsActor($this->privateNoteRequest(
                "POST",
                $path,
                ["content" => $content]
            ));
            self::assertSame(422, $response->get_status());
            self::assertSame(
                "biblio_validation_failed",
                $response->get_data()["code"]
            );
            self::assertStringNotContainsString(
                "onclick",
                (string) wp_json_encode($response->get_data())
            );
        }

        $unknownWork = $this->dispatchAsActor($this->privateNoteRequest(
            "POST",
            "/biblio/v1/me/works/notes-missing-work/private-notes",
            ["content" => "<p>Geldige inhoud</p>"]
        ));
        self::assertSame(422, $unknownWork->get_status());
        self::assertSame(
            "biblio_validation_failed",
            $unknownWork->get_data()["code"]
        );

        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->privateNotes()}`"
        ));
    }

    public function testPrivateNotesPatchPreservesNoOpAndConflictSemantics(): void
    {
        $this->seedWork("notes-update-work", "Wijzigen");
        $created = $this->successData($this->dispatchAsActor(
            $this->privateNoteRequest(
                "POST",
                "/biblio/v1/me/works/notes-update-work/private-notes",
                ["content" => "<p>Begin</p>"]
            )
        ));
        $id = $created["private_note_id"];
        self::assertIsString($id);

        $written = $this->dispatchAsActor($this->privateNoteRequest(
            "PATCH",
            "/biblio/v1/me/private-notes/{$id}",
            ["content" => "<p>Gewijzigd</p>", "expected_version" => 1]
        ));
        self::assertSame(200, $written->get_status());
        self::assertSame([
            "private_note_id" => $id,
            "content_html" => "<p>Gewijzigd</p>",
            "version" => 2,
        ], $this->successData($written));

        foreach ([2, 1] as $expectedVersion) {
            $noOp = $this->dispatchAsActor($this->privateNoteRequest(
                "PATCH",
                "/biblio/v1/me/private-notes/{$id}",
                [
                    "content" => "<p>Gewijzigd</p>",
                    "expected_version" => $expectedVersion,
                ]
            ));
            self::assertSame(200, $noOp->get_status());
            self::assertSame(2, $this->successData($noOp)["version"]);
        }

        $conflict = $this->dispatchAsActor($this->privateNoteRequest(
            "PATCH",
            "/biblio/v1/me/private-notes/{$id}",
            ["content" => "<p>Andere intentie</p>", "expected_version" => 1]
        ));
        self::assertSame(409, $conflict->get_status());
        self::assertSame(
            "biblio_private_note_stale",
            $conflict->get_data()["code"]
        );
        $stored = $this->storedPrivateNote($id);
        self::assertSame("<p>Gewijzigd</p>", $stored["note_content"]);
        self::assertSame("2", $stored["note_version"]);
    }

    public function testPrivateNotesPatchIsStrictValidatedAndNonEnumerating(): void
    {
        $this->seedWork("notes-member-work", "Member");
        $this->seedPrivateNote(
            "notes-member-foreign",
            $this->otherId,
            "notes-member-work",
            "<p>Vreemd</p>"
        );
        $this->seedPrivateNote(
            "notes-member-deleted",
            $this->actorId,
            "notes-member-work",
            "<p>Verwijderd</p>"
        );
        self::assertSame(204, $this->dispatchAsActor($this->privateNoteRequest(
            "DELETE",
            "/biblio/v1/me/private-notes/notes-member-deleted",
            ["expected_version" => 1]
        ))->get_status());
        $body = ["content" => "<p>Poging</p>", "expected_version" => 1];
        $unknown = $this->dispatchAsActor($this->privateNoteRequest(
            "PATCH",
            "/biblio/v1/me/private-notes/notes-member-unknown",
            $body
        ));
        $foreign = $this->dispatchAsActor($this->privateNoteRequest(
            "PATCH",
            "/biblio/v1/me/private-notes/notes-member-foreign",
            $body
        ));
        $deleted = $this->dispatchAsActor($this->privateNoteRequest(
            "PATCH",
            "/biblio/v1/me/private-notes/notes-member-deleted",
            $body
        ));
        $this->assertEquivalentNotAvailable($unknown, $foreign);
        $this->assertEquivalentNotAvailable($unknown, $deleted);

        $this->seedPrivateNote(
            "notes-member-own",
            $this->actorId,
            "notes-member-work",
            "<p>Eigen</p>"
        );
        $invalidContent = $this->dispatchAsActor($this->privateNoteRequest(
            "PATCH",
            "/biblio/v1/me/private-notes/notes-member-own",
            [
                "content" => '<p style="color:red">Onveilig</p>',
                "expected_version" => 1,
            ]
        ));
        self::assertSame(422, $invalidContent->get_status());

        foreach ([
            [
                "content" => "<p>Extra</p>",
                "expected_version" => 1,
                "user_id" => (string) $this->otherId,
            ],
            ["content" => "<p>String</p>", "expected_version" => "1"],
        ] as $invalidBody) {
            $invalid = $this->dispatchAsActor($this->privateNoteRequest(
                "PATCH",
                "/biblio/v1/me/private-notes/notes-member-own",
                $invalidBody
            ));
            self::assertSame(400, $invalid->get_status());
        }

        $invalidId = $this->dispatchAsActor($this->privateNoteRequest(
            "PATCH",
            "/biblio/v1/me/private-notes/" . str_repeat("x", 192),
            $body
        ));
        self::assertSame(400, $invalidId->get_status());
    }

    public function testPrivateNotesDeleteIsConditionalNoContentAndNonEnumerating(): void
    {
        $this->seedWork("notes-delete-work", "Verwijderen");
        $this->seedPrivateNote(
            "notes-delete-own",
            $this->actorId,
            "notes-delete-work",
            "<p>Eigen</p>"
        );
        $this->seedPrivateNote(
            "notes-delete-foreign",
            $this->otherId,
            "notes-delete-work",
            "<p>Vreemd</p>"
        );
        $updated = $this->dispatchAsActor($this->privateNoteRequest(
            "PATCH",
            "/biblio/v1/me/private-notes/notes-delete-own",
            ["content" => "<p>Versie twee</p>", "expected_version" => 1]
        ));
        self::assertSame(2, $this->successData($updated)["version"]);

        $stale = $this->dispatchAsActor($this->privateNoteRequest(
            "DELETE",
            "/biblio/v1/me/private-notes/notes-delete-own",
            ["expected_version" => 1]
        ));
        self::assertSame(409, $stale->get_status());
        self::assertSame(
            "biblio_private_note_stale",
            $stale->get_data()["code"]
        );
        self::assertSame("2", $this->storedPrivateNote(
            "notes-delete-own"
        )["note_version"]);

        $deleted = $this->dispatchAsActor($this->privateNoteRequest(
            "DELETE",
            "/biblio/v1/me/private-notes/notes-delete-own",
            ["expected_version" => 2]
        ));
        self::assertSame(204, $deleted->get_status());
        self::assertNull($deleted->get_data());
        self::assertNull($this->storedPrivateNoteOrNull("notes-delete-own"));

        $body = ["expected_version" => 2];
        $repeated = $this->dispatchAsActor($this->privateNoteRequest(
            "DELETE",
            "/biblio/v1/me/private-notes/notes-delete-own",
            $body
        ));
        $unknown = $this->dispatchAsActor($this->privateNoteRequest(
            "DELETE",
            "/biblio/v1/me/private-notes/notes-delete-unknown",
            $body
        ));
        $foreign = $this->dispatchAsActor($this->privateNoteRequest(
            "DELETE",
            "/biblio/v1/me/private-notes/notes-delete-foreign",
            $body
        ));
        $this->assertEquivalentNotAvailable($repeated, $unknown);
        $this->assertEquivalentNotAvailable($repeated, $foreign);

        $wrongType = $this->dispatchAsActor($this->privateNoteRequest(
            "DELETE",
            "/biblio/v1/me/private-notes/notes-delete-foreign",
            ["expected_version" => "1"]
        ));
        self::assertSame(400, $wrongType->get_status());

        $unknownField = $this->dispatchAsActor($this->privateNoteRequest(
            "DELETE",
            "/biblio/v1/me/private-notes/notes-delete-foreign",
            ["expected_version" => 1, "library_id" => "library-other"]
        ));
        self::assertSame(400, $unknownField->get_status());
        self::assertSame(
            "biblio_unknown_request_fields",
            $unknownField->get_data()["code"]
        );
    }

    public function testPrivateNotesInvalidStoredContentFailsClosed(): void
    {
        $this->seedWork("notes-corrupt-work", "Corrupt");
        $this->seedPrivateNote(
            "notes-corrupt",
            $this->actorId,
            "notes-corrupt-work",
            "<p>Veilig</p>"
        );
        self::assertSame(1, $this->database->update(
            $this->tableNames->privateNotes(),
            ["note_content" => '<p onclick="alert(1)">Onveilig</p>'],
            ["private_note_id" => "notes-corrupt"],
            ["%s"],
            ["%s"]
        ));

        $response = $this->dispatchAsActor(
            $this->privateNotesRequest("notes-corrupt-work")
        );
        self::assertSame(500, $response->get_status());
        self::assertSame("biblio_internal_error", $response->get_data()["code"]);
        $public = (string) wp_json_encode($response->get_data());
        self::assertStringNotContainsString("onclick", $public);
        self::assertStringNotContainsString("notes-corrupt", $public);
    }

    public function testPrivateNotesCollectionStrictlyRejectsMalformedInputs(): void
    {
        $unknownQuery = $this->dispatchAsActor($this->privateNotesRequest(
            "notes-work",
            ["user_id" => (string) $this->otherId]
        ));
        self::assertSame(400, $unknownQuery->get_status());
        self::assertSame(
            "biblio_unknown_request_fields",
            $unknownQuery->get_data()["code"]
        );

        $invalidWork = $this->dispatchAsActor($this->privateNotesRequest(
            str_repeat("x", 192)
        ));
        self::assertSame(400, $invalidWork->get_status());

        $invalidCursorType = $this->dispatchAsActor($this->privateNotesRequest(
            "notes-work",
            ["cursor" => true]
        ));
        self::assertSame(400, $invalidCursorType->get_status());
        self::assertSame(
            "biblio_invalid_field_type",
            $invalidCursorType->get_data()["code"]
        );
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function invalidEndRequests(): iterable
    {
        $valid = [
            "outcome" => "completed",
            "finished_on" => "2026-08-29",
            "expected_version" => 1,
        ];

        yield "malformed ReadingRound ID" => [
            str_repeat("x", 192),
            $valid,
            "biblio_invalid_field_syntax",
        ];
        yield "missing outcome" => ["round", array_diff_key($valid, ["outcome" => true]), "biblio_missing_required_field"];
        yield "unknown outcome" => ["round", [...$valid, "outcome" => "done"], "biblio_invalid_field_syntax"];
        yield "outcome wrong type" => ["round", [...$valid, "outcome" => true], "biblio_invalid_field_type"];
        yield "missing finished_on" => ["round", array_diff_key($valid, ["finished_on" => true]), "biblio_missing_required_field"];
        yield "malformed finished_on" => ["round", [...$valid, "finished_on" => "29-08-2026"], "biblio_invalid_field_syntax"];
        yield "impossible finished_on" => ["round", [...$valid, "finished_on" => "2026-02-30"], "biblio_invalid_field_syntax"];
        yield "finished_on wrong type" => ["round", [...$valid, "finished_on" => 20260829], "biblio_invalid_field_type"];
        yield "missing expected_version" => ["round", array_diff_key($valid, ["expected_version" => true]), "biblio_missing_required_field"];
        yield "zero expected_version" => ["round", [...$valid, "expected_version" => 0], "biblio_invalid_field_syntax"];
        yield "string expected_version" => ["round", [...$valid, "expected_version" => "1"], "biblio_invalid_field_type"];
        yield "unknown field" => ["round", [...$valid, "user_id" => "other"], "biblio_unknown_request_fields"];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider("invalidEndRequests")]
    public function testEndReadingStrictlyRejectsInvalidRequests(
        string $roundId,
        array $body,
        string $code
    ): void {
        $response = $this->dispatchAsActor($this->endRequest($roundId, $body));

        self::assertSame(400, $response->get_status());
        self::assertSame($code, $response->get_data()["code"]);
    }

    /**
     * @param array<int, array<string, mixed>> $endpoints
     * @return list<string>
     */
    private function routeMethods(array $endpoints): array
    {
        $methods = [];

        foreach ($endpoints as $endpoint) {
            if (!isset($endpoint["callback"]) || !is_array($endpoint["methods"])) {
                continue;
            }

            $methods = array_merge($methods, array_keys($endpoint["methods"]));
        }

        $methods = array_values(array_unique($methods));
        sort($methods);

        return $methods;
    }

    /** @param array<string, mixed> $query */
    private function privateNotesRequest(
        string $workId,
        array $query = []
    ): WP_REST_Request {
        $request = new WP_REST_Request(
            "GET",
            "/biblio/v1/me/works/{$workId}/private-notes"
        );
        $request->set_query_params($query);

        return $request;
    }

    /** @param array<string, mixed> $query */
    private function catalogRequest(
        string $libraryId,
        array $query = []
    ): WP_REST_Request {
        $request = new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/{$libraryId}/catalog"
        );
        $request->set_query_params($query);

        return $request;
    }

    /** @param array<string, mixed> $body */
    private function privateNoteRequest(
        string $method,
        string $path,
        array $body
    ): WP_REST_Request {
        return $this->privateNoteRawRequest(
            $method,
            $path,
            (string) wp_json_encode($body)
        );
    }

    private function privateNoteRawRequest(
        string $method,
        string $path,
        string $body
    ): WP_REST_Request {
        $request = new WP_REST_Request($method, $path);
        $request->set_header("content-type", "application/json");
        $request->set_body($body);

        return $request;
    }

    /** @param array<string, mixed> $payload */
    private function encodedJson(array $payload): string
    {
        return rtrim(strtr(base64_encode((string) wp_json_encode($payload)), "+/", "-_"), "=");
    }

    private function dispatchAsActor(
        WP_REST_Request $request,
        ?string $nonce = "valid"
    ): WP_REST_Response {
        return $this->dispatchAsUser($request, $this->actorId, $nonce);
    }

    /** @param array<string, mixed> $body */
    private function bibliographicSearchRequest(array $body): WP_REST_Request
    {
        return $this->bibliographicSearchRawRequest(
            (string) wp_json_encode($body)
        );
    }

    private function bibliographicSearchRawRequest(string $body): WP_REST_Request
    {
        $request = new WP_REST_Request(
            "POST",
            "/biblio/v1/me/bibliographic-searches"
        );
        $request->set_header("content-type", "application/json");
        $request->set_body($body);

        return $request;
    }

    /** @param array<string, mixed> $body */
    private function bibliographicAuthorWorksRequest(array $body): WP_REST_Request
    {
        return $this->bibliographicAuthorWorksRawRequest(
            (string) wp_json_encode($body)
        );
    }

    private function bibliographicAuthorWorksRawRequest(string $body): WP_REST_Request
    {
        $request = new WP_REST_Request(
            "POST",
            "/biblio/v1/me/bibliographic-author-works"
        );
        $request->set_header("content-type", "application/json");
        $request->set_body($body);

        return $request;
    }

    private function authorSelectorCodec(): BibliographicAuthorSelectorCodec
    {
        $salt = constant("AUTH_SALT");
        self::assertIsString($salt);
        return new BibliographicAuthorSelectorCodec(
            hash("sha256", $salt . ":bibliographic-author-selector-v1")
        );
    }

    private function rebuildApiWithOpenLibraryConfiguration(): void
    {
        $this->server = new WP_REST_Server();
        global $wp_rest_server;
        $wp_rest_server = $this->server;

        $application = (new ProductionComposition(
            $this->database,
            metadataLookup: new FirstSufficientMetadataLookupService(
                new CandidateClassifier(),
                new ConfigurationErrorMetadataProvider("open_library"),
                new ConfigurationErrorMetadataProvider("google_books")
            ),
            providerConfiguration: new RuntimeMetadataProviderConfiguration(
                static fn (string $name): bool => false,
                static fn (string $name): mixed => null,
                static fn (string $name): mixed => $name
                    === "BIBLIO_OPEN_LIBRARY_CONTACT_EMAIL"
                    ? "integration@example.invalid"
                    : false
            )
        ))->application();
        $this->api = new RestApi(static fn () => $application);
        $this->api->registerRoutes();
    }

    /** @param array<string, mixed> $body */
    private function metadataLookupRequest(
        string $libraryId,
        array $body
    ): WP_REST_Request {
        $request = new WP_REST_Request(
            "POST",
            "/biblio/v1/libraries/{$libraryId}/metadata-lookups"
        );
        $request->set_header("content-type", "application/json");
        $request->set_body((string) wp_json_encode($body));

        return $request;
    }

    /** @param array<string, mixed> $body */
    private function addBookCommitRequest(
        string $libraryId,
        array $body
    ): WP_REST_Request {
        $request = new WP_REST_Request(
            "POST",
            "/biblio/v1/libraries/{$libraryId}/items"
        );
        $request->set_header("content-type", "application/json");
        $request->set_body((string) wp_json_encode($body));
        return $request;
    }

    /** @return array<string, int> */
    private function addBookPersistenceCounts(): array
    {
        $tables = [
            "works" => $this->tableNames->works(),
            "editions" => $this->tableNames->editions(),
            "items" => $this->tableNames->items(),
            "claims" => $this->tableNames->editionIdentifierClaims(),
            "provenance" => $this->tableNames->editionMetadataProvenance(),
            "field_states" => $this->tableNames->metadataFieldStates(),
            "field_values" => $this->tableNames->metadataFieldValues(),
            "field_evidence" => $this->tableNames->metadataFieldEvidence(),
        ];
        $counts = [];

        foreach ($tables as $key => $table) {
            $counts[$key] = (int) $this->database->get_var(
                "SELECT COUNT(*) FROM `{$table}`"
            );
        }

        return $counts;
    }

    private function dispatchAsUser(
        WP_REST_Request $request,
        int $userId,
        ?string $nonce = "valid"
    ): WP_REST_Response {
        wp_set_current_user($userId);

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

    /** @param array<string, mixed> $query */
    private function historyRequest(
        string $workId,
        array $query = []
    ): WP_REST_Request {
        $request = new WP_REST_Request(
            "GET",
            "/biblio/v1/me/works/{$workId}/reading-history"
        );
        $request->set_query_params($query);

        return $request;
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

    /** @param array<string, mixed> $body */
    private function endRequest(
        string $roundId,
        array $body
    ): WP_REST_Request {
        $request = new WP_REST_Request(
            "POST",
            "/biblio/v1/me/reading-rounds/{$roundId}/end"
        );
        $request->set_header("content-type", "application/json");
        $request->set_body((string) wp_json_encode($body));

        return $request;
    }

    /** @return array<string, null|string> */
    private function storedRound(string $roundId): array
    {
        $row = $this->database->get_row($this->database->prepare(
            "SELECT round_outcome, reading_finished_year, "
                . "reading_finished_month, reading_finished_day, round_version, "
                . "updated_at, ended_at FROM `{$this->tableNames->readingRounds()}` "
                . "WHERE reading_round_id = %s",
            $roundId
        ), ARRAY_A);
        self::assertIsArray($row);

        return $row;
    }

    /** @return array<string, array{round_outcome: string, round_version: string}> */
    private function storedEndTruth(string ...$roundIds): array
    {
        $truth = [];

        foreach ($roundIds as $roundId) {
            $row = $this->storedRound($roundId);
            self::assertIsString($row["round_outcome"]);
            self::assertIsString($row["round_version"]);
            $truth[$roundId] = [
                "round_outcome" => $row["round_outcome"],
                "round_version" => $row["round_version"],
            ];
        }

        return $truth;
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
            "management_role" => match ($access) {
                "owner" => "owner",
                "manager" => "manager",
                default => "member",
            },
            "use_access" => $access === "view_only" ? "view_only" : "direct",
            "additional_permissions" => "[]",
        ]);
    }

    private function seedBookType(string $libraryId, string $bookTypeId): void
    {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->libraryBookTypes(),
            [
                "library_id" => $libraryId,
                "book_type_id" => $bookTypeId,
                "display_name" => "Leesboek",
                "normalized_name" => "leesboek",
                "term_status" => "active",
            ]
        ));
    }

    /**
     * @param array{string, string, string, string} $bookType
     * @param list<array{string, string, string, string}> $genres
     * @param list<array{string, string, string, string}> $subjects
     */
    private function seedClassificationAssignment(
        string $libraryId,
        string $workId,
        array $bookType,
        array $genres,
        array $subjects
    ): void {
        [$bookTypeId, $name, $normalized, $status] = $bookType;
        $this->database->insert($this->tableNames->libraryBookTypes(), [
            "library_id" => $libraryId,
            "book_type_id" => $bookTypeId,
            "display_name" => $name,
            "normalized_name" => $normalized,
            "term_status" => $status,
        ]);
        $this->database->insert($this->tableNames->libraryCatalogContexts(), [
            "library_id" => $libraryId,
            "work_id" => $workId,
            "book_type_id" => $bookTypeId,
            "context_version" => 1,
        ]);

        foreach ($genres as [$termId, $termName, $termNormalized, $termStatus]) {
            $this->database->insert($this->tableNames->libraryGenres(), [
                "library_id" => $libraryId,
                "genre_id" => $termId,
                "display_name" => $termName,
                "normalized_name" => $termNormalized,
                "term_status" => $termStatus,
            ]);
            $this->database->insert($this->tableNames->libraryCatalogContextGenres(), [
                "library_id" => $libraryId,
                "work_id" => $workId,
                "genre_id" => $termId,
            ]);
        }
        foreach ($subjects as [$termId, $termName, $termNormalized, $termStatus]) {
            $this->database->insert($this->tableNames->librarySubjects(), [
                "library_id" => $libraryId,
                "subject_id" => $termId,
                "display_name" => $termName,
                "normalized_name" => $termNormalized,
                "term_status" => $termStatus,
            ]);
            $this->database->insert($this->tableNames->libraryCatalogContextSubjects(), [
                "library_id" => $libraryId,
                "work_id" => $workId,
                "subject_id" => $termId,
            ]);
        }
    }

    private function seedItem(
        string $itemId,
        string $libraryId,
        string $workId,
        string $title
    ): void {
        if ((int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$this->tableNames->works()}` WHERE work_id = %s",
            $workId
        )) === 0) {
            $this->database->insert($this->tableNames->works(), [
                "work_id" => $workId,
                "work_title" => $title,
            ]);
        }
        $this->database->insert($this->tableNames->editions(), [
            "edition_id" => "edition-{$itemId}",
            "work_id" => $workId,
            "edition_title" => $title,
        ]);
        $this->database->insert($this->tableNames->items(), [
            "item_id" => $itemId,
            "library_id" => $libraryId,
            "edition_id" => "edition-{$itemId}",
            "item_status" => "active",
        ]);
    }

    private function seedDetailCollection(
        string $libraryId,
        string $collectionId,
        string $name,
        int $position,
        string $status = "active"
    ): void {
        $this->database->insert($this->tableNames->collections(), [
            "library_id" => $libraryId,
            "collection_id" => $collectionId,
            "collection_name" => $name,
            "normalized_name" => strtolower($name),
            "collection_status" => $status,
            "collection_position" => $position,
            "collection_version" => 1,
            "created_at" => "2026-09-08 08:00:00.000000",
            "updated_at" => "2026-09-08 08:00:00.000000",
        ]);
    }

    private function seedDetailCollectionMembership(
        string $libraryId,
        string $membershipId,
        string $collectionId,
        string $itemId,
        int $position
    ): void {
        $this->database->insert($this->tableNames->collectionMemberships(), [
            "library_id" => $libraryId,
            "membership_id" => $membershipId,
            "collection_id" => $collectionId,
            "item_id" => $itemId,
            "membership_status" => "active",
            "item_position" => $position,
            "added_at" => "2026-09-08 08:01:00.000000",
        ]);
    }

    private function seedRichCatalogItem(
        string $libraryId,
        string $itemId,
        string $workId,
        string $title,
        string $position
    ): void {
        $locationId = "location-query-count";
        $bookTypeId = "book-query-count";
        $genreId = "genre-query-count";
        $subjectId = "subject-query-count";
        $collectionId = "collection-query-count";

        if ((int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$this->tableNames->locations()}` "
                . "WHERE library_id = %s AND location_id = %s",
            $libraryId,
            $locationId
        )) === 0) {
            $this->database->insert($this->tableNames->locations(), [
                "library_id" => $libraryId,
                "location_id" => $locationId,
                "display_name" => "Kast",
            ]);
            foreach ([
                [$this->tableNames->libraryBookTypes(), "book_type_id", $bookTypeId],
                [$this->tableNames->libraryGenres(), "genre_id", $genreId],
                [$this->tableNames->librarySubjects(), "subject_id", $subjectId],
            ] as [$table, $column, $value]) {
                $this->database->insert($table, [
                    "library_id" => $libraryId,
                    $column => $value,
                    "display_name" => $value,
                    "normalized_name" => $value,
                    "term_status" => "active",
                ]);
            }
            $this->database->insert($this->tableNames->collections(), [
                "library_id" => $libraryId,
                "collection_id" => $collectionId,
                "collection_name" => "Collectie",
                "normalized_name" => "collectie",
                "collection_status" => "active",
                "collection_position" => 1,
                "collection_version" => 1,
                "created_at" => "2026-09-05 10:00:00.000000",
                "updated_at" => "2026-09-05 10:00:00.000000",
            ]);
        }

        $this->seedItem($itemId, $libraryId, $workId, $title);
        self::assertSame(1, $this->database->update(
            $this->tableNames->items(),
            [
                "inventory_number" => "INV-{$itemId}",
                "location_id" => $locationId,
            ],
            ["item_id" => $itemId]
        ));
        $this->database->insert($this->tableNames->authors(), [
            "author_id" => "author-{$workId}",
            "display_name" => "Author {$position}",
        ]);
        $this->database->insert($this->tableNames->workContributors(), [
            "work_id" => $workId,
            "author_id" => "author-{$workId}",
            "contributor_role" => "author",
            "contributor_position" => 1,
        ]);
        $this->database->insert($this->tableNames->series(), [
            "series_id" => "series-{$workId}",
            "display_name" => "Series {$position}",
        ]);
        $this->database->insert($this->tableNames->workSeries(), [
            "work_id" => $workId,
            "series_id" => "series-{$workId}",
            "series_position" => "{$position}.000000",
        ]);
        $this->database->insert($this->tableNames->libraryCatalogContexts(), [
            "library_id" => $libraryId,
            "work_id" => $workId,
            "book_type_id" => $bookTypeId,
            "context_version" => 1,
        ]);
        $this->database->insert($this->tableNames->libraryCatalogContextGenres(), [
            "library_id" => $libraryId,
            "work_id" => $workId,
            "genre_id" => $genreId,
        ]);
        $this->database->insert($this->tableNames->libraryCatalogContextSubjects(), [
            "library_id" => $libraryId,
            "work_id" => $workId,
            "subject_id" => $subjectId,
        ]);
        $this->database->insert($this->tableNames->collectionMemberships(), [
            "library_id" => $libraryId,
            "membership_id" => "membership-{$itemId}",
            "collection_id" => $collectionId,
            "item_id" => $itemId,
            "membership_status" => "active",
            "item_position" => (int) $position,
            "added_at" => "2026-09-05 10:00:00.000000",
        ]);
        $this->seedRound(
            "round-{$itemId}",
            $this->actorId,
            $workId,
            $itemId,
            outcome: "completed"
        );
    }

    private function seedWork(string $workId, string $title): void
    {
        if ((int) $this->database->get_var($this->database->prepare(
            "SELECT COUNT(*) FROM `{$this->tableNames->works()}` WHERE work_id = %s",
            $workId
        )) === 0) {
            $this->database->insert($this->tableNames->works(), [
                "work_id" => $workId,
                "work_title" => $title,
            ]);
        }
    }

    private function seedPrivateNote(
        string $noteId,
        int $userId,
        string $workId,
        string $content,
        string $updatedAt = "2026-08-31 12:00:00.000000",
        int $version = 1
    ): void {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->privateNotes(),
            [
                "private_note_id" => $noteId,
                "user_id" => (string) $userId,
                "work_id" => $workId,
                "reading_round_id" => null,
                "note_content" => $content,
                "created_at" => "2026-08-01 10:00:00.000000",
                "updated_at" => $updatedAt,
                "note_version" => $version,
            ]
        ), $this->database->last_error);
    }

    /**
     * @return array{
     *     user_id: string,
     *     work_id: string,
     *     reading_round_id: ?string,
     *     note_content: string,
     *     note_version: string
     * }
     */
    private function storedPrivateNote(string $noteId): array
    {
        $row = $this->storedPrivateNoteOrNull($noteId);
        self::assertIsArray($row);

        return $row;
    }

    /**
     * @return null|array{
     *     user_id: string,
     *     work_id: string,
     *     reading_round_id: ?string,
     *     note_content: string,
     *     note_version: string
     * }
     */
    private function storedPrivateNoteOrNull(string $noteId): ?array
    {
        $row = $this->database->get_row($this->database->prepare(
            "SELECT user_id, work_id, reading_round_id, note_content, "
                . "note_version FROM `{$this->tableNames->privateNotes()}` "
                . "WHERE private_note_id = %s",
            $noteId
        ), ARRAY_A);

        if ($row === null) {
            return null;
        }

        self::assertIsArray($row);

        return $row;
    }

    /**
     * @param null|array{int, null|int, null|int} $startedOn
     * @param array{int, null|int, null|int} $finishedOn
     */
    private function seedHistoryRound(
        string $roundId,
        int $userId,
        string $workId,
        string $outcome,
        string $provenance,
        ?array $startedOn,
        array $finishedOn,
        ?string $itemId = null,
        ?string $externalLoanId = null
    ): void {
        $this->seedWork($workId, $workId);
        self::assertSame(1, $this->database->insert(
            $this->tableNames->readingRounds(),
            [
                "reading_round_id" => $roundId,
                "user_id" => (string) $userId,
                "work_id" => $workId,
                "item_id" => $itemId,
                "external_loan_id" => $externalLoanId,
                "started_at" => $provenance === "legacy_source_started"
                    ? "2024-01-01 10:00:00.000000"
                    : null,
                "round_outcome" => $outcome,
                "provenance" => $provenance,
                "reading_started_year" => $startedOn[0] ?? null,
                "reading_started_month" => $startedOn[1] ?? null,
                "reading_started_day" => $startedOn[2] ?? null,
                "reading_finished_year" => $finishedOn[0],
                "reading_finished_month" => $finishedOn[1],
                "reading_finished_day" => $finishedOn[2],
                "created_at" => "2026-08-01 10:00:00.000000",
                "updated_at" => "2026-08-29 10:00:00.000000",
                "ended_at" => "2026-08-29 10:00:00.000000",
                "round_version" => 1,
            ]
        ), $this->database->last_error);
    }

    private function seedRound(
        string $roundId,
        int $userId,
        string $workId,
        ?string $itemId,
        ?string $externalLoanId = null,
        ?string $outcome = null,
        int $version = 1
    ): void {
        $this->database->insert($this->tableNames->readingRounds(), [
            "reading_round_id" => $roundId,
            "user_id" => (string) $userId,
            "work_id" => $workId,
            "item_id" => $itemId,
            "external_loan_id" => $externalLoanId,
            "started_at" => null,
            "round_outcome" => $outcome,
            "provenance" => "source_started",
            "reading_started_year" => 2026,
            "reading_started_month" => 8,
            "reading_started_day" => 1,
            "reading_finished_year" => $outcome === null ? null : 2026,
            "reading_finished_month" => $outcome === null ? null : 8,
            "reading_finished_day" => $outcome === null ? null : 2,
            "created_at" => "2026-08-01 10:00:00.000000",
            "updated_at" => "2026-08-02 10:00:00.000000",
            "ended_at" => $outcome === null
                ? null
                : "2026-08-02 10:00:00.000000",
            "round_version" => $version,
        ]);
    }

    private function seedExternalLoan(
        string $loanId,
        int $userId,
        string $workId
    ): void {
        $this->database->insert($this->tableNames->externalLoans(), [
            "external_loan_id" => $loanId,
            "user_id" => (string) $userId,
            "work_id" => $workId,
            "loan_status" => "active",
            "borrowed_at" => "2026-08-01 10:00:00.000000",
            "due_at" => null,
        ]);
    }
}
