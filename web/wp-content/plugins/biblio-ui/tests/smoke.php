<?php

declare(strict_types=1);

define("ABSPATH", __DIR__ . "/wordpress/");

/** @var array<string, list<callable>> $biblioUiTestActions */
$biblioUiTestActions = [];
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
$biblioUiTestIsLibraryPage = false;

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
    global $biblioUiTestIsLibraryPage, $biblioUiTestPageChecks;

    $biblioUiTestPageChecks[] = $page;

    return $biblioUiTestIsLibraryPage;
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

function wp_login_url(string $redirect = "", bool $forceReauthentication = false): string
{
    global $biblioUiTestLoginRedirects;

    $biblioUiTestLoginRedirects[] = $redirect;

    return "https://example.test/wp-login.php?redirect_to="
        . rawurlencode($redirect)
        . '&reason="session"';
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

biblioUiAssertFalse(
    class_exists("Elementor\\Plugin", false),
    "The isolated smoke test must not load Elementor."
);
biblioUiAssertFalse(
    class_exists("Biblio\\Core\\Plugin", false),
    "The isolated smoke test must not load Biblio Core."
);

require_once __DIR__ . "/../src/LibraryAppShortcode.php";
require_once __DIR__ . "/../src/Plugin.php";

$plugin = new \Biblio\UI\Plugin("/plugin/biblio-ui.php");
$plugin->boot();
$plugin->boot();

biblioUiAssertSame(
    1,
    count($biblioUiTestActions["init"] ?? []),
    "Plugin boot must register the init hook exactly once."
);
biblioUiAssertSame(
    1,
    count($biblioUiTestActions["wp_enqueue_scripts"] ?? []),
    "Plugin boot must register the asset hook exactly once."
);

biblioUiRunAction("init");
biblioUiRunAction("init");

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
    'data-login-url="https://example.test/wp-login.php?redirect_to='
        . 'https%3A%2F%2Fexample.test%2Fmijn-bibliotheek%2F'
        . '&amp;reason=&quot;session&quot;"',
    $mount,
    "The escaped login URL is missing."
);
biblioUiAssertSame(
    ["biblio/v1/"],
    $biblioUiTestRestPaths,
    "The REST root must use the existing biblio/v1 namespace."
);
biblioUiAssertSame(
    ["wp_rest"],
    $biblioUiTestNonceActions,
    "The mount must use the standard WordPress REST nonce action."
);
biblioUiAssertSame(
    ["/mijn-bibliotheek/"],
    $biblioUiTestHomePaths,
    "The overview URL must be server-generated from the planned path."
);
biblioUiAssertSame(
    ["https://example.test/mijn-bibliotheek/"],
    $biblioUiTestLoginRedirects,
    "The login URL must return to the canonical overview URL."
);

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
        "dependencies" => [],
        "version" => "0.1.0",
        "arguments" => [],
    ],
    $biblioUiTestRegisteredModules[\Biblio\UI\Plugin::SCRIPT_MODULE_ID] ?? null,
    "The Script Module registration contract is incorrect."
);
biblioUiAssertSame(
    [
        "source" => "https://example.test/wp-content/plugins/biblio-ui/"
            . "assets/css/app.css",
        "dependencies" => [],
        "version" => "0.1.0",
        "media" => "all",
    ],
    $biblioUiTestRegisteredStyles[\Biblio\UI\Plugin::STYLE_HANDLE] ?? null,
    "The stylesheet registration contract is incorrect."
);

$biblioUiTestIsLibraryPage = true;
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
    ["mijn-bibliotheek", "mijn-bibliotheek"],
    $biblioUiTestPageChecks,
    "Every asset decision must use the planned Page slug."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/js/app.js"),
    "The Script Module entry file must exist."
);
biblioUiAssertSame(
    true,
    is_file(__DIR__ . "/../assets/css/app.css"),
    "The stylesheet file must exist."
);

require __DIR__ . "/../biblio-ui.php";

biblioUiAssertSame(
    2,
    count($biblioUiTestActions["init"] ?? []),
    "The plugin entry point must register one additional init hook."
);
biblioUiAssertSame(
    2,
    count($biblioUiTestActions["wp_enqueue_scripts"] ?? []),
    "The plugin entry point must register one additional asset hook."
);
biblioUiAssertSame(
    "0.1.0",
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
echo "Script Module: biblio-ui/app@0.1.0" . PHP_EOL;
echo "Stylesheet: biblio-ui@0.1.0" . PHP_EOL;
echo "Global enqueue: no" . PHP_EOL;
echo "Library Page enqueue: yes" . PHP_EOL;
echo "Elementor loaded: no" . PHP_EOL;
echo "Biblio Core loaded: no" . PHP_EOL;
