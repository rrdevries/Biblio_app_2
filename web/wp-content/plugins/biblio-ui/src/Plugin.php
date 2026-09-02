<?php

declare(strict_types=1);

namespace Biblio\UI;

final class Plugin
{
    public const VERSION = "0.2.0";
    public const SCRIPT_MODULE_ID = "biblio-ui/app";
    public const API_SCRIPT_MODULE_ID = "biblio-ui/api";
    public const ROUTE_SCRIPT_MODULE_ID = "biblio-ui/route-state";
    public const LIBRARY_SCRIPT_MODULE_ID = "biblio-ui/library-state";
    public const OVERVIEW_SCRIPT_MODULE_ID = "biblio-ui/overview-view";
    public const PRIVATE_NOTES_SCRIPT_MODULE_ID = "biblio-ui/private-notes";
    public const READING_HISTORY_SCRIPT_MODULE_ID = "biblio-ui/reading-history";
    public const DETAIL_SCRIPT_MODULE_ID = "biblio-ui/detail-view";
    public const END_READING_SCRIPT_MODULE_ID = "biblio-ui/end-reading-view";
    public const START_READING_SCRIPT_MODULE_ID = "biblio-ui/start-reading-view";
    public const NEXT_READING_SCRIPT_MODULE_ID = "biblio-ui/next-reading";
    public const STYLE_HANDLE = "biblio-ui";

    private bool $booted = false;
    private readonly LibraryAppShortcode $libraryAppShortcode;
    private readonly NextReadingAppShortcode $nextReadingAppShortcode;

    public function __construct(
        private readonly string $pluginFile,
        ?LibraryAppShortcode $libraryAppShortcode = null,
        ?NextReadingAppShortcode $nextReadingAppShortcode = null
    ) {
        $this->libraryAppShortcode = $libraryAppShortcode
            ?? new LibraryAppShortcode();
        $this->nextReadingAppShortcode = $nextReadingAppShortcode
            ?? new NextReadingAppShortcode();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        add_action("init", [$this->libraryAppShortcode, "register"]);
        add_action("init", [$this->nextReadingAppShortcode, "register"]);
        add_action("wp_enqueue_scripts", [$this, "registerAndEnqueueAssets"]);
        $this->booted = true;
    }

    public function registerAndEnqueueAssets(): void
    {
        $assetBaseUrl = plugin_dir_url($this->pluginFile) . "assets/";

        wp_register_script_module(
            self::NEXT_READING_SCRIPT_MODULE_ID,
            $assetBaseUrl . "js/next-reading.js",
            [[
                "id" => self::API_SCRIPT_MODULE_ID,
                "import" => "static",
            ]],
            self::VERSION
        );
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
            self::READING_HISTORY_SCRIPT_MODULE_ID,
            $assetBaseUrl . "js/reading-history.js",
            [],
            self::VERSION
        );
        wp_register_script_module(
            self::PRIVATE_NOTES_SCRIPT_MODULE_ID,
            $assetBaseUrl . "js/private-notes.js",
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
            self::START_READING_SCRIPT_MODULE_ID,
            $assetBaseUrl . "js/start-reading-view.js",
            [],
            self::VERSION
        );
        wp_register_script_module(
            self::END_READING_SCRIPT_MODULE_ID,
            $assetBaseUrl . "js/end-reading-view.js",
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
                "id" => self::PRIVATE_NOTES_SCRIPT_MODULE_ID,
                "import" => "static",
            ], [
                "id" => self::READING_HISTORY_SCRIPT_MODULE_ID,
                "import" => "static",
            ], [
                "id" => self::DETAIL_SCRIPT_MODULE_ID,
                "import" => "static",
            ], [
                "id" => self::START_READING_SCRIPT_MODULE_ID,
                "import" => "static",
            ], [
                "id" => self::END_READING_SCRIPT_MODULE_ID,
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

        if (is_page(NextReadingAppShortcode::PAGE_SLUG)) {
            wp_enqueue_script_module(self::NEXT_READING_SCRIPT_MODULE_ID);
            wp_enqueue_style(self::STYLE_HANDLE);

            return;
        }

        if (!is_page(LibraryAppShortcode::PAGE_SLUG)) {
            return;
        }

        wp_enqueue_script_module(self::SCRIPT_MODULE_ID);
        wp_enqueue_style(self::STYLE_HANDLE);
    }
}
