<?php

declare(strict_types=1);

use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Biblio\Core\Infrastructure\WordPress\Rest\RestApi;

if ($argc !== 8) {
    fwrite(
        STDERR,
        "Expected action, user, work, edition-or-dash, entry-or-dash, ready and release.\n"
    );
    exit(2);
}

[
    ,
    $wishlistRestAction,
    $wishlistRestUser,
    $wishlistRestWork,
    $wishlistRestEdition,
    $wishlistRestEntry,
    $wishlistRestReady,
    $wishlistRestRelease,
] = $argv;
require dirname(__DIR__) . "/bootstrap.php";

if (file_put_contents($wishlistRestReady, "ready") === false) {
    throw new RuntimeException("Could not signal Wishlist REST worker readiness.");
}
$deadline = microtime(true) + 15;
while (!is_file($wishlistRestRelease)) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException("Wishlist REST mutation barrier timed out.");
    }
    usleep(10_000);
}

$server = new WP_REST_Server();
$GLOBALS["wp_rest_server"] = $server;
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
wp_set_current_user((int) $wishlistRestUser);
$GLOBALS["wp_rest_auth_cookie"] = true;
$_SERVER["HTTP_X_WP_NONCE"] = wp_create_nonce("wp_rest");
rest_cookie_check_errors(null);

if ($wishlistRestAction === "add_work") {
    $request = new WP_REST_Request("POST", "/biblio/v1/me/wishlist");
    $body = [
        "target" => ["type" => "work_only", "work_id" => $wishlistRestWork],
    ];
} elseif ($wishlistRestAction === "add_edition") {
    $request = new WP_REST_Request("POST", "/biblio/v1/me/wishlist");
    $body = [
        "target" => [
            "type" => "edition_specific",
            "work_id" => $wishlistRestWork,
            "edition_id" => $wishlistRestEdition,
        ],
    ];
} elseif ($wishlistRestAction === "refine") {
    $request = new WP_REST_Request(
        "PATCH",
        "/biblio/v1/me/wishlist/{$wishlistRestEntry}"
    );
    $body = [
        "target" => [
            "type" => "edition_specific",
            "edition_id" => $wishlistRestEdition,
        ],
    ];
} else {
    throw new RuntimeException("Unknown Wishlist REST worker action.");
}

$request->set_header("content-type", "application/json");
$request->set_body((string) wp_json_encode($body));
$response = $server->dispatch($request);
$data = $response->get_data();
$payload = ["status" => $response->get_status()];
if (is_array($data)) {
    $payload["code"] = $data["code"] ?? null;
    $payload["entry_id"] = $data["data"]["wishlist_entry_id"] ?? null;
}
fwrite(STDOUT, json_encode($payload, JSON_THROW_ON_ERROR) . "\n");
