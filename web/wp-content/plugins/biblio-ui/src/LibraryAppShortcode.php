<?php

declare(strict_types=1);

namespace Biblio\UI;

final class LibraryAppShortcode
{
    public const TAG = "biblio_library_app";
    public const PAGE_SLUG = "mijn-bibliotheek";

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
        $overviewUrl = home_url("/" . self::PAGE_SLUG . "/");

        return sprintf(
            '<div data-biblio-ui-root data-rest-root="%s" '
                . 'data-rest-nonce="%s" data-overview-url="%s" '
                . 'data-login-url="%s"></div>',
            esc_url(rest_url("biblio/v1/")),
            esc_attr(wp_create_nonce("wp_rest")),
            esc_url($overviewUrl),
            esc_url(wp_login_url($overviewUrl))
        );
    }
}
