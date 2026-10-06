<?php

declare(strict_types=1);

define("ABSPATH", __DIR__ . "/wordpress/");

class WP_User
{
}

class WP_Error
{
}

$biblioUiTestUserCanManage = false;

function user_can(WP_User $user, string $capability): bool
{
    global $biblioUiTestUserCanManage;

    return $capability === "manage_options" && $biblioUiTestUserCanManage;
}

/** @var array<string, list<callable>> $biblioUiTestActions */
$biblioUiTestActions = [];
/** @var array<string, list<callable>> $biblioUiTestFilters */
$biblioUiTestFilters = [];
/** @var array<string, callable> $biblioUiTestShortcodes */
$biblioUiTestShortcodes = [];
/** @var array<string, int> $biblioUiTestShortcodeRegistrations */
$biblioUiTestShortcodeRegistrations = [];
/** @var array<string, array<string, mixed>> $biblioUiTestRegisteredModules */
$biblioUiTestRegisteredModules = [];
/** @var array<string, array<string, mixed>> $biblioUiTestRegisteredStyles */
$biblioUiTestRegisteredStyles = [];
/** @var list<string> $biblioUiTestEnqueuedModules */
$biblioUiTestEnqueuedModules = [];
/** @var list<string> $biblioUiTestEnqueuedStyles */
$biblioUiTestEnqueuedStyles = [];
/** @var list<int|string|array<int|string>> $biblioUiTestPageChecks */
$biblioUiTestPageChecks = [];
/** @var list<string> $biblioUiTestRestPaths */
$biblioUiTestRestPaths = [];
/** @var list<string> $biblioUiTestNonceActions */
$biblioUiTestNonceActions = [];
/** @var list<string> $biblioUiTestHomePaths */
$biblioUiTestHomePaths = [];
/** @var list<string> $biblioUiTestLoginRedirects */
$biblioUiTestLoginRedirects = [];
/** @var list<string> $biblioUiTestLogoutRedirects */
$biblioUiTestLogoutRedirects = [];
$biblioUiTestLoggedIn = false;
$biblioUiTestCurrentUser = (object) [
    "display_name" => "",
    "user_login" => "",
];
$biblioUiTestCurrentPageSlug = null;

function add_action(
    string $hookName,
    callable $callback,
    int $priority = 10,
    int $acceptedArguments = 1
): true {
    global $biblioUiTestActions;

    $biblioUiTestActions[$hookName][] = $callback;

    return true;
}

function add_filter(
    string $hookName,
    callable $callback,
    int $priority = 10,
    int $acceptedArguments = 1
): true {
    global $biblioUiTestFilters;

    $biblioUiTestFilters[$hookName][] = $callback;

    return true;
}

function add_shortcode(string $shortcodeTag, callable $callback): void
{
    global $biblioUiTestShortcodes, $biblioUiTestShortcodeRegistrations;

    $biblioUiTestShortcodes[$shortcodeTag] = $callback;
    $biblioUiTestShortcodeRegistrations[$shortcodeTag] =
        ($biblioUiTestShortcodeRegistrations[$shortcodeTag] ?? 0) + 1;
}

/**
 * @param array<string|array<string, string>> $dependencies
 * @param array<string, string|bool> $arguments
 */
function wp_register_script_module(
    string $id,
    string $source,
    array $dependencies = [],
    string|false|null $version = false,
    array $arguments = []
): void {
    global $biblioUiTestRegisteredModules;

    $biblioUiTestRegisteredModules[$id] = [
        "source" => $source,
        "dependencies" => $dependencies,
        "version" => $version,
        "arguments" => $arguments,
    ];
}

/**
 * @param array<string|array<string, string>> $dependencies
 * @param array<string, string|bool> $arguments
 */
function wp_enqueue_script_module(
    string $id,
    string $source = "",
    array $dependencies = [],
    string|false|null $version = false,
    array $arguments = []
): void {
    global $biblioUiTestEnqueuedModules;

    $biblioUiTestEnqueuedModules[] = $id;
}

/** @param list<string> $dependencies */
function wp_register_style(
    string $handle,
    string $source,
    array $dependencies = [],
    string|false|null $version = false,
    string $media = "all"
): true {
    global $biblioUiTestRegisteredStyles;

    $biblioUiTestRegisteredStyles[$handle] = [
        "source" => $source,
        "dependencies" => $dependencies,
        "version" => $version,
        "media" => $media,
    ];

    return true;
}

/** @param list<string> $dependencies */
function wp_enqueue_style(
    string $handle,
    string $source = "",
    array $dependencies = [],
    string|false|null $version = false,
    string $media = "all"
): void {
    global $biblioUiTestEnqueuedStyles;

    $biblioUiTestEnqueuedStyles[] = $handle;
}

function plugin_dir_url(string $pluginFile): string
{
    return "https://example.test/wp-content/plugins/biblio-ui/";
}

/** @param int|string|array<int|string> $page */
function is_page(int|string|array $page = ""): bool
{
    global $biblioUiTestCurrentPageSlug, $biblioUiTestPageChecks;

    $biblioUiTestPageChecks[] = $page;

    if (is_array($page)) {
        return in_array($biblioUiTestCurrentPageSlug, $page, true);
    }

    return is_string($page) && $page === $biblioUiTestCurrentPageSlug;
}

function rest_url(string $path = "", string $scheme = "rest"): string
{
    global $biblioUiTestRestPaths;

    $biblioUiTestRestPaths[] = $path;

    return 'https://example.test/wp-json/' . $path . '?context="mount"&next=1';
}

function wp_create_nonce(int|string $action = -1): string
{
    global $biblioUiTestNonceActions;

    $biblioUiTestNonceActions[] = (string) $action;

    return 'nonce-"<&';
}

function home_url(string $path = "", ?string $scheme = null): string
{
    global $biblioUiTestHomePaths;

    $biblioUiTestHomePaths[] = $path;

    return "https://example.test" . $path;
}

function admin_url(string $path = ""): string
{
    return "https://example.test/wp-admin/" . $path;
}

function wp_login_url(string $redirect = "", bool $forceReauthentication = false): string
{
    global $biblioUiTestLoginRedirects;

    $biblioUiTestLoginRedirects[] = $redirect;

    return "https://example.test/wp-login.php?redirect_to="
        . rawurlencode($redirect)
        . '&reason="session"';
}

function is_user_logged_in(): bool
{
    global $biblioUiTestLoggedIn;

    return $biblioUiTestLoggedIn;
}

function wp_get_current_user(): object
{
    global $biblioUiTestCurrentUser;

    return $biblioUiTestCurrentUser;
}

function wp_logout_url(string $redirect = ""): string
{
    global $biblioUiTestLogoutRedirects;

    $biblioUiTestLogoutRedirects[] = $redirect;

    return "https://example.test/wp-login.php?action=logout&redirect_to="
        . rawurlencode($redirect)
        . '&_wpnonce="logout"';
}

/** @param null|list<string> $protocols */
function esc_url(
    string $url,
    ?array $protocols = null,
    string $context = "display"
): string
{
    return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8");
}

function biblioUiAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message
            . " Expected "
            . var_export($expected, true)
            . ", received "
            . var_export($actual, true)
            . "."
        );
    }
}

function biblioUiAssertContains(
    string $needle,
    string $haystack,
    string $message
): void {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . " Missing " . $needle . ".");
    }
}

function biblioUiAssertFalse(bool $actual, string $message): void
{
    biblioUiAssertSame(false, $actual, $message);
}

function biblioUiRunAction(string $hookName): void
{
    global $biblioUiTestActions;

    foreach ($biblioUiTestActions[$hookName] ?? [] as $callback) {
        $callback();
    }
}

/** @param list<string> $value
 *  @return list<string>
 */
function biblioUiRunFilter(string $hookName, array $value): array
{
    global $biblioUiTestFilters;

    foreach ($biblioUiTestFilters[$hookName] ?? [] as $callback) {
        $value = $callback($value);
    }

    return $value;
}

biblioUiAssertFalse(
    class_exists("Elementor\\Plugin", false),
    "The isolated smoke test must not load Elementor."
);
biblioUiAssertFalse(
    class_exists("Biblio\\Core\\Plugin", false),
    "The isolated smoke test must not load Biblio Core."
);

require_once __DIR__ . "/../src/AccountMount.php";
require_once __DIR__ . "/../src/LoginPresentation.php";
require_once __DIR__ . "/../src/LibraryNamePresentation.php";
require_once __DIR__ . "/../src/LibraryAppShortcode.php";
require_once __DIR__ . "/../src/EntryAppShortcode.php";
require_once __DIR__ . "/../src/PublicHomeShortcode.php";
require_once __DIR__ . "/../src/SettingsAppShortcode.php";
require_once __DIR__ . "/../src/NextReadingAppShortcode.php";
require_once __DIR__ . "/../src/WishlistAppShortcode.php";
require_once __DIR__ . "/../src/SearchAppShortcode.php";
require_once __DIR__ . "/../src/Plugin.php";

$plugin = new \Biblio\UI\Plugin("/plugin/biblio-ui.php");
$plugin->boot();
$plugin->boot();

biblioUiAssertSame(
    7,
    count($biblioUiTestActions["init"] ?? []),
    "Plugin boot must register all seven init hooks exactly once."
);
biblioUiAssertSame(
    1,
    count($biblioUiTestActions["wp_enqueue_scripts"] ?? []),
    "Plugin boot must register the asset hook exactly once."
);
biblioUiAssertSame(
    1,
    count($biblioUiTestFilters["body_class"] ?? []),
    "Plugin boot must register the Page Shell body-class filter exactly once."
);
biblioUiAssertSame(
    1,
    count($biblioUiTestActions["login_enqueue_scripts"] ?? []),
    "Login presentation must register its style hook exactly once."
);
biblioUiAssertSame(
    1,
    count($biblioUiTestActions["login_form"] ?? []),
    "Login presentation must mark whether an admin destination was explicitly requested."
);
biblioUiRunAction("login_enqueue_scripts");
biblioUiAssertSame(
    [\Biblio\UI\LoginPresentation::STYLE_HANDLE],
    $biblioUiTestEnqueuedStyles,
    "WordPress login screens must load only the bounded Biblio login style."
);
$loginPresentation = new \Biblio\UI\LoginPresentation("/plugin/biblio-ui.php");
biblioUiAssertSame(
    "Inloggen bij Biblio",
    $loginPresentation->pageTitle("Login ‹ Biblio V2 — WordPress", "Login"),
    "The normal login page must use the Biblio title."
);
biblioUiAssertSame(
    '<div class="biblio-login__intro"><h1>Inloggen bij Biblio</h1></div>',
    $loginPresentation->intro(""),
    "The login page must have one title without the former subtitle."
);
biblioUiAssertSame(
    "https://example.test/",
    $loginPresentation->headerUrl("https://wordpress.org/"),
    "The login wordmark must return to the public Biblio start page."
);
biblioUiAssertSame(
    '<a href="https://example.test/">Terug naar startpagina</a>',
    $loginPresentation->returnLink('<a href="https://example.test/">Back to site</a>'),
    "The login and recovery return link must name and open the public Biblio start page."
);
biblioUiAssertSame(
    "Inloggen",
    $loginPresentation->translateLoginLabel("Login", "Log In", "default"),
    "The WordPress login control must use Dutch Biblio copy."
);
biblioUiAssertSame(
    "Inloggen",
    $loginPresentation->translateLoginLabel("Login", "Log in", "default"),
    "The recovery page must link back with the same Dutch login label."
);
biblioUiAssertSame(
    "Anmelden",
    $loginPresentation->translateLoginLabel("Anmelden", "Log In", "other"),
    "The login translation must leave other domains unchanged."
);
$_GET["redirect_to"] = admin_url();
ob_start();
$loginPresentation->redirectIntentField();
biblioUiAssertSame(
    '<input type="hidden" name="biblio_redirect_requested" value="1">',
    ob_get_clean(),
    "An explicitly requested WordPress admin page must be marked."
);
unset($_GET["redirect_to"]);
ob_start();
$loginPresentation->redirectIntentField();
biblioUiAssertSame(
    '<input type="hidden" name="biblio_redirect_requested" value="0">',
    ob_get_clean(),
    "The WordPress admin form default must not count as an explicit destination."
);
biblioUiAssertSame(
    "https://example.test/mijn-biblio/",
    $loginPresentation->defaultRedirect(
        "https://example.test/wp-admin/",
        "https://example.test/wp-admin/",
        new WP_User()
    ),
    "WordPress's hidden admin URL must not override the ordinary Biblio default."
);
$_POST["biblio_redirect_requested"] = "1";
biblioUiAssertSame(
    "https://example.test/wp-admin/",
    $loginPresentation->defaultRedirect(
        "https://example.test/wp-admin/",
        "https://example.test/wp-admin/",
        new WP_User()
    ),
    "An explicitly requested safe WordPress admin page must keep priority."
);
unset($_POST["biblio_redirect_requested"]);
biblioUiAssertSame(
    "https://example.test/mijn-biblio/",
    $loginPresentation->defaultRedirect("https://example.test/wp-admin/", "", new WP_User()),
    "A normal account without a requested destination must enter Mijn Biblio."
);
biblioUiAssertSame(
    "https://example.test/verlanglijst/",
    $loginPresentation->defaultRedirect(
        "https://example.test/verlanglijst/",
        "https://example.test/verlanglijst/",
        new WP_User()
    ),
    "An explicit Biblio destination must survive login."
);
biblioUiAssertSame(
    "https://example.test/mijn-bibliotheek/?library_id=library-2&item_id=item-3",
    $loginPresentation->defaultRedirect(
        "https://example.test/mijn-bibliotheek/?library_id=library-2&item_id=item-3",
        "https://example.test/mijn-bibliotheek/?library_id=library-2&item_id=item-3",
        new WP_User()
    ),
    "A safe direct Library link must retain its explicit context after login."
);
biblioUiAssertSame(
    "https://example.test/wp-admin/",
    $loginPresentation->defaultRedirect(
        "https://example.test/wp-admin/",
        "https://external.example.test/unsafe",
        new WP_User()
    ),
    "An unsafe requested destination must not be restored over WordPress's safe fallback."
);
biblioUiAssertSame(
    "https://example.test/wp-admin/",
    $loginPresentation->defaultRedirect("https://example.test/wp-admin/", "", new WP_Error()),
    "A failed login must retain the WordPress destination."
);
$biblioUiTestUserCanManage = true;
biblioUiAssertSame(
    "https://example.test/wp-admin/",
    $loginPresentation->defaultRedirect("https://example.test/wp-admin/", "", new WP_User()),
    "A platform administrator must retain the standard admin destination."
);
$biblioUiTestUserCanManage = false;
$biblioUiTestHomePaths = [];
$biblioUiTestEnqueuedStyles = [];
biblioUiAssertSame(
    ["existing"],
    biblioUiRunFilter("body_class", ["existing"]),
    "Non-Library pages must keep their body classes unchanged."
);
$biblioUiTestCurrentPageSlug = \Biblio\UI\LibraryAppShortcode::PAGE_SLUG;
biblioUiAssertSame(
    ["existing", \Biblio\UI\Plugin::PAGE_BODY_CLASS],
    biblioUiRunFilter("body_class", ["existing"]),
    "The Library Page must receive the scoped App Shell body class."
);
$biblioUiTestCurrentPageSlug = \Biblio\UI\NextReadingAppShortcode::PAGE_SLUG;
biblioUiAssertSame(
    ["existing", \Biblio\UI\Plugin::PAGE_BODY_CLASS],
    biblioUiRunFilter("body_class", ["existing"]),
    "The Next Reading Page must receive the scoped App Shell body class."
);
$biblioUiTestCurrentPageSlug = \Biblio\UI\WishlistAppShortcode::PAGE_SLUG;
biblioUiAssertSame(
    ["existing", \Biblio\UI\Plugin::PAGE_BODY_CLASS],
    biblioUiRunFilter("body_class", ["existing"]),
    "The Wishlist Page must receive the scoped App Shell body class."
);
$biblioUiTestCurrentPageSlug = \Biblio\UI\SearchAppShortcode::PAGE_SLUG;
biblioUiAssertSame(
    ["existing", \Biblio\UI\Plugin::PAGE_BODY_CLASS],
    biblioUiRunFilter("body_class", ["existing"]),
    "The Search Page must receive the scoped App Shell body class."
);
$biblioUiTestCurrentPageSlug = null;
$biblioUiTestPageChecks = [];

biblioUiRunAction("init");
biblioUiRunAction("init");

biblioUiAssertSame(
    1,
    $biblioUiTestShortcodeRegistrations[\Biblio\UI\NextReadingAppShortcode::TAG]
        ?? 0,
    "The Next Reading app shortcode must register exactly once."
);

$nextReadingShortcode =
    $biblioUiTestShortcodes[\Biblio\UI\NextReadingAppShortcode::TAG] ?? null;
if (!is_callable($nextReadingShortcode)) {
    throw new RuntimeException("The Next Reading app shortcode is not callable.");
}
$nextReadingMount = $nextReadingShortcode(
    [],
    null,
    \Biblio\UI\NextReadingAppShortcode::TAG
);
biblioUiAssertContains(
    "data-biblio-next-reading-root",
    $nextReadingMount,
    "The Next Reading mount marker is missing."
);
biblioUiAssertContains(
    'data-login-url="https://example.test/wp-login.php?redirect_to='
        . 'https%3A%2F%2Fexample.test%2Fhierna-lezen%2F',
    $nextReadingMount,
    "The Next Reading login redirect is missing."
);
biblioUiAssertContains(
    'data-search-url="https://example.test/zoeken/"',
    $nextReadingMount,
    "The Next Reading shared Search destination is missing."
);
biblioUiAssertContains(
    'data-wishlist-url="https://example.test/verlanglijst/"',
    $nextReadingMount,
    "The Next Reading shared Wishlist destination is missing."
);

biblioUiAssertSame(
    1,
    $biblioUiTestShortcodeRegistrations[\Biblio\UI\WishlistAppShortcode::TAG]
        ?? 0,
    "The Wishlist app shortcode must register exactly once."
);
$wishlistShortcode =
    $biblioUiTestShortcodes[\Biblio\UI\WishlistAppShortcode::TAG] ?? null;
if (!is_callable($wishlistShortcode)) {
    throw new RuntimeException("The Wishlist app shortcode is not callable.");
}
$wishlistMount = $wishlistShortcode(
    [],
    null,
    \Biblio\UI\WishlistAppShortcode::TAG
);
biblioUiAssertContains(
    "data-biblio-wishlist-root",
    $wishlistMount,
    "The Wishlist mount marker is missing."
);
biblioUiAssertContains(
    'data-search-url="https://example.test/zoeken/"',
    $wishlistMount,
    "The Wishlist shared Search destination is missing."
);
biblioUiAssertContains(
    'data-wishlist-url="https://example.test/verlanglijst/"',
    $wishlistMount,
    "The canonical Wishlist URL is missing."
);
biblioUiAssertContains(
    'data-next-reading-url="https://example.test/hierna-lezen/"',
    $wishlistMount,
    "The Wishlist shared Next Reading destination is missing."
);

biblioUiAssertSame(
    1,
    $biblioUiTestShortcodeRegistrations[\Biblio\UI\SearchAppShortcode::TAG]
        ?? 0,
    "The Search app shortcode must register exactly once."
);
$searchShortcode =
    $biblioUiTestShortcodes[\Biblio\UI\SearchAppShortcode::TAG] ?? null;
if (!is_callable($searchShortcode)) {
    throw new RuntimeException("The Search app shortcode is not callable.");
}
$searchMount = $searchShortcode([], null, \Biblio\UI\SearchAppShortcode::TAG);
biblioUiAssertContains(
    "data-biblio-search-root",
    $searchMount,
    "The Search mount marker is missing."
);
biblioUiAssertContains(
    'data-search-url="https://example.test/zoeken/"',
    $searchMount,
    "The canonical Search URL is missing."
);
biblioUiAssertContains(
    'data-login-url="https://example.test/wp-login.php?redirect_to='
        . 'https%3A%2F%2Fexample.test%2Fzoeken%2F',
    $searchMount,
    "The Search login redirect is missing."
);

biblioUiAssertSame(
    1,
    $biblioUiTestShortcodeRegistrations[\Biblio\UI\LibraryAppShortcode::TAG]
        ?? 0,
    "The Library app shortcode must register exactly once."
);

$shortcodeCallback =
    $biblioUiTestShortcodes[\Biblio\UI\LibraryAppShortcode::TAG] ?? null;

if (!is_callable($shortcodeCallback)) {
    throw new RuntimeException("The Library app shortcode is not callable.");
}

$mount = $shortcodeCallback(
    [],
    null,
    \Biblio\UI\LibraryAppShortcode::TAG
);

biblioUiAssertContains("data-biblio-ui-root", $mount, "Mount marker missing.");
biblioUiAssertContains(
    'data-rest-root="https://example.test/wp-json/biblio/v1/'
        . '?context=&quot;mount&quot;&amp;next=1"',
    $mount,
    "The escaped REST root is missing."
);
biblioUiAssertContains(
    'data-rest-nonce="nonce-&quot;&lt;&amp;"',
    $mount,
    "The escaped REST nonce is missing."
);
biblioUiAssertContains(
    'data-overview-url="https://example.test/mijn-bibliotheek/"',
    $mount,
    "The canonical overview URL is missing."
);
biblioUiAssertContains(
    'data-search-url="https://example.test/zoeken/"',
    $mount,
    "The shared Search destination is missing."
);
biblioUiAssertContains(
    'data-wishlist-url="https://example.test/verlanglijst/"',
    $mount,
    "The shared Wishlist destination is missing."
);
biblioUiAssertContains(
    'data-next-reading-url="https://example.test/hierna-lezen/"',
    $mount,
    "The shared Next Reading destination is missing."
);
biblioUiAssertContains(
    'data-login-url="https://example.test/wp-login.php?redirect_to='
        . 'https%3A%2F%2Fexample.test%2Fmijn-bibliotheek%2F'
        . '&amp;reason=&quot;session&quot;"',
    $mount,
    "The escaped login URL is missing."
);
biblioUiAssertSame(
    ["biblio/v1/", "biblio/v1/", "biblio/v1/", "biblio/v1/"],
    $biblioUiTestRestPaths,
    "The REST root must use the existing biblio/v1 namespace."
);
biblioUiAssertSame(
    ["wp_rest", "wp_rest", "wp_rest", "wp_rest"],
    $biblioUiTestNonceActions,
    "The mount must use the standard WordPress REST nonce action."
);
biblioUiAssertSame(
    [
        "/hierna-lezen/", "/mijn-bibliotheek/", "/mijn-biblio/", "/zoeken/", "/verlanglijst/",
        "/verlanglijst/", "/mijn-bibliotheek/", "/mijn-biblio/", "/zoeken/", "/hierna-lezen/",
        "/zoeken/", "/mijn-bibliotheek/", "/mijn-biblio/", "/verlanglijst/", "/hierna-lezen/",
        "/mijn-bibliotheek/", "/mijn-biblio/", "/bibliotheek-home/", "/zoeken/", "/verlanglijst/", "/hierna-lezen/", "/instellingen/", "/bibliotheekinstellingen/",
    ],
    $biblioUiTestHomePaths,
    "All personal navigation URLs must be server-generated from canonical paths."
);
biblioUiAssertSame(
    [
        "https://example.test/hierna-lezen/",
        "https://example.test/verlanglijst/",
        "https://example.test/zoeken/",
        "https://example.test/mijn-bibliotheek/",
    ],
    $biblioUiTestLoginRedirects,
    "The login URL must return to the canonical overview URL."
);

foreach ([$nextReadingMount, $wishlistMount, $searchMount, $mount] as $guestMount) {
    biblioUiAssertContains(
        'data-account-state="guest"',
        $guestMount,
        "A guest mount must expose the guest account state."
    );
    biblioUiAssertFalse(
        str_contains($guestMount, "data-logout-url"),
        "A guest mount must not expose an authenticated logout URL."
    );
}
biblioUiAssertSame(
    [],
    $biblioUiTestLogoutRedirects,
    "Guest rendering must not generate a logout URL."
);

$biblioUiTestLoggedIn = true;
$biblioUiTestCurrentUser = (object) [
    "display_name" => 'Renée "Biblio" <Admin>',
    "user_login" => "renee",
];
foreach ([
    $nextReadingShortcode,
    $wishlistShortcode,
    $searchShortcode,
    $shortcodeCallback,
] as $callback) {
    $signedInMount = $callback();
    biblioUiAssertContains(
        'data-account-state="authenticated"',
        $signedInMount,
        "An authenticated mount must expose its account state."
    );
    biblioUiAssertContains(
        'data-account-name="Renée &quot;Biblio&quot; &lt;Admin&gt;"',
        $signedInMount,
        "An authenticated mount must safely expose the current display name."
    );
    biblioUiAssertContains(
        'data-logout-url="https://example.test/wp-login.php?action=logout&amp;redirect_to='
            . rawurlencode("https://example.test/")
            . '&amp;_wpnonce=&quot;logout&quot;"',
        $signedInMount,
        "The WordPress logout URL must return to the public Biblio root."
    );
}
biblioUiAssertSame(
    [
        "https://example.test/",
        "https://example.test/",
        "https://example.test/",
        "https://example.test/",
    ],
    $biblioUiTestLogoutRedirects,
    "Every authenticated page must use the public root as its logout return URL."
);
$biblioUiTestCurrentUser->display_name = "";
biblioUiAssertContains(
    'data-account-name="renee"',
    $shortcodeCallback(),
    "An account without a display name must still identify its user."
);
$biblioUiTestLoggedIn = false;

biblioUiRunAction("wp_enqueue_scripts");

biblioUiAssertSame(
    [],
    $biblioUiTestEnqueuedModules,
    "The script module must not be globally enqueued."
);
biblioUiAssertSame(
    [],
    $biblioUiTestEnqueuedStyles,
    "The stylesheet must not be globally enqueued."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/js/app.js",
        "dependencies" => [[
            "id" => \Biblio\UI\Plugin::API_SCRIPT_MODULE_ID,
            "import" => "static",
        ], [
            "id" => \Biblio\UI\Plugin::CATALOG_QUERY_SCRIPT_MODULE_ID,
            "import" => "static",
        ], [
            "id" => \Biblio\UI\Plugin::ROUTE_SCRIPT_MODULE_ID,
            "import" => "static",
        ], [
            "id" => \Biblio\UI\Plugin::LIBRARY_SCRIPT_MODULE_ID,
            "import" => "static",
        ], [
            "id" => \Biblio\UI\Plugin::OVERVIEW_SCRIPT_MODULE_ID,
            "import" => "static",
        ], [
            "id" => \Biblio\UI\Plugin::PRIVATE_NOTES_SCRIPT_MODULE_ID,
            "import" => "static",
        ], [
            "id" => \Biblio\UI\Plugin::READING_HISTORY_SCRIPT_MODULE_ID,
            "import" => "static",
        ], [
            "id" => \Biblio\UI\Plugin::DETAIL_SCRIPT_MODULE_ID,
            "import" => "static",
        ], [
            "id" => \Biblio\UI\Plugin::START_READING_SCRIPT_MODULE_ID,
            "import" => "static",
        ], [
            "id" => \Biblio\UI\Plugin::END_READING_SCRIPT_MODULE_ID,
            "import" => "static",
        ], [
            "id" => \Biblio\UI\Plugin::ADD_BOOK_SCRIPT_MODULE_ID,
            "import" => "static",
        ], [
            "id" => \Biblio\UI\Plugin::UI_SHELL_SCRIPT_MODULE_ID,
            "import" => "static",
        ], [
            "id" => \Biblio\UI\Plugin::WISHLIST_SCRIPT_MODULE_ID,
            "import" => "static",
        ]],
        "version" => "0.24.2",
        "arguments" => [],
    ],
    $biblioUiTestRegisteredModules[\Biblio\UI\Plugin::SCRIPT_MODULE_ID] ?? null,
    "The Script Module registration contract is incorrect."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/js/api.js",
        "dependencies" => [],
        "version" => "0.24.2",
        "arguments" => [],
    ],
    $biblioUiTestRegisteredModules[
        \Biblio\UI\Plugin::API_SCRIPT_MODULE_ID
    ] ?? null,
    "The API Script Module registration contract is incorrect."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/js/catalog-query.js",
        "dependencies" => [],
        "version" => "0.24.2",
        "arguments" => [],
    ],
    $biblioUiTestRegisteredModules[
        \Biblio\UI\Plugin::CATALOG_QUERY_SCRIPT_MODULE_ID
    ] ?? null,
    "The catalog-query Script Module registration contract is incorrect."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/js/route-state.js",
        "dependencies" => [[
            "id" => \Biblio\UI\Plugin::CATALOG_QUERY_SCRIPT_MODULE_ID,
            "import" => "static",
        ]],
        "version" => "0.24.2",
        "arguments" => [],
    ],
    $biblioUiTestRegisteredModules[
        \Biblio\UI\Plugin::ROUTE_SCRIPT_MODULE_ID
    ] ?? null,
    "The route-state Script Module registration contract is incorrect."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/js/library-state.js",
        "dependencies" => [],
        "version" => "0.24.2",
        "arguments" => [],
    ],
    $biblioUiTestRegisteredModules[
        \Biblio\UI\Plugin::LIBRARY_SCRIPT_MODULE_ID
    ] ?? null,
    "The library-state Script Module registration contract is incorrect."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/js/overview-view.js",
        "dependencies" => [],
        "version" => "0.24.2",
        "arguments" => [],
    ],
    $biblioUiTestRegisteredModules[
        \Biblio\UI\Plugin::OVERVIEW_SCRIPT_MODULE_ID
    ] ?? null,
    "The overview-view Script Module registration contract is incorrect."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/js/ui-preferences.js",
        "dependencies" => [],
        "version" => "0.24.2",
        "arguments" => [],
    ],
    $biblioUiTestRegisteredModules[
        \Biblio\UI\Plugin::UI_PREFERENCES_SCRIPT_MODULE_ID
    ] ?? null,
    "The UI-preferences Script Module registration contract is incorrect."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/js/ui-shell.js",
        "dependencies" => [[
            "id" => \Biblio\UI\Plugin::UI_PREFERENCES_SCRIPT_MODULE_ID,
            "import" => "static",
        ]],
        "version" => "0.24.2",
        "arguments" => [],
    ],
    $biblioUiTestRegisteredModules[
        \Biblio\UI\Plugin::UI_SHELL_SCRIPT_MODULE_ID
    ] ?? null,
    "The UI-shell Script Module registration contract is incorrect."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/js/bibliographic-search.js",
        "dependencies" => [[
            "id" => \Biblio\UI\Plugin::API_SCRIPT_MODULE_ID,
            "import" => "static",
        ], [
            "id" => \Biblio\UI\Plugin::UI_SHELL_SCRIPT_MODULE_ID,
            "import" => "static",
        ]],
        "version" => "0.24.2",
        "arguments" => [],
    ],
    $biblioUiTestRegisteredModules[
        \Biblio\UI\Plugin::SEARCH_SCRIPT_MODULE_ID
    ] ?? null,
    "The Search Script Module registration contract is incorrect."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/js/next-reading.js",
        "dependencies" => [[
            "id" => \Biblio\UI\Plugin::API_SCRIPT_MODULE_ID,
            "import" => "static",
        ], [
            "id" => \Biblio\UI\Plugin::UI_SHELL_SCRIPT_MODULE_ID,
            "import" => "static",
        ]],
        "version" => "0.24.2",
        "arguments" => [],
    ],
    $biblioUiTestRegisteredModules[
        \Biblio\UI\Plugin::NEXT_READING_SCRIPT_MODULE_ID
    ] ?? null,
    "The Next Reading Script Module registration contract is incorrect."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/js/wishlist.js",
        "dependencies" => [[
            "id" => \Biblio\UI\Plugin::API_SCRIPT_MODULE_ID,
            "import" => "static",
        ], [
            "id" => \Biblio\UI\Plugin::UI_SHELL_SCRIPT_MODULE_ID,
            "import" => "static",
        ]],
        "version" => "0.24.2",
        "arguments" => [],
    ],
    $biblioUiTestRegisteredModules[
        \Biblio\UI\Plugin::WISHLIST_SCRIPT_MODULE_ID
    ] ?? null,
    "The Wishlist Script Module registration contract is incorrect."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/js/private-notes.js",
        "dependencies" => [],
        "version" => "0.24.2",
        "arguments" => [],
    ],
    $biblioUiTestRegisteredModules[
        \Biblio\UI\Plugin::PRIVATE_NOTES_SCRIPT_MODULE_ID
    ] ?? null,
    "The Private Notes Script Module registration contract is incorrect."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/js/reading-history.js",
        "dependencies" => [],
        "version" => "0.24.2",
        "arguments" => [],
    ],
    $biblioUiTestRegisteredModules[
        \Biblio\UI\Plugin::READING_HISTORY_SCRIPT_MODULE_ID
    ] ?? null,
    "The Reading-history Script Module registration contract is incorrect."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/js/detail-view.js",
        "dependencies" => [],
        "version" => "0.24.2",
        "arguments" => [],
    ],
    $biblioUiTestRegisteredModules[
        \Biblio\UI\Plugin::DETAIL_SCRIPT_MODULE_ID
    ] ?? null,
    "The detail-view Script Module registration contract is incorrect."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/js/start-reading-view.js",
        "dependencies" => [],
        "version" => "0.24.2",
        "arguments" => [],
    ],
    $biblioUiTestRegisteredModules[
        \Biblio\UI\Plugin::START_READING_SCRIPT_MODULE_ID
    ] ?? null,
    "The start-reading Script Module registration contract is incorrect."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/js/end-reading-view.js",
        "dependencies" => [],
        "version" => "0.24.2",
        "arguments" => [],
    ],
    $biblioUiTestRegisteredModules[
        \Biblio\UI\Plugin::END_READING_SCRIPT_MODULE_ID
    ] ?? null,
    "The end-reading Script Module registration contract is incorrect."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/js/add-book-wizard.js",
        "dependencies" => [],
        "version" => "0.24.2",
        "arguments" => [],
    ],
    $biblioUiTestRegisteredModules[
        \Biblio\UI\Plugin::ADD_BOOK_SCRIPT_MODULE_ID
    ] ?? null,
    "The Add Book Script Module registration contract is incorrect."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/css/app.css",
        "dependencies" => [],
        "version" => "0.24.2",
        "media" => "all",
    ],
    $biblioUiTestRegisteredStyles[\Biblio\UI\Plugin::STYLE_HANDLE] ?? null,
    "The stylesheet registration contract is incorrect."
);

$biblioUiTestCurrentPageSlug = "mijn-bibliotheek";
biblioUiRunAction("wp_enqueue_scripts");

biblioUiAssertSame(
    [\Biblio\UI\Plugin::SCRIPT_MODULE_ID],
    $biblioUiTestEnqueuedModules,
    "The Script Module must be enqueued on the Library app Page."
);
biblioUiAssertSame(
    [\Biblio\UI\Plugin::STYLE_HANDLE],
    $biblioUiTestEnqueuedStyles,
    "The stylesheet must be enqueued on the Library app Page."
);
biblioUiAssertSame(
    [
        "biblio", ["instellingen", "bibliotheekinstellingen"], ["mijn-biblio", "bibliotheek-home"], "hierna-lezen", "verlanglijst", "zoeken", "mijn-bibliotheek",
        "biblio", ["instellingen", "bibliotheekinstellingen"], ["mijn-biblio", "bibliotheek-home"], "hierna-lezen", "verlanglijst", "zoeken", "mijn-bibliotheek",
    ],
    $biblioUiTestPageChecks,
    "Every asset decision must use the planned Page slug."
);

$biblioUiTestEnqueuedModules = [];
$biblioUiTestEnqueuedStyles = [];
$biblioUiTestCurrentPageSlug = "hierna-lezen";
biblioUiRunAction("wp_enqueue_scripts");
biblioUiAssertSame(
    [\Biblio\UI\Plugin::NEXT_READING_SCRIPT_MODULE_ID],
    $biblioUiTestEnqueuedModules,
    "Only the Next Reading module must load on its isolated Page."
);
biblioUiAssertSame(
    [\Biblio\UI\Plugin::STYLE_HANDLE],
    $biblioUiTestEnqueuedStyles,
    "The shared stylesheet must load on the Next Reading Page."
);
$biblioUiTestEnqueuedModules = [];
$biblioUiTestEnqueuedStyles = [];
$biblioUiTestCurrentPageSlug = "verlanglijst";
biblioUiRunAction("wp_enqueue_scripts");
biblioUiAssertSame(
    [\Biblio\UI\Plugin::WISHLIST_SCRIPT_MODULE_ID],
    $biblioUiTestEnqueuedModules,
    "Only the Wishlist module must load on its isolated Page."
);
biblioUiAssertSame(
    [\Biblio\UI\Plugin::STYLE_HANDLE],
    $biblioUiTestEnqueuedStyles,
    "The shared stylesheet must load on the Wishlist Page."
);
$biblioUiTestEnqueuedModules = [];
$biblioUiTestEnqueuedStyles = [];
$biblioUiTestCurrentPageSlug = "zoeken";
biblioUiRunAction("wp_enqueue_scripts");
biblioUiAssertSame(
    [\Biblio\UI\Plugin::SEARCH_SCRIPT_MODULE_ID],
    $biblioUiTestEnqueuedModules,
    "Only the Search module must load on its isolated Page."
);
biblioUiAssertSame(
    [\Biblio\UI\Plugin::STYLE_HANDLE],
    $biblioUiTestEnqueuedStyles,
    "The shared stylesheet must load on the Search Page."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/app.js"),
    "The Script Module entry file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/api.js"),
    "The API Script Module file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/route-state.js"),
    "The route-state Script Module file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/catalog-query.js"),
    "The catalog-query Script Module file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/library-state.js"),
    "The library-state Script Module file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/overview-view.js"),
    "The overview-view Script Module file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/ui-preferences.js"),
    "The UI-preferences Script Module file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/ui-shell.js"),
    "The UI-shell Script Module file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/private-notes.js"),
    "The Private Notes Script Module file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/reading-history.js"),
    "The Reading-history Script Module file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/detail-view.js"),
    "The detail-view Script Module file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/start-reading-view.js"),
    "The start-reading Script Module file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/end-reading-view.js"),
    "The end-reading Script Module file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/add-book-wizard.js"),
    "The Add Book Script Module file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/next-reading.js"),
    "The Next Reading Script Module file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/wishlist.js"),
    "The Wishlist Script Module file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/bibliographic-search.js"),
    "The Search Script Module file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/entry.js"),
    "The personal and Library Home Script Module file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/bibliographic-discovery.js"),
    "The shared bibliographic discovery decoder file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/css/app.css"),
    "The stylesheet file must exist."
);

foreach ([
    \Biblio\UI\EntryAppShortcode::PERSONAL_TAG => "personal",
    \Biblio\UI\EntryAppShortcode::LIBRARY_TAG => "library",
] as $tag => $mode) {
    biblioUiAssertSame(
        1,
        $biblioUiTestShortcodeRegistrations[$tag] ?? 0,
        "Each entry shortcode must register exactly once."
    );
    $entryMount = $biblioUiTestShortcodes[$tag]();
    biblioUiAssertContains('data-entry-mode="' . $mode . '"', $entryMount, "Entry mode is missing.");
    biblioUiAssertContains('data-platform-url="https://example.test/mijn-biblio/"', $entryMount, "The personal route is missing.");
    biblioUiAssertContains('data-library-home-url="https://example.test/bibliotheek-home/"', $entryMount, "The Library Home route is missing.");
}
$_GET["library_id"] = "member/2";
$directHomeMount = $biblioUiTestShortcodes[\Biblio\UI\EntryAppShortcode::LIBRARY_TAG]();
unset($_GET["library_id"]);
biblioUiAssertContains(
    'redirect_to=https%3A%2F%2Fexample.test%2Fbibliotheek-home%2F%3Flibrary_id%3Dmember%252F2',
    $directHomeMount,
    "A Library Home login must preserve its explicit Library target."
);
$biblioUiTestCurrentPageSlug = \Biblio\UI\EntryAppShortcode::PERSONAL_SLUG;
$biblioUiTestEnqueuedModules = [];
biblioUiRunAction("wp_enqueue_scripts");
biblioUiAssertSame(
    [\Biblio\UI\Plugin::ENTRY_SCRIPT_MODULE_ID],
    $biblioUiTestEnqueuedModules,
    "Mijn Biblio must load the entry module."
);

biblioUiAssertSame(
    1,
    $biblioUiTestShortcodeRegistrations[\Biblio\UI\PublicHomeShortcode::TAG] ?? 0,
    "The public Home shortcode must register exactly once."
);
$publicHome = $biblioUiTestShortcodes[\Biblio\UI\PublicHomeShortcode::TAG] ?? null;
if (!is_callable($publicHome)) {
    throw new RuntimeException("The public Home shortcode is not callable.");
}
$guestHome = $publicHome();
biblioUiAssertContains('<h1 id="biblio-public-home-title">Biblio</h1>', $guestHome, "The public Home title is missing.");
biblioUiAssertContains("Biblio helpt je om boeken terug te vinden", $guestHome, "The public introduction is missing.");
biblioUiAssertContains('href="https://example.test/wp-login.php?redirect_to=https%3A%2F%2Fexample.test%2Fmijn-biblio%2F', $guestHome, "Guest login must return to Mijn Biblio.");
biblioUiAssertContains('>Inloggen<span', $guestHome, "Guests must see the login action.");
biblioUiAssertFalse(str_contains($guestHome, "Naar Mijn Biblio<span"), "Guests must not see the signed-in action.");

$biblioUiTestLoggedIn = true;
$signedInHome = $publicHome();
biblioUiAssertContains('href="https://example.test/mijn-biblio/"', $signedInHome, "A signed-in user must go directly to Mijn Biblio.");
biblioUiAssertContains('>Naar Mijn Biblio<span', $signedInHome, "A signed-in user must see the personal entry action.");
biblioUiAssertFalse(str_contains($signedInHome, "Renée"), "The public root must not expose account details.");
$biblioUiTestLoggedIn = false;

$biblioUiTestCurrentPageSlug = \Biblio\UI\PublicHomeShortcode::PAGE_SLUG;
biblioUiAssertSame(
    ["existing", \Biblio\UI\Plugin::PUBLIC_HOME_BODY_CLASS],
    biblioUiRunFilter("body_class", ["existing"]),
    "The public Home must receive only its scoped body class."
);
$biblioUiTestEnqueuedStyles = [];
$biblioUiTestEnqueuedModules = [];
biblioUiRunAction("wp_enqueue_scripts");
biblioUiAssertSame(
    [\Biblio\UI\Plugin::PUBLIC_HOME_STYLE_HANDLE],
    $biblioUiTestEnqueuedStyles,
    "The public Home must load its own stylesheet."
);
biblioUiAssertSame([], $biblioUiTestEnqueuedModules, "The public Home must not load app modules.");
biblioUiAssertSame(true, is_file(__DIR__ . "/../assets/css/public-home.css"), "The public Home stylesheet must exist.");

$biblioUiTestCurrentPageSlug = \Biblio\UI\SettingsAppShortcode::PAGE_SLUG;
$biblioUiTestEnqueuedModules = [];
$biblioUiTestEnqueuedStyles = [];
$plugin->registerAndEnqueueAssets();
biblioUiAssertSame([\Biblio\UI\Plugin::SETTINGS_SCRIPT_MODULE_ID], $biblioUiTestEnqueuedModules, "Settings must load its own module.");
biblioUiAssertSame([\Biblio\UI\Plugin::STYLE_HANDLE], $biblioUiTestEnqueuedStyles, "Settings uses the app stylesheet.");
$_GET = ["library_id" => "exact/library"];
$settingsMount = (new \Biblio\UI\SettingsAppShortcode())->render();
biblioUiAssertContains('data-biblio-settings-root', $settingsMount, "Settings has its own mount.");
biblioUiAssertFalse(str_contains($settingsMount, 'data-biblio-entry-root'), "Settings is not an entry bootstrap.");
biblioUiAssertContains('data-settings-url=', $settingsMount, "Settings has the canonical route.");
biblioUiAssertSame('https://example.test/instellingen/?library_id=exact%2Flibrary', end($biblioUiTestLoginRedirects), "Login returns to the exact settings Library.");
biblioUiAssertContains('data-settings-mode="preferences"', $settingsMount, "Personal settings has its fixed page mode.");
biblioUiAssertContains('data-library-settings-url="https://example.test/bibliotheekinstellingen/"', $settingsMount, "Settings exposes the separate management destination.");
$biblioUiTestCurrentPageSlug = \Biblio\UI\SettingsAppShortcode::MANAGEMENT_SLUG;
$biblioUiTestEnqueuedModules = [];
$biblioUiTestEnqueuedStyles = [];
$plugin->registerAndEnqueueAssets();
biblioUiAssertSame([\Biblio\UI\Plugin::SETTINGS_SCRIPT_MODULE_ID], $biblioUiTestEnqueuedModules, "Management loads the settings module.");
biblioUiAssertSame([\Biblio\UI\Plugin::STYLE_HANDLE], $biblioUiTestEnqueuedStyles, "Management uses the app stylesheet.");
biblioUiAssertSame(["existing", \Biblio\UI\Plugin::PAGE_BODY_CLASS], biblioUiRunFilter("body_class", ["existing"]), "Management uses the scoped shell.");
$managementMount = (new \Biblio\UI\SettingsAppShortcode())->renderManagement();
biblioUiAssertContains('data-settings-mode="defaults"', $managementMount, "Management has its fixed mode.");
biblioUiAssertFalse(str_contains($managementMount, 'data-biblio-entry-root'), "Management is not an entry bootstrap.");
biblioUiAssertSame('https://example.test/bibliotheekinstellingen/?library_id=exact%2Flibrary', end($biblioUiTestLoginRedirects), "Login returns to the exact management Library.");
$_GET = [];

require __DIR__ . "/../biblio-ui.php";

biblioUiAssertSame(
    14,
    count($biblioUiTestActions["init"] ?? []),
    "The plugin entry point must register all seven additional init hooks."
);
biblioUiAssertSame(
    2,
    count($biblioUiTestActions["wp_enqueue_scripts"] ?? []),
    "The plugin entry point must register one additional asset hook."
);
biblioUiAssertSame(
    2,
    count($biblioUiTestFilters["body_class"] ?? []),
    "The plugin entry point must register one additional body-class filter."
);
biblioUiAssertSame(
    "0.24.2",
    \Biblio\UI\Plugin::VERSION,
    "The plugin version must remain the single asset cache-busting version."
);
biblioUiAssertFalse(
    class_exists("Elementor\\Plugin", false),
    "Biblio UI must boot without Elementor."
);
biblioUiAssertFalse(
    class_exists("Biblio\\Core\\Plugin", false),
    "Biblio UI asset bootstrap must not load Biblio Core."
);

echo "OK: Biblio UI isolated smoke test passed." . PHP_EOL;
echo "Lifecycle: idempotent" . PHP_EOL;
echo "Shortcode config: escaped server values" . PHP_EOL;
echo "Script Module: biblio-ui/app@0.24.2" . PHP_EOL;
echo "API Script Module: biblio-ui/api@0.24.2" . PHP_EOL;
echo "Catalog Query Script Module: biblio-ui/catalog-query@0.24.2" . PHP_EOL;
echo "Route Script Module: biblio-ui/route-state@0.24.2" . PHP_EOL;
echo "Library Script Module: biblio-ui/library-state@0.24.2" . PHP_EOL;
echo "Overview Script Module: biblio-ui/overview-view@0.24.2" . PHP_EOL;
echo "UI Preferences Script Module: biblio-ui/ui-preferences@0.24.2" . PHP_EOL;
echo "UI Shell Script Module: biblio-ui/ui-shell@0.24.2" . PHP_EOL;
echo "Private Notes Script Module: biblio-ui/private-notes@0.24.2" . PHP_EOL;
echo "Reading History Script Module: biblio-ui/reading-history@0.24.2" . PHP_EOL;
echo "Detail Script Module: biblio-ui/detail-view@0.24.2" . PHP_EOL;
echo "Start Reading Script Module: biblio-ui/start-reading-view@0.24.2" . PHP_EOL;
echo "End Reading Script Module: biblio-ui/end-reading-view@0.24.2" . PHP_EOL;
echo "Add Book Script Module: biblio-ui/add-book-wizard@0.24.2" . PHP_EOL;
echo "Next Reading Script Module: biblio-ui/next-reading@0.24.2" . PHP_EOL;
echo "Wishlist Script Module: biblio-ui/wishlist@0.24.2" . PHP_EOL;
echo "Search Script Module: biblio-ui/bibliographic-search@0.24.2" . PHP_EOL;
echo "Stylesheet: biblio-ui@0.24.2" . PHP_EOL;
echo "Global enqueue: no" . PHP_EOL;
echo "Library Page enqueue: yes" . PHP_EOL;
echo "Elementor loaded: no" . PHP_EOL;
echo "Biblio Core loaded: no" . PHP_EOL;
