<?php

declare(strict_types=1);

namespace Biblio\Core\Infrastructure\WordPress\Identity;

use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Application\Accounts\AccountPreparationService;
use Biblio\Core\Identity\UserId;
use Biblio\Core\Library\LibraryId;
use Closure;
use Throwable;
use WP_Error;
use WP_User;

final class AccountPreparationAdapter
{
    private ?int $failedTarget = null;
    private ?int $sendingTarget = null;
    private bool $mailSucceeded = false;

    /** @param Closure(): ?CoreApplication $application */
    public function __construct(private readonly Closure $application) {}

    public function boot(): void
    {
        add_action("user_new_form", [$this, "newFields"]);
        add_action("user_profile_update_errors", [$this, "validateNewAccount"], 10, 3);
        add_action("user_register", [$this, "registered"], 20, 2);
        add_action("edit_user_created_user", [$this, "created"], 9, 2);
        add_filter("wp_send_new_user_notification_to_user", [$this, "allowNotification"], 20, 2);
        add_filter("wp_authenticate_user", [$this, "authenticate"], 30, 2);
        add_filter("allow_password_reset", [$this, "allowReset"], 30, 2);
        add_filter("wp_redirect", [$this, "creationRedirect"]);
        add_action("wp_mail_succeeded", [$this, "mailSucceeded"]);
        add_action("admin_menu", [$this, "menu"]);
        add_action("admin_post_biblio_account_resume", [$this, "resume"]);
        add_action("admin_notices", [$this, "notice"]);
    }

    public function newFields(string $operation): void
    {
        if ($operation !== "add-new-user" || !current_user_can("create_users")) { return; }
        echo '<h2>Biblio</h2><table class="form-table"><tr><th>Biblio-account</th><td>'
            . '<label><input type="checkbox" name="biblio_account" value="1" checked> Persoonlijk account met één lege Privébibliotheek</label>'
            . '<p class="description">Gebruik de rol Abonnee. Biblio verstuurt de wachtwoordlink zodra de Bibliotheek klaarstaat. Laat dit uit voor een afzonderlijk WordPress-beheeraccount.</p></td></tr>'
            . '<tr><th><label for="biblio_display_name">Zichtbare accountnaam</label></th><td><input id="biblio_display_name" name="biblio_display_name" type="text">'
            . '<p class="description">Optioneel; de gebruiker kiest de Bibliotheeknaam bij eerste login.</p></td></tr></table>';
    }

    public function validateNewAccount(WP_Error $errors, bool $update, object $user): void
    {
        if ($update || ($_POST["biblio_account"] ?? "") !== "1") { return; }
        try {
            $nonce = $_POST["_wpnonce_create-user"] ?? "";
            if (!is_string($nonce) || !wp_verify_nonce($nonce, "create-user")) {
                throw new \RuntimeException("Invalid account-creation nonce.");
            }
            $this->service()->assertManagement();
            if (($user->role ?? "") !== "subscriber") { throw new \RuntimeException("Biblio personal accounts use the subscriber role."); }
            $user->meta_input = [WordPressAccountDirectory::INTENT_KEY => "1"] + (array) ($user->meta_input ?? []);
            $name = $_POST["biblio_display_name"] ?? "";
            if (is_string($name) && trim($name) !== "") { $user->display_name = sanitize_text_field(wp_unslash($name)); }
        } catch (Throwable) {
            $errors->add("biblio_account", "Biblio-accountaanmaak vereist bevoegd gebruikersbeheer en de rol Abonnee. Ververs het formulier en probeer opnieuw.");
        }
    }

    /** @param array<string,mixed> $data */
    public function registered(int $userId, array $data): void
    {
        if (($data["meta_input"][WordPressAccountDirectory::INTENT_KEY] ?? null) !== "1") { return; }
        try {
            if (!(new WordPressAccountDirectory())->isRequested(new UserId((string) $userId))) {
                throw new \RuntimeException("Account intent was not persisted.");
            }
            $this->service()->prepare(new UserId((string) $userId));
        } catch (Throwable) {
            $this->failedTarget = $userId;
        }
    }

    public function created(int|WP_Error $userId, string $notify): void
    {
        if (!is_int($userId) || !(new WordPressAccountDirectory())->isRequested(new UserId((string) $userId))) { return; }
        try {
            if (!$this->notify($userId)) { $this->failedTarget = $userId; }
        } catch (Throwable) { $this->failedTarget = $userId; }
    }

    public function allowNotification(bool $send, WP_User $user): bool
    {
        if (!(new WordPressAccountDirectory())->isRequested(new UserId((string) $user->ID))) { return $send; }
        if ($this->sendingTarget !== (int) $user->ID) { return false; }
        try { $this->service()->requireReady(new UserId((string) $user->ID), false); return $send; }
        catch (Throwable) { return false; }
    }

    /** @param array<string,mixed> $data */
    public function mailSucceeded(array $data): void
    {
        if ($this->sendingTarget === null) { return; }
        $user = get_userdata($this->sendingTarget);
        if ($user instanceof WP_User && in_array($user->user_email, (array) ($data["to"] ?? []), true)) {
            $this->mailSucceeded = true;
        }
    }

    public function authenticate(WP_User|WP_Error $user, string $password): WP_User|WP_Error
    {
        if (!$user instanceof WP_User) { return $user; }
        if (!(new WordPressAccountDirectory())->isRequested(new UserId((string) $user->ID))) { return $user; }
        try { $this->service()->requireReady(new UserId((string) $user->ID), false); return $user; }
        catch (Throwable) { return new WP_Error("biblio_preparation_pending", "Je Biblio-account is nog niet klaar. Neem contact op met de beheerder."); }
    }

    public function allowReset(bool|WP_Error $allowed, int $userId): bool|WP_Error
    {
        if (!(new WordPressAccountDirectory())->isRequested(new UserId((string) $userId))) { return $allowed; }
        try { $this->service()->requireReady(new UserId((string) $userId), false); return $allowed; }
        catch (Throwable) { return new WP_Error("biblio_preparation_pending", "Dit account is nog niet volledig voorbereid."); }
    }

    public function creationRedirect(string $location): string
    {
        return $this->failedTarget === null ? $location : add_query_arg([
            "page" => "biblio-accounts", "user_id" => $this->failedTarget, "biblio_result" => "incomplete",
        ], admin_url("users.php"));
    }

    public function menu(): void
    {
        add_users_page("Biblio-accountvoorbereiding", "Biblio-accounts", "create_users", "biblio-accounts", [$this, "render"]);
    }

    public function render(): void
    {
        echo '<div class="wrap"><h1>Biblio-accountvoorbereiding</h1><form method="get">'
            . '<input type="hidden" name="page" value="biblio-accounts"><label>Account-ID <input name="user_id" type="number" min="1" required value="'
            . esc_attr((string) absint($_GET["user_id"] ?? 0)) . '"></label> <button class="button">Controleren</button></form>';
        $id = absint($_GET["user_id"] ?? 0);
        if ($id > 0) {
            try {
                $state = $this->service()->diagnose(new UserId((string) $id));
                $label = match ($state["state"]) {
                    "pending" => "Voorbereiding onvolledig",
                    "ready" => $state["needs_name"] ? "Gereed; naamgeving bij eerste login" : "Gereed",
                    "legacy" => "Bestaande persoonlijke koppeling correct; behouden",
                    default => "Persoonlijke koppeling vereist beoordeling",
                };
                echo '<h2>' . esc_html($label) . '</h2><p>Persoonlijke Bibliotheek-ID: '
                    . esc_html($state["library_id"] ?? "geen") . '</p><p>Bestaande eigenaarskoppelingen: '
                    . esc_html(implode(", ", $state["owned_libraries"]) ?: "geen") . '</p>';
                if ($state["requested"]) {
                    echo '<p>Wachtwoordmelding: ' . ($state["notified"]
                        ? 'verstuurd.'
                        : 'nog niet verstuurd. Hervat de voorbereiding om de wachtwoordlink te verzenden.') . '</p>';
                }
                if ($state["state"] !== "legacy") {
                    echo '<form method="post" action="' . esc_url(admin_url("admin-post.php")) . '">';
                    wp_nonce_field("biblio-account-" . $id);
                    echo '<input type="hidden" name="action" value="biblio_account_resume"><input type="hidden" name="user_id" value="' . $id . '">';
                    if (!$state["requested"]) {
                        echo '<p><label>Exacte bestaande Bibliotheek-ID <input name="library_id" type="text"></label></p>'
                            . '<p>Laat alleen leeg als er geen bestaande eigen Bibliotheek is. Biblio behoudt gegevens en weigert tegenstrijdige koppelingen.</p>';
                    }
                    echo '<button class="button button-primary">' . ($state["requested"] ? "Voorbereiding en wachtwoordlink hervatten" : "Gericht herstellen") . '</button></form>';
                }
            } catch (Throwable) { echo '<p>Controle niet beschikbaar. Controleer de account-ID, bevoegdheid en Core-status.</p>'; }
        }
        echo '</div>';
    }

    public function resume(): void
    {
        $id = absint($_POST["user_id"] ?? 0);
        check_admin_referer("biblio-account-" . $id);
        try {
            $target = new UserId((string) $id);
            $state = $this->service()->diagnose($target);
            if ($state["requested"]) {
                $this->service()->prepare($target);
                if (!$this->notify($id)) { throw new \RuntimeException("Notification was not accepted."); }
            } else {
                $library = $_POST["library_id"] ?? "";
                if (!is_string($library)) { throw new \RuntimeException("Invalid Library ID."); }
                $library = trim(wp_unslash($library));
                $this->service()->repair($target, $library === "" ? null : new LibraryId($library));
            }
            $result = "ready";
        } catch (Throwable) { $result = "incomplete"; }
        wp_safe_redirect(add_query_arg(["page" => "biblio-accounts", "user_id" => $id, "biblio_result" => $result], admin_url("users.php")));
        exit;
    }

    public function notice(): void
    {
        if (!current_user_can("create_users") || ($_GET["page"] ?? "") !== "biblio-accounts") { return; }
        $id = absint($_GET["user_id"] ?? 0);
        if ($id === 0) { return; }
        try { $state = $this->service()->diagnose(new UserId((string) $id)); }
        catch (Throwable) { return; }
        $ready = $state["state"] === "legacy"
            || ($state["state"] === "ready" && (!$state["requested"] || $state["notified"]));
        if ($ready && !isset($_GET["biblio_result"])) { return; }
        echo '<div class="notice ' . ($ready ? "notice-success" : "notice-error") . '"><p>'
            . ($ready ? "Biblio-accountvoorbereiding afgerond." : "De accountvoorbereiding of wachtwoordmelding is niet afgerond. Controleer dit account en hervat de voorbereiding; maak geen tweede account.")
            . '</p></div>';
    }

    private function notify(int $id): bool
    {
        return $this->service()->notify(new UserId((string) $id), function () use ($id): bool {
            $this->sendingTarget = $id;
            $this->mailSucceeded = false;
            try { wp_new_user_notification($id, null, "user"); return $this->mailSucceeded; }
            finally { $this->sendingTarget = null; }
        });
    }

    private function service(): AccountPreparationService
    {
        return ($this->application)()?->accountPreparation() ?? throw new \RuntimeException("Biblio Core unavailable.");
    }
}
