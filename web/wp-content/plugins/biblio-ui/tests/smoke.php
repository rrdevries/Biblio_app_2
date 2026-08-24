<?php

declare(strict_types=1);

define("ABSPATH", __DIR__ . "/wordpress/");

/** @var array<string, list<callable>> $biblioUiTestActions */
$biblioUiTestActions = [];
/** @var array<string, callable> $biblioUiTestShortcodes */
$biblioUiTestShortcodes = [];
/** @var array<string, int> $biblioUiTestShortcodeRegistrations */
$biblioUiTestShortcodeRegistrations = [];

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

function biblioUiAssertFalse(bool $actual, string $message): void
{
    biblioUiAssertSame(false, $actual, $message);
}

function biblioUiRunInitCallbacks(): void
{
    global $biblioUiTestActions;

    foreach ($biblioUiTestActions["init"] ?? [] as $callback) {
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

$plugin = new \Biblio\UI\Plugin();
$plugin->boot();
$plugin->boot();

biblioUiAssertSame(
    1,
    count($biblioUiTestActions["init"] ?? []),
    "Plugin boot must register the init hook exactly once."
);

biblioUiRunInitCallbacks();
biblioUiRunInitCallbacks();

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

biblioUiAssertSame(
    '<div data-biblio-ui-root></div>',
    $shortcodeCallback([], null, \Biblio\UI\LibraryAppShortcode::TAG),
    "The shortcode must render only the Slice 1a mount."
);

/** @var array<string, list<callable>> $biblioUiTestActions */
$biblioUiTestActions = [];
/** @var array<string, callable> $biblioUiTestShortcodes */
$biblioUiTestShortcodes = [];
/** @var array<string, int> $biblioUiTestShortcodeRegistrations */
$biblioUiTestShortcodeRegistrations = [];

require __DIR__ . "/../biblio-ui.php";

biblioUiAssertSame(
    1,
    count($biblioUiTestActions["init"] ?? []),
    "The plugin entry point must register one init hook."
);

biblioUiRunInitCallbacks();

biblioUiAssertSame(
    1,
    $biblioUiTestShortcodeRegistrations[\Biblio\UI\LibraryAppShortcode::TAG]
        ?? 0,
    "The plugin entry point must register the shortcode once."
);
biblioUiAssertSame(
    "0.1.0",
    \Biblio\UI\Plugin::VERSION,
    "The plugin foundation version must remain explicit."
);
biblioUiAssertFalse(
    class_exists("Elementor\\Plugin", false),
    "Biblio UI must boot without Elementor."
);
biblioUiAssertFalse(
    class_exists("Biblio\\Core\\Plugin", false),
    "Biblio UI foundation must not load Biblio Core."
);

echo "OK: Biblio UI isolated smoke test passed." . PHP_EOL;
echo "Lifecycle: idempotent" . PHP_EOL;
echo "Shortcode: " . \Biblio\UI\LibraryAppShortcode::TAG . PHP_EOL;
echo "Mount: <div data-biblio-ui-root></div>" . PHP_EOL;
echo "Elementor loaded: no" . PHP_EOL;
echo "Biblio Core loaded: no" . PHP_EOL;
