<?php

declare(strict_types=1);

namespace Biblio\UI;

final class Plugin
{
    public const VERSION = "0.1.0";
    public const SCRIPT_MODULE_ID = "biblio-ui/app";
    public const API_SCRIPT_MODULE_ID = "biblio-ui/api";
    public const ROUTE_SCRIPT_MODULE_ID = "biblio-ui/route-state";
    public const LIBRARY_SCRIPT_MODULE_ID = "biblio-ui/library-state";
    public const OVERVIEW_SCRIPT_MODULE_ID = "biblio-ui/overview-view";
    public const DETAIL_SCRIPT_MODULE_ID = "biblio-ui/detail-view";
    public const STYLE_HANDLE = "biblio-ui";

    private bool $booted = false;
    private readonly LibraryAppShortcode $libraryAppShortcode;

    public function __construct(
        private readonly string $pluginFile,
        ?LibraryAppShortcode $libraryAppShortcode = null
    ) {
        $this->libraryAppShortcode = $libraryAppShortcode
            ?? new LibraryAppShortcode();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        add_action("init", [$this->libraryAppShortcode, "register"]);
        add_action("wp_enqueue_scripts", [$this, "registerAndEnqueueAssets"]);
        $this->booted = true;
    }

    public function registerAndEnqueueAssets(): void
    {
        $assetBaseUrl = plugin_dir_url($this->pluginFile) . "assets/";

        wp_register_script_module(
            self::API_SCRIPT_MODULE_ID,
            $assetBaseUrl . "js/api.js",
            [],
            self::VERSION
        );
        wp_register_script_module(
            self::ROUTE_SCRIPT_MODULE_ID,
            $assetBaseUrl . "js/route-state.js",
            [],
            self::VERSION
        );
        wp_register_script_module(
            self::LIBRARY_SCRIPT_MODULE_ID,
            $assetBaseUrl . "js/library-state.js",
            [],
            self::VERSION
        );
        wp_register_script_module(
            self::OVERVIEW_SCRIPT_MODULE_ID,
            $assetBaseUrl . "js/overview-view.js",
            [],
            self::VERSION
        );
        wp_register_script_module(
            self::DETAIL_SCRIPT_MODULE_ID,
            $assetBaseUrl . "js/detail-view.js",
            [],
            self::VERSION
        );
        wp_register_script_module(
            self::SCRIPT_MODULE_ID,
            $assetBaseUrl . "js/app.js",
            [[
                "id" => self::API_SCRIPT_MODULE_ID,
                "import" => "static",
            ], [
                "id" => self::ROUTE_SCRIPT_MODULE_ID,
                "import" => "static",
            ], [
                "id" => self::LIBRARY_SCRIPT_MODULE_ID,
                "import" => "static",
            ], [
                "id" => self::OVERVIEW_SCRIPT_MODULE_ID,
                "import" => "static",
            ], [
                "id" => self::DETAIL_SCRIPT_MODULE_ID,
                "import" => "static",
            ]],
            self::VERSION
        );
        wp_register_style(
            self::STYLE_HANDLE,
            $assetBaseUrl . "css/app.css",
            [],
            self::VERSION
        );

        if (!is_page(LibraryAppShortcode::PAGE_SLUG)) {
            return;
        }

        wp_enqueue_script_module(self::SCRIPT_MODULE_ID);
        wp_enqueue_style(self::STYLE_HANDLE);
    }
}
