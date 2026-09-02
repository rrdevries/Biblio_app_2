<?php

declare(strict_types=1);

namespace Biblio\UI;

final class NextReadingAppShortcode
{
    public const TAG = "biblio_next_reading_app";
    public const PAGE_SLUG = "hierna-lezen";

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
            '<div data-biblio-ui-root data-biblio-next-reading-root data-rest-root="%s" '
                . 'data-rest-nonce="%s" data-login-url="%s"></div>',
            esc_url(rest_url("biblio/v1/")),
            esc_attr(wp_create_nonce("wp_rest")),
            esc_url(wp_login_url($pageUrl))
        );
    }
}
