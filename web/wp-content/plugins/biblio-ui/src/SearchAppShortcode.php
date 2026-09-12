<?php

declare(strict_types=1);

namespace Biblio\UI;

final class SearchAppShortcode
{
    public const TAG = "biblio_search_app";
    public const PAGE_SLUG = "zoeken";

    private bool $registered = false;

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        add_shortcode(self::TAG, [$this, "render"]);
        $this->registered = true;
    }

    /** @param array<string, mixed>|string $attributes */
    public function render(
        array|string $attributes = [],
        ?string $content = null,
        string $shortcodeTag = self::TAG
    ): string {
        $pageUrl = home_url("/" . self::PAGE_SLUG . "/");

        return sprintf(
            '<div data-biblio-ui-root data-biblio-search-root data-rest-root="%s" '
                . 'data-rest-nonce="%s" data-overview-url="%s" '
                . 'data-search-url="%s" data-wishlist-url="%s" '
                . 'data-next-reading-url="%s" data-login-url="%s"></div>',
            esc_url(rest_url("biblio/v1/")),
            esc_attr(wp_create_nonce("wp_rest")),
            esc_url(home_url("/" . LibraryAppShortcode::PAGE_SLUG . "/")),
            esc_url($pageUrl),
            esc_url(home_url("/" . WishlistAppShortcode::PAGE_SLUG . "/")),
            esc_url(home_url("/" . NextReadingAppShortcode::PAGE_SLUG . "/")),
            esc_url(wp_login_url($pageUrl))
        );
    }
}
