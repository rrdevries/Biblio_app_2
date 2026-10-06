<?php
if (!defined('WP_CLI') || wp_get_environment_type() !== 'local' || getenv('DDEV_PROJECT') !== 'biblio-v2') throw new RuntimeException('Local only.');
$pages = [
    ['instellingen', 'Mijn voorkeuren', '[biblio_settings]'],
    ['bibliotheekinstellingen', 'Bibliotheekinstellingen', '[biblio_library_settings]'],
];
// Validate both destinations before creating either; never overwrite other content.
foreach ($pages as [$slug, $title, $shortcode]) {
    $page = get_page_by_path($slug);
    if ($page && trim($page->post_content) !== $shortcode) throw new RuntimeException('Existing page has different content; refusing overwrite.');
}
foreach ($pages as [$slug, $title, $shortcode]) {
    if (get_page_by_path($slug)) {
        WP_CLI::line($title . ' page already present.');
        continue;
    }
    $id = wp_insert_post(['post_type'=>'page', 'post_status'=>'publish', 'post_title'=>$title, 'post_name'=>$slug, 'post_content'=>$shortcode], true);
    if (is_wp_error($id)) throw new RuntimeException('Page creation failed.');
    WP_CLI::line($title . ' page created.');
}
