<?php

declare(strict_types=1);

namespace Biblio\UI;

final class LoginPresentation
{
    public const STYLE_HANDLE = "biblio-ui-login";

    public function __construct(private readonly string $pluginFile)
    {
    }

    public function boot(): void
    {
        add_action("login_enqueue_scripts", [$this, "enqueueStyle"]);
        add_action("login_init", [$this, "registerLabelTranslation"]);
        add_action("login_form", [$this, "redirectIntentField"]);
        add_filter("login_headerurl", [$this, "headerUrl"]);
        add_filter("login_headertext", [$this, "headerText"]);
        add_filter("login_title", [$this, "pageTitle"], 10, 2);
        add_filter("login_message", [$this, "intro"]);
        add_filter("login_site_html_link", [$this, "returnLink"]);
        add_filter("login_redirect", [$this, "defaultRedirect"], 10, 3);
    }

    public function enqueueStyle(): void
    {
        wp_enqueue_style(
            self::STYLE_HANDLE,
            plugin_dir_url($this->pluginFile) . "assets/css/login.css",
            [],
            Plugin::VERSION
        );
    }

    public function registerLabelTranslation(): void
    {
        add_filter("gettext", [$this, "translateLoginLabel"], 10, 3);
    }

    public function translateLoginLabel(string $translation, string $original, string $domain): string
    {
        if ($domain === "default" && in_array($original, ["Log In", "Log in"], true)) {
            return "Inloggen";
        }

        return $translation;
    }

    public function headerUrl(string $url): string
    {
        return home_url("/");
    }

    public function headerText(string $text): string
    {
        return "Biblio";
    }

    public function pageTitle(string $loginTitle, string $title): string
    {
        return match ($this->action()) {
            "login" => "Inloggen bij Biblio",
            "lostpassword", "retrievepassword" => "Wachtwoord herstellen | Biblio",
            default => $loginTitle,
        };
    }

    public function intro(string $message): string
    {
        $heading = match ($this->action()) {
            "login" => "Inloggen bij Biblio",
            "lostpassword", "retrievepassword" => "Wachtwoord herstellen",
            default => null,
        };

        if ($heading === null) {
            return $message;
        }

        return '<div class="biblio-login__intro"><h1>' . $heading . '</h1>'
            . '</div>' . $message;
    }

    public function returnLink(string $htmlLink): string
    {
        return '<a href="' . esc_url(home_url("/"))
            . '">Terug naar startpagina</a>';
    }

    public function redirectIntentField(): void
    {
        $requested = $_POST["biblio_redirect_requested"]
            ?? $_GET["redirect_to"]
            ?? "";
        $explicit = is_string($requested) && $requested !== "" && $requested !== "0";
        echo '<input type="hidden" name="biblio_redirect_requested" value="'
            . ($explicit ? "1" : "0") . '">';
    }

    public function defaultRedirect(
        string $redirectTo,
        string $requestedRedirectTo,
        \WP_User|\WP_Error $user
    ): string {
        if (
            !($user instanceof \WP_User)
            || user_can($user, "manage_options")
        ) {
            return $redirectTo;
        }

        // WordPress posts its admin URL as redirect_to even when no destination was requested.
        if (
            $requestedRedirectTo !== ""
            && (
                $requestedRedirectTo !== admin_url()
                || ($_POST["biblio_redirect_requested"] ?? "") === "1"
            )
        ) {
            return $redirectTo;
        }

        return home_url("/mijn-biblio/");
    }

    private function action(): string
    {
        $action = $_GET["action"] ?? "login";
        return is_string($action) ? $action : "login";
    }
}
