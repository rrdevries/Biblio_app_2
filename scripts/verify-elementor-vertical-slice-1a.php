<?php

function biblioStep9Fail(string $message): never
{
    throw new RuntimeException($message);
}

function biblioStep9Assert(bool $condition, string $message): void
{
    if (!$condition) {
        biblioStep9Fail($message);
    }
}

function biblioStep9GeneratedCss(int $postId): string
{
    $uploads = wp_upload_dir();
    $path = $uploads["basedir"]
        . "/elementor/css/post-"
        . $postId
        . ".css";
    $css = file_get_contents($path);
    biblioStep9Assert(
        is_string($css),
        "The generated Elementor CSS is missing for post {$postId}."
    );

    return $css;
}

/**
 * @param array<int, mixed> $elements
 * @return list<string>
 */
function biblioStep9WidgetTypes(array $elements): array
{
    $types = [];

    foreach ($elements as $element) {
        if (!is_array($element)) {
            continue;
        }

        if (isset($element["widgetType"]) && is_string($element["widgetType"])) {
            $types[] = $element["widgetType"];
        }

        if (isset($element["elements"]) && is_array($element["elements"])) {
            $types = array_merge(
                $types,
                biblioStep9WidgetTypes($element["elements"])
            );
        }
    }

    return $types;
}

$mode = $args[0] ?? "page";

if ($mode === "assets-off") {
    $query = new WP_Query(["p" => 0]);
    $GLOBALS["wp_query"] = $query;
    $GLOBALS["wp_the_query"] = $query;

    do_action("wp_enqueue_scripts");
    biblioStep9Assert(
        !wp_style_is("biblio-ui", "enqueued"),
        "Biblio UI styles must not enqueue outside the Library Page."
    );

    ob_start();
    wp_script_modules()->print_head_enqueued_script_modules();
    $moduleMarkup = (string) ob_get_clean();
    biblioStep9Assert(
        !str_contains($moduleMarkup, 'id="biblio-ui/app-js-module"'),
        "The Biblio UI app module must not enqueue globally."
    );

    echo "Success: Non-Library Page asset isolation passed.\n";
    return;
}

biblioStep9Assert($mode === "page", "Unknown verification mode.");

$pageIds = get_posts([
    "fields" => "ids",
    "name" => "mijn-bibliotheek",
    "numberposts" => -1,
    "post_status" => "any",
    "post_type" => "page",
]);
biblioStep9Assert(
    count($pageIds) === 1,
    "Exactly one Mijn Bibliotheek Page must exist."
);

$pageId = (int) $pageIds[0];
$page = get_post($pageId);
biblioStep9Assert($page instanceof WP_Post, "The Library Page is invalid.");
biblioStep9Assert(
    $page->post_title === "Mijn Bibliotheek",
    "The Library Page title is incorrect."
);
biblioStep9Assert(
    $page->post_name === "mijn-bibliotheek",
    "The Library Page slug is incorrect."
);
biblioStep9Assert(
    $page->post_status === "publish",
    "The Library Page must be published."
);
biblioStep9Assert(
    wp_parse_url(get_permalink($pageId), PHP_URL_PATH)
        === "/mijn-bibliotheek/",
    "The Library Page canonical path is incorrect."
);
biblioStep9Assert(
    get_page_template_slug($pageId) === "",
    "The Library Page must use the ordinary default Page template."
);

$activeKitId = (int) get_option("elementor_active_kit");
$activeKit = get_post($activeKitId);
biblioStep9Assert(
    $activeKit instanceof WP_Post
        && $activeKit->post_type === "elementor_library"
        && $activeKit->post_status === "publish",
    "The imported active Elementor Kit is invalid."
);
$kitSettings = get_post_meta(
    $activeKitId,
    "_elementor_page_settings",
    true
);
biblioStep9Assert(
    is_array($kitSettings)
        && ($kitSettings["page_title_selector"] ?? null)
            === "h1.wp-block-post-title",
    "The Kit must target the Twenty Twenty-Five Page title selector."
);

biblioStep9Assert(
    get_post_meta($pageId, "_elementor_edit_mode", true) === "builder",
    "Elementor must recognize the Library Page."
);
$pageSettings = get_post_meta(
    $pageId,
    "_elementor_page_settings",
    true
);
biblioStep9Assert(
    is_array($pageSettings)
        && ($pageSettings["hide_title"] ?? null) === "yes",
    "The duplicate WordPress Page title must be hidden."
);
$kitCss = biblioStep9GeneratedCss($activeKitId);
biblioStep9Assert(
    str_contains(
        $kitCss,
        "h1.wp-block-post-title{display:var(--page-title-display)"
    ),
    "The generated Kit CSS must target the Twenty Twenty-Five Page title."
);
$pageCss = biblioStep9GeneratedCss($pageId);
biblioStep9Assert(
    str_contains($pageCss, ":root{--page-title-display:none;}"),
    "The generated Page CSS must hide the duplicate WordPress title."
);

$rawData = get_post_meta($pageId, "_elementor_data", true);
biblioStep9Assert(
    is_string($rawData) && $rawData !== "",
    "The Elementor Page data is missing."
);
$elements = json_decode($rawData, true, 512, JSON_THROW_ON_ERROR);
biblioStep9Assert(
    is_array($elements) && count($elements) === 1,
    "The Library Page must have exactly one outer Elementor container."
);
biblioStep9Assert(
    ($elements[0]["elType"] ?? null) === "container",
    "The Library Page shell must use an Elementor container."
);

$widgetTypes = biblioStep9WidgetTypes($elements);
biblioStep9Assert(
    $widgetTypes === ["shortcode"],
    "The Library Page must contain only one Shortcode widget."
);
biblioStep9Assert(
    substr_count($rawData, "[biblio_library_app]") === 1,
    "The Elementor Page must store the Biblio shortcode exactly once."
);

$html = do_shortcode("[biblio_library_app]");
biblioStep9Assert(
    substr_count($html, "data-biblio-ui-root") === 1,
    "The imported shortcode must render exactly one Biblio mount."
);

$query = new WP_Query(["page_id" => $pageId]);
$GLOBALS["wp_query"] = $query;
$GLOBALS["wp_the_query"] = $query;
do_action("wp_enqueue_scripts");
biblioStep9Assert(
    wp_style_is("biblio-ui", "enqueued"),
    "Biblio UI styles must enqueue on the Library Page."
);

ob_start();
wp_script_modules()->print_head_enqueued_script_modules();
$moduleMarkup = (string) ob_get_clean();
biblioStep9Assert(
    str_contains($moduleMarkup, 'id="biblio-ui/app-js-module"'),
    "The Biblio UI app module must enqueue on the Library Page."
);

echo "Page: Mijn Bibliotheek\n";
echo "Slug: mijn-bibliotheek\n";
echo "Status: publish\n";
echo "Elementor: yes\n";
echo "Active Kit: yes\n";
echo "Page title selector: h1.wp-block-post-title\n";
echo "Generated title CSS: yes\n";
echo "Outer containers: 1\n";
echo "Shortcode widgets: 1\n";
echo "Frontend mounts: 1\n";
echo "Page-only assets: yes\n";
echo "Success: Elementor Vertical Slice 1a Page verification passed.\n";
