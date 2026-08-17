<?php

declare(strict_types=1);

namespace Biblio\Core;

use Biblio\Core\Application\CoreApplication;
use Biblio\Core\Exception\CoreFailure;
use Biblio\Core\Exception\FailureReason;
use Biblio\Core\Infrastructure\WordPress\Lifecycle\CoreLifecycleException;
use Biblio\Core\Infrastructure\WordPress\ProductionComposition;
use Closure;
use Throwable;
use wpdb;

final class Plugin
{
    public const VERSION = "2.1.0";

    private bool $hooksRegistered = false;
    private bool $initialized = false;
    private ?ProductionComposition $composition = null;
    private ?Throwable $bootFailure = null;

    /** @var Closure(): ProductionComposition */
    private readonly Closure $compositionFactory;

    /** @param null|Closure(): ProductionComposition $compositionFactory */
    public function __construct(
        private readonly string $pluginFile,
        ?Closure $compositionFactory = null
    ) {
        $this->compositionFactory = $compositionFactory
            ?? static function (): ProductionComposition {
                global $wpdb;

                if (!$wpdb instanceof wpdb) {
                    throw new CoreLifecycleException(
                        "WordPress database connection is unavailable.",
                        FailureReason::PersistenceFailure
                    );
                }

                return new ProductionComposition($wpdb);
            };
    }

    public function boot(): void
    {
        if ($this->hooksRegistered) {
            return;
        }

        register_activation_hook($this->pluginFile, [$this, "activate"]);
        add_action("init", [$this, "initialize"], 1);
        add_action("admin_notices", [$this, "renderAdminNotice"]);
        $this->hooksRegistered = true;
    }

    public function activate(bool $networkWide = false): void
    {
        try {
            $this->composition()->lifecycle()->activate();
        } catch (Throwable $exception) {
            $this->recordFailure($exception);

            throw $exception;
        }
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initialized = true;

        try {
            $composition = $this->composition();
            $composition->lifecycle()->boot();
        } catch (Throwable $exception) {
            $this->recordFailure($exception);

            return;
        }

        do_action("biblio_core_initialized", $composition->application());
    }

    public function renderAdminNotice(): void
    {
        if (
            $this->bootFailure === null
            || !current_user_can("activate_plugins")
        ) {
            return;
        }

        $reason = $this->bootFailure instanceof CoreFailure
            ? $this->bootFailure->reason()
            : FailureReason::PersistenceFailure;

        echo '<div class="notice notice-error"><p>'
            . esc_html(
                "Biblio Core is niet operationeel. Reden: {$reason->value}. "
                . "Controleer de serverlog; er is geen automatische "
                . "schemareparatie uitgevoerd."
            )
            . "</p></div>";
    }

    public function application(): ?CoreApplication
    {
        if (!$this->initialized || $this->bootFailure !== null) {
            return null;
        }

        return $this->composition?->application();
    }

    public function bootFailure(): ?Throwable
    {
        return $this->bootFailure;
    }

    private function composition(): ProductionComposition
    {
        return $this->composition ??= ($this->compositionFactory)();
    }

    private function recordFailure(Throwable $exception): void
    {
        $this->bootFailure = $exception;
        $reason = $exception instanceof CoreFailure
            ? $exception->reason()
            : FailureReason::PersistenceFailure;

        if (
            !$exception instanceof CoreLifecycleException
            || !$exception->isCached()
        ) {
            error_log(
                "Biblio Core lifecycle failure [{$reason->value}]: "
                . $exception->getMessage()
            );
        }

        do_action("biblio_core_boot_failed", $reason, $exception);
    }
}
