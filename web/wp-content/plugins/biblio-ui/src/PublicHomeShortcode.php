<?php

declare(strict_types=1);

namespace Biblio\UI;

final class PublicHomeShortcode
{
    public const TAG = "biblio_public_home";
    public const PAGE_SLUG = "biblio";
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
    public function render(array|string $attributes = []): string
    {
        $authenticated = is_user_logged_in();
        $actionUrl = $authenticated
            ? home_url("/" . EntryAppShortcode::PERSONAL_SLUG . "/")
            : wp_login_url(home_url("/" . EntryAppShortcode::PERSONAL_SLUG . "/"));
        $actionLabel = $authenticated ? "Naar Mijn Biblio" : "Inloggen";

        return '<section class="biblio-public-home" aria-labelledby="biblio-public-home-title">'
            . '<div class="biblio-public-home__art" aria-hidden="true">'
            . '<div class="biblio-public-home__books"><span></span><span></span><span></span></div>'
            . '</div>'
            . '<div class="biblio-public-home__content"><div class="biblio-public-home__inner">'
            . '<h1 id="biblio-public-home-title">Biblio</h1>'
            . '<p>Biblio helpt je om boeken terug te vinden en je leesplannen bij te houden. '
            . 'Na het inloggen kom je in Mijn Biblio: daar vind je je persoonlijke Verlanglijst en Hierna lezen, '
            . 'en kies je de bibliotheek die je wilt openen. Iedere bibliotheek heeft een eigen startpagina '
            . 'en catalogus, zodat je steeds weet waar je bent.</p>'
            . '<a class="biblio-public-home__action" href="' . esc_url($actionUrl) . '">'
            . esc_html($actionLabel) . '<span aria-hidden="true">→</span></a>'
            . '</div></div></section>';
    }
}
