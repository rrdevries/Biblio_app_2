<?php

declare(strict_types=1);

namespace Biblio\UI;

use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Exception\ValidationException;
use Biblio\Core\Library\LibraryId;
use Biblio\Core\Identity\UserId;
use Throwable;
use WP_Error;
use WP_User;

final class LibraryNamePresentation
{
    private ?CoreApplication $core = null;

    public function __construct(private readonly string $pluginFile) {}

    public function boot(): void
    {
        add_action("biblio_core_initialized", function (CoreApplication $core): void { $this->core = $core; });
        add_filter("login_redirect", [$this, "loginRedirect"], 20, 3);
        add_action("template_redirect", [$this, "route"], 5);
    }

    public function loginRedirect(string $destination, string $requested, WP_User|WP_Error $user): string
    {
        if (!$user instanceof WP_User || $this->core === null) { return $destination; }
        try {
            // wp_signon validates the candidate but does not set the current user.
            if ($this->core->accountPreparation()->requiresNamingAfterAuthentication(new UserId((string) $user->ID))) {
                return $this->setupUrl($this->safeDestination($destination));
            }
        } catch (Throwable) { /* Authentication/preparation failures have their own adapter. */ }
        return $destination;
    }

    public function route(): void
    {
        if (!is_page(["mijn-biblio", "bibliotheek-home", "mijn-bibliotheek", "zoeken", "verlanglijst", "hierna-lezen"])) { return; }
        $initial = isset($_GET["biblio_setup"]);
        $rename = isset($_GET["biblio_name"]);
        if (!is_user_logged_in()) {
            if ($initial || $rename) { wp_safe_redirect(wp_login_url($this->safeDestination($this->currentUrl()))); exit; }
            return;
        }
        if ($this->core === null) { return; }
        try { $state = $this->core->accountPreparation()->myStatus(); }
        catch (Throwable) { return; }
        if ($state["state"] === "pending") {
            $this->render("Je account wordt voorbereid", "Je account is nog niet volledig klaar. Neem contact op met de beheerder.", null, "", "", "", false);
            exit;
        }
        if ($state["needs_name"] && !$initial) {
            wp_safe_redirect($this->setupUrl($this->safeDestination($this->currentUrl()))); exit;
        }
        if (!$initial && !$rename) { return; }
        $default = home_url("/mijn-biblio/");
        if ($rename) {
            $requestedId = $_GET["library_id"] ?? "";
            if (!is_string($requestedId) || $requestedId !== $state["library_id"] || $state["name"] === null) {
                status_header(403);
                $this->render("Bibliotheek niet beschikbaar", "Je kunt deze Bibliotheeknaam niet wijzigen.", null, "", "", "", false);
                exit;
            }
            $default = add_query_arg("library_id", $requestedId, home_url("/bibliotheek-home/"));
        }
        $rawDestination = $_POST["redirect_to"] ?? $_GET["redirect_to"] ?? $default;
        $destination = $this->safeDestination(is_string($rawDestination) ? wp_unslash($rawDestination) : $default, $default);
        if ($initial && !$state["needs_name"]) { wp_safe_redirect($destination); exit; }
        $id = $state["library_id"];
        if ($id === null) { status_header(403); return; }
        $value = $rename ? ($state["name"] ?? "") : "";
        $error = "";
        if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST") {
            $posted = $_POST["library_name"] ?? "";
            $value = is_string($posted) ? wp_unslash($posted) : "";
            $nonce = $_POST["_wpnonce"] ?? "";
            try {
                if (!is_string($nonce) || !wp_verify_nonce($nonce, "biblio-name-" . $id)) {
                    throw new \RuntimeException("Name form session expired.");
                }
                $this->core->accountPreparation()->saveName(new LibraryId($id), $value);
                wp_safe_redirect($destination); exit;
            } catch (ValidationException) {
                $error = "Vul een Bibliotheeknaam in van maximaal 191 tekens.";
            } catch (Throwable) {
                $error = "Opslaan is niet gelukt. Controleer je sessie en toegang en probeer opnieuw; je invoer blijft staan.";
            }
        }
        $this->render(
            $rename ? "Bibliotheeknaam wijzigen" : "Geef je bibliotheek een naam",
            $rename ? "Kies een nieuwe naam voor je persoonlijke bibliotheek. Je boeken blijven behouden."
                : "Je persoonlijke bibliotheek staat voor je klaar. Kies een naam die bij jouw boeken past. Je kunt deze later veranderen.",
            $id, $value, $destination, $error, $rename
        );
        exit;
    }

    public function safeDestination(string $value, ?string $fallback = null): string
    {
        $fallback ??= home_url("/mijn-biblio/");
        $safe = wp_validate_redirect($value, $fallback);
        if (str_starts_with($safe, "/") && !str_starts_with($safe, "//")) { $safe = rtrim(home_url("/"), "/") . $safe; }
        $home = wp_parse_url(home_url("/"));
        $target = wp_parse_url($safe);
        if (!is_array($home) || !is_array($target)
            || ($target["host"] ?? "") !== ($home["host"] ?? "")
            || ($target["scheme"] ?? "") !== ($home["scheme"] ?? "")
            || ($target["port"] ?? null) !== ($home["port"] ?? null)
            || isset($target["user"]) || isset($target["pass"])) { return $fallback; }
        parse_str($target["query"] ?? "", $query);
        return isset($query["biblio_setup"]) || isset($query["biblio_name"]) ? $fallback : $safe;
    }

    private function setupUrl(string $destination): string
    {
        return add_query_arg(["biblio_setup" => "1", "redirect_to" => $destination], home_url("/mijn-biblio/"));
    }

    private function currentUrl(): string
    {
        $uri = $_SERVER["REQUEST_URI"] ?? "/mijn-biblio/";
        return is_string($uri) ? $uri : "/mijn-biblio/";
    }

    private function render(string $heading, string $copy, ?string $id, string $value, string $destination, string $error, bool $rename): void
    {
        nocache_headers();
        header("Content-Type: text/html; charset=UTF-8");
        echo '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'
            . esc_html($heading . " | Biblio") . '</title><link rel="stylesheet" href="' . esc_url(admin_url("css/login.min.css"))
            . '"><link rel="stylesheet" href="' . esc_url(plugin_dir_url($this->pluginFile) . "assets/css/login.css?ver=" . Plugin::VERSION)
            . '"><link rel="stylesheet" href="' . esc_url(plugin_dir_url($this->pluginFile) . "assets/css/library-name.css?ver=" . Plugin::VERSION)
            . '"></head><body class="login biblio-name-page"><main id="login"><div class="biblio-login__intro"><h1>'
            . esc_html($heading) . '</h1><p>' . esc_html($copy) . '</p></div>';
        if ($id !== null) {
            echo '<form method="post" aria-label="Bibliotheeknaam">';
            wp_nonce_field("biblio-name-" . $id);
            echo '<input type="hidden" name="redirect_to" value="' . esc_attr($destination) . '">'
                . '<p><label for="library-name">Bibliotheeknaam</label><input id="library-name" name="library_name" type="text" required maxlength="191" autocomplete="off" value="'
                . esc_attr($value) . '" placeholder="Bijvoorbeeld: Renées boeken"'
                . ($error !== "" ? ' aria-invalid="true" aria-describedby="library-name-error"' : '') . ' autofocus></p>';
            if ($error !== "") { echo '<p id="library-name-error" class="biblio-name__error" role="alert">' . esc_html($error) . '</p>'; }
            echo '<div class="biblio-name__actions"><button class="button button-primary button-large" type="submit">'
                . ($rename ? "Opslaan" : "Opslaan en verder") . '</button>';
            if ($rename) { echo '<a href="' . esc_url($destination) . '">Annuleren</a>'; }
            echo '</div></form>';
        }
        echo '<p class="biblio-name__logout"><a href="' . esc_url(wp_logout_url(home_url("/"))) . '">Uitloggen</a></p></main></body></html>';
    }
}
