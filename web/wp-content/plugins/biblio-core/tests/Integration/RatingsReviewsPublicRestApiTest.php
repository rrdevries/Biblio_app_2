<?php

declare(strict_types=1);

namespace Biblio\Core\Tests\Integration;

use Biblio\Core\Assessments\{AssessmentStale,ContributionDuplicate,PublicationIneligible,PublicationNotAvailable,PublicationStale,RatingNotAvailable,ReviewNotAvailable};
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Infrastructure\WordPress\Rest\RestApi;
use Biblio\Core\Infrastructure\WordPress\Rest\RestErrorMapper;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class RatingsReviewsPublicRestApiTest extends PersistenceIntegrationTestCase
{
    /** @var list<int> */
    private array $users = [];
    private int $actorId;
    private int $otherId;
    private int $authorA;
    private int $authorB;
    private int $authorC;
    private WP_REST_Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actorId = $this->createUser("Reader", "reader");
        $this->otherId = $this->createUser("Other", "other");
        $this->authorA = $this->createUser("Author A", "author-a");
        $this->authorB = $this->createUser("Author B", "author-b");
        $this->authorC = $this->createUser("Author C", "author-c");
        wp_set_current_user(0);
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
        foreach ($this->users as $userId) {
            wp_delete_user($userId);
        }
        unset($GLOBALS["wp_rest_server"]);
        parent::tearDown();
    }

    public function testBoundaryRequiresAuthenticationAndCanViewCollection(): void
    {
        $this->seedLibrary("library-view", $this->actorId, "active", "view_only");
        $this->seedLibrary("library-inactive", $this->actorId, "inactive");
        $this->seedLibrary("library-foreign", $this->otherId);
        $this->seedWorkAndItem("library-view", "work-view", "item-view");

        $request = $this->request("library-view", "work-view");
        self::assertSame(401, $this->server->dispatch($request)->get_status());
        self::assertSame(401, $this->dispatchAs($this->actorId, $request, null)->get_status());
        self::assertSame(403, $this->dispatchAs($this->actorId, $request, "invalid")->get_status());

        $allowed = $this->data($this->dispatchAs($this->actorId, $request));
        self::assertSame("library-view", $allowed["library_id"]);
        self::assertSame("work-view", $allowed["work_id"]);
        self::assertSame([], $allowed["contributions"]);

        $foreign = $this->dispatchAs(
            $this->actorId,
            $this->request("library-foreign", "work-view")
        );
        $inactive = $this->dispatchAs(
            $this->actorId,
            $this->request("library-inactive", "work-view")
        );
        $missing = $this->dispatchAs(
            $this->actorId,
            $this->request("library-missing", "work-view")
        );
        foreach ([$foreign, $inactive, $missing] as $response) {
            self::assertSame(404, $response->get_status());
            self::assertSame($missing->get_data(), $response->get_data());
        }
    }

    public function testPublicPageIsBoundedAllowlistedIndependentAndOneVote(): void
    {
        $this->seedPublicScenario();
        $cursor = null;
        $contributions = [];

        do {
            $response = $this->data($this->dispatchAs(
                $this->actorId,
                $this->request("library-a", "work-a", [
                    "limit" => 2,
                    ...($cursor === null ? [] : ["cursor" => $cursor]),
                ])
            ));
            self::assertSame([
                "library_id",
                "work_id",
                "contributions",
                "aggregate",
                "next_cursor",
            ], array_keys($response));
            self::assertSame(
                ["average" => 4.5, "voter_count" => 2],
                $response["aggregate"]
            );
            $contributions = [...$contributions, ...$response["contributions"]];
            $cursor = $response["next_cursor"];
            if ($cursor !== null) {
                self::assertStringNotContainsString("publication-", $cursor);
            }
        } while ($cursor !== null);

        self::assertCount(5, $contributions);
        self::assertCount(2, array_filter(
            $contributions,
            static fn (array $row): bool =>
                $row["type"] === "rating"
                && $row["display_name"] === "Author A"
        ));
        self::assertSame(5, count(array_filter(
            $contributions,
            static fn (array $row): bool => in_array(
                $row["display_name"],
                ["Author A", "Author B", "Author C"],
                true
            )
        )));
        $reviewWithoutRating = array_values(array_filter(
            $contributions,
            static fn (array $row): bool =>
                $row["type"] === "review"
                && $row["display_name"] === "Author C"
        ))[0];
        self::assertNull($reviewWithoutRating["rating"]);
        self::assertStringContainsString("&lt;script&gt;", $reviewWithoutRating["review_html"]);
        self::assertStringNotContainsString("<script>", $reviewWithoutRating["review_html"]);
        $reviewWithRating = array_values(array_filter(
            $contributions,
            static fn (array $row): bool =>
                $row["type"] === "review"
                && $row["display_name"] === "Author A"
        ))[0];
        self::assertSame(5.0, $reviewWithRating["rating"]);

        $serialized = (string) wp_json_encode($contributions);
        foreach ([
            "publication-",
            "rating-",
            "review-",
            "round-",
            "moderation",
            "membership",
            "user_id",
            "Private review",
            "Library B only",
        ] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $serialized);
        }

        foreach ([
            [],
            ["limit" => 50],
        ] as $query) {
            self::assertCount(5, $this->data($this->dispatchAs(
                $this->actorId,
                $this->request("library-a", "work-a", $query)
            ))["contributions"]);
        }
        foreach ([
            ["limit" => 0],
            ["limit" => 51],
            ["limit" => "1.5"],
            ["cursor" => "***"],
            ["user_id" => (string) $this->authorA],
        ] as $query) {
            self::assertSame(400, $this->dispatchAs(
                $this->actorId,
                $this->request("library-a", "work-a", $query)
            )->get_status());
        }
    }

    public function testAggregateFallbackVisibilityPresenceAndLibraryIsolation(): void
    {
        $this->seedPublicScenario();
        $this->database->update(
            $this->tableNames->memberships(),
            ["membership_status" => "inactive"],
            ["library_id" => "library-a", "user_id" => (string) $this->authorA]
        );
        self::assertSame(4.5, $this->readScenario()["aggregate"]["average"]);

        $this->setPublication("publication-a-new", "author_status", "withdrawn");
        self::assertSame(3.5, $this->readScenario()["aggregate"]["average"]);
        $this->setPublication("publication-a-new", "author_status", "active");
        $this->setPublication("publication-a-new", "moderation_status", "hidden", true);
        self::assertSame(3.5, $this->readScenario()["aggregate"]["average"]);
        $this->setPublication("publication-a-new", "moderation_status", "removed", true);
        self::assertSame(3.5, $this->readScenario()["aggregate"]["average"]);

        self::assertSame(1, $this->database->delete(
            $this->tableNames->ratings(),
            ["rating_id" => "rating-a-new"]
        ));
        self::assertSame(3.5, $this->readScenario()["aggregate"]["average"]);

        self::assertSame(1, $this->database->delete(
            $this->tableNames->items(),
            ["item_id" => "item-a"]
        ));
        $suppressed = $this->readScenario();
        self::assertSame([], $suppressed["contributions"]);
        self::assertSame(
            ["average" => null, "voter_count" => 0],
            $suppressed["aggregate"]
        );
        $this->seedItem("item-a-restored", "library-a", "work-a");
        self::assertSame(3.5, $this->readScenario()["aggregate"]["average"]);
        self::assertStringNotContainsString(
            "Library B only",
            (string) wp_json_encode($this->readScenario())
        );
    }

    public function testDeletingNewestRatingCascadesAndFallsBack(): void
    {
        $this->seedPublicScenario();
        self::assertSame(1, $this->database->delete(
            $this->tableNames->ratings(),
            ["rating_id" => "rating-a-new"]
        ));
        self::assertSame(0, (int) $this->database->get_var(
            "SELECT COUNT(*) FROM `{$this->tableNames->contributionPublications()}` "
            . "WHERE publication_id='publication-a-new'"
        ));
        self::assertSame(3.5, $this->readScenario()["aggregate"]["average"]);
    }

    public function testPublicAverageUsesRatingIdDescendingForEqualTimes(): void
    {
        $this->seedPublicScenario();
        self::assertSame(1, $this->database->update(
            $this->tableNames->ratings(),
            ["updated_at" => "2026-09-03 10:01:00.000000"],
            ["rating_id" => "rating-a-old"]
        ));
        self::assertSame(3.5, $this->readScenario()["aggregate"]["average"]);
    }

    public function testAssessmentErrorsHaveSafeStableMappings(): void
    {
        $mapper = new RestErrorMapper();
        foreach ([
            new RatingNotAvailable(),
            new ReviewNotAvailable(),
            new PublicationNotAvailable(),
        ] as $failure) {
            $error = $mapper->map($failure);
            self::assertSame(404, $error->get_error_data()["status"]);
            self::assertSame("biblio_resource_not_available", $error->get_error_code());
        }
        foreach ([
            new AssessmentStale(),
            new PublicationStale(),
            new ContributionDuplicate(),
        ] as $failure) {
            self::assertSame(409, $mapper->map($failure)->get_error_data()["status"]);
        }
        self::assertSame(
            422,
            $mapper->map(new PublicationIneligible())->get_error_data()["status"]
        );
    }

    /** @return array<string, mixed> */
    private function readScenario(): array
    {
        return $this->data($this->dispatchAs(
            $this->actorId,
            $this->request("library-a", "work-a", ["limit" => 50])
        ));
    }

    private function seedPublicScenario(): void
    {
        $this->seedLibrary("library-a", $this->actorId, "active", "view_only");
        $this->addMembership("library-a", $this->authorA);
        $this->addMembership("library-a", $this->authorB);
        $this->seedLibrary("library-b", $this->actorId);
        $this->seedWorkAndItem("library-a", "work-a", "item-a");
        $this->seedItem("item-b", "library-b", "work-a");
        $this->seedRound("round-a-new", $this->authorA, "work-a");

        $this->seedRating("rating-a-old", $this->authorA, null, 6, "10:00:00");
        $this->seedRating("rating-a-new", $this->authorA, "round-a-new", 10, "10:01:00");
        $this->seedRating("rating-b", $this->authorB, null, 8, "10:00:30");
        $this->seedRating("rating-private", $this->authorC, null, 2, "10:02:00");
        $this->seedReview("review-a", $this->authorA, "round-a-new", "Public review");
        $this->seedReview("review-c", $this->authorC, null, "<script>review</script>");
        $this->seedReview("review-private", $this->authorB, null, "Private review");
        $this->seedReview("review-hidden", $this->authorC, "round-a-hidden", "Hidden review", true);

        $this->seedPublication("publication-a-old", "library-a", "rating-a-old", null, "10:00:00");
        $this->seedPublication("publication-a-new", "library-a", "rating-a-new", null, "10:01:00");
        $this->seedPublication("publication-b", "library-a", "rating-b", null, "10:00:30");
        $this->seedPublication("publication-review-a", "library-a", null, "review-a", "10:03:00");
        $this->seedPublication("publication-review-c", "library-a", null, "review-c", "10:04:00");
        $this->seedPublication("publication-review-hidden", "library-a", null, "review-hidden", "10:05:00", "active", "hidden");

        $this->seedRating("rating-library-b", $this->otherId, "round-library-b", 10, "10:06:00", true);
        $this->seedReview("review-library-b", $this->otherId, "round-library-b-review", "Library B only", true);
        $this->seedPublication("publication-library-b-rating", "library-b", "rating-library-b", null, "10:06:00");
        $this->seedPublication("publication-library-b-review", "library-b", null, "review-library-b", "10:07:00");
    }

    private function seedLibrary(
        string $libraryId,
        int $memberId,
        string $status = "active",
        string $access = "direct"
    ): void {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->libraries(),
            [
                "library_id" => $libraryId,
                "library_name" => $libraryId,
                "library_type" => "private_library",
                "library_status" => "active",
            ]
        ), $this->database->last_error);
        $this->addMembership($libraryId, $memberId, $status, $access);
    }

    private function addMembership(
        string $libraryId,
        int $userId,
        string $status = "active",
        string $access = "direct"
    ): void {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->memberships(),
            [
                "library_id" => $libraryId,
                "user_id" => (string) $userId,
                "membership_status" => $status,
                "management_role" => "member",
                "use_access" => $access,
                "additional_permissions" => "[]",
            ]
        ), $this->database->last_error);
    }

    private function seedWorkAndItem(
        string $libraryId,
        string $workId,
        string $itemId
    ): void {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->works(),
            ["work_id" => $workId, "work_title" => $workId]
        ), $this->database->last_error);
        $this->seedItem($itemId, $libraryId, $workId);
    }

    private function seedItem(string $itemId, string $libraryId, string $workId): void
    {
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

    private function seedRound(string $roundId, int $userId, string $workId): void
    {
        self::assertSame(1, $this->database->insert(
            $this->tableNames->readingRounds(),
            [
                "reading_round_id" => $roundId,
                "user_id" => (string) $userId,
                "work_id" => $workId,
                "item_id" => null,
                "external_loan_id" => null,
                "started_at" => null,
                "round_outcome" => "completed",
                "provenance" => "historical_manual",
                "reading_started_year" => 2026,
                "reading_started_month" => 9,
                "reading_started_day" => 1,
                "reading_finished_year" => 2026,
                "reading_finished_month" => 9,
                "reading_finished_day" => 2,
                "created_at" => "2026-09-03 09:00:00.000000",
                "updated_at" => "2026-09-03 09:00:00.000000",
                "ended_at" => "2026-09-03 09:00:00.000000",
                "round_version" => 1,
            ]
        ), $this->database->last_error);
    }

    private function seedRating(
        string $ratingId,
        int $userId,
        ?string $roundId,
        int $halfUnits,
        string $time,
        bool $createRound = false
    ): void {
        if ($createRound && $roundId !== null) {
            $this->seedRound($roundId, $userId, "work-a");
        }
        self::assertSame(1, $this->database->insert(
            $this->tableNames->ratings(),
            [
                "rating_id" => $ratingId,
                "user_id" => (string) $userId,
                "work_id" => "work-a",
                "reading_round_id" => $roundId,
                "rating_half_units" => $halfUnits,
                "created_at" => "2026-09-03 {$time}.000000",
                "updated_at" => "2026-09-03 {$time}.000000",
                "rating_version" => 1,
            ]
        ), $this->database->last_error);
    }

    private function seedReview(
        string $reviewId,
        int $userId,
        ?string $roundId,
        string $content,
        bool $createRound = false
    ): void {
        if ($createRound && $roundId !== null) {
            $this->seedRound($roundId, $userId, "work-a");
        }
        self::assertSame(1, $this->database->insert(
            $this->tableNames->reviews(),
            [
                "review_id" => $reviewId,
                "user_id" => (string) $userId,
                "work_id" => "work-a",
                "reading_round_id" => $roundId,
                "review_content" => $content,
                "created_at" => "2026-09-03 10:00:00.000000",
                "updated_at" => "2026-09-03 10:00:00.000000",
                "review_version" => 1,
            ]
        ), $this->database->last_error);
    }

    private function seedPublication(
        string $publicationId,
        string $libraryId,
        ?string $ratingId,
        ?string $reviewId,
        string $time,
        string $authorStatus = "active",
        string $moderationStatus = "visible"
    ): void {
        $moderated = $moderationStatus === "visible" ? null : "test moderation";
        self::assertSame(1, $this->database->insert(
            $this->tableNames->contributionPublications(),
            [
                "publication_id" => $publicationId,
                "library_id" => $libraryId,
                "rating_id" => $ratingId,
                "review_id" => $reviewId,
                "author_status" => $authorStatus,
                "moderation_status" => $moderationStatus,
                "moderation_reason" => $moderated,
                "moderator_user_id" => $moderated === null
                    ? null
                    : (string) $this->actorId,
                "moderated_at" => $moderated === null
                    ? null
                    : "2026-09-03 {$time}.000000",
                "published_at" => "2026-09-03 {$time}.000000",
                "updated_at" => "2026-09-03 {$time}.000000",
                "publication_version" => 1,
            ]
        ), $this->database->last_error);
    }

    private function setPublication(
        string $publicationId,
        string $field,
        string $value,
        bool $withModeration = false
    ): void {
        $data = [$field => $value];
        if ($withModeration) {
            $data += [
                "moderation_reason" => "test moderation",
                "moderator_user_id" => (string) $this->actorId,
                "moderated_at" => "2026-09-03 11:00:00.000000",
            ];
        }
        self::assertSame(1, $this->database->update(
            $this->tableNames->contributionPublications(),
            $data,
            ["publication_id" => $publicationId]
        ), $this->database->last_error);
    }

    /** @param array<string, mixed> $query */
    private function request(
        string $libraryId,
        string $workId,
        array $query = []
    ): WP_REST_Request {
        $request = new WP_REST_Request(
            "GET",
            "/biblio/v1/libraries/{$libraryId}/works/{$workId}/assessments"
        );
        $request->set_query_params($query);
        return $request;
    }

    private function dispatchAs(
        int $userId,
        WP_REST_Request $request,
        ?string $nonce = "valid"
    ): WP_REST_Response {
        wp_set_current_user($userId);
        $GLOBALS["wp_rest_auth_cookie"] = true;
        if ($nonce === null) {
            unset($_SERVER["HTTP_X_WP_NONCE"]);
        } else {
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

    /** @return array<string, mixed> */
    private function data(WP_REST_Response $response): array
    {
        self::assertSame(200, $response->get_status());
        $payload = $response->get_data();
        self::assertIsArray($payload);
        self::assertIsArray($payload["data"] ?? null);
        return $payload["data"];
    }

    private function createUser(string $displayName, string $slug): int
    {
        $suffix = bin2hex(random_bytes(5));
        $id = wp_insert_user([
            "user_login" => "biblio-b7-{$slug}-{$suffix}",
            "user_pass" => bin2hex(random_bytes(12)),
            "display_name" => $displayName,
            "user_email" => "{$slug}-{$suffix}@example.test",
        ]);
        if ($id instanceof WP_Error) {
            throw new RuntimeException($id->get_error_message());
        }
        $this->users[] = (int) $id;
        return (int) $id;
    }
}
