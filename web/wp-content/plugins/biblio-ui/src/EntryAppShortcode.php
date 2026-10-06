<?php

declare(strict_types=1);

namespace Biblio\UI;

final class EntryAppShortcode
{
    public const PERSONAL_TAG = "biblio_personal_home";
    public const PERSONAL_SLUG = "mijn-biblio";
    public const LIBRARY_TAG = "biblio_library_home";
    public const LIBRARY_SLUG = "bibliotheek-home";
    private bool $registered = false;

    public function register(): void
    {
        if ($this->registered) {
            return;
        }
        add_shortcode(self::PERSONAL_TAG, [$this, "renderPersonal"]);
        add_shortcode(self::LIBRARY_TAG, [$this, "renderLibrary"]);
        $this->registered = true;
    }

    /** @param array<string, mixed>|string $attributes */
    public function renderPersonal(array|string $attributes = []): string
    {
        return $this->render(self::PERSONAL_SLUG, "personal");
    }

    /** @param array<string, mixed>|string $attributes */
    public function renderLibrary(array|string $attributes = []): string
    {
        return $this->render(self::LIBRARY_SLUG, "library");
    }

    private function render(string $slug, string $mode): string
    {
        $pageUrl = home_url("/{$slug}/");
        $returnUrl = $pageUrl;
        if ($mode === "library" && isset($_GET["library_id"])
            && is_string($_GET["library_id"])
            && $_GET["library_id"] !== ""
            && strlen($_GET["library_id"]) <= 191
            && !preg_match('/[\x00-\x1F\x7F]/', $_GET["library_id"])) {
            $returnUrl .= "?library_id=" . rawurlencode($_GET["library_id"]);
        }

        return sprintf(
            '<div data-biblio-ui-root data-biblio-entry-root data-entry-mode="%s" '
                . 'data-rest-root="%s" data-rest-nonce="%s" '
                . 'data-platform-url="%s" data-library-home-url="%s" '
                . 'data-overview-url="%s" data-search-url="%s" '
                . 'data-wishlist-url="%s" data-next-reading-url="%s" '
                . 'data-settings-url="%s" data-library-settings-url="%s" data-login-url="%s" %s></div>',
            esc_attr($mode),
            esc_url(rest_url("biblio/v1/")),
            esc_attr(wp_create_nonce("wp_rest")),
            esc_url(home_url("/" . self::PERSONAL_SLUG . "/")),
            esc_url(home_url("/" . self::LIBRARY_SLUG . "/")),
            esc_url(home_url("/" . LibraryAppShortcode::PAGE_SLUG . "/")),
            esc_url(home_url("/" . SearchAppShortcode::PAGE_SLUG . "/")),
            esc_url(home_url("/" . WishlistAppShortcode::PAGE_SLUG . "/")),
            esc_url(home_url("/" . NextReadingAppShortcode::PAGE_SLUG . "/")),
            esc_url(home_url("/instellingen/")),
            esc_url(home_url("/" . SettingsAppShortcode::MANAGEMENT_SLUG . "/")),
            esc_url(wp_login_url($returnUrl)),
            AccountMount::attributes()
        );
    }
}
