<?php

declare(strict_types=1);

namespace Biblio\Core;

final class Plugin
{
    public const VERSION = "2.1.0";

    public static function boot(): void
    {
        add_action("init", [self::class, "onInit"]);
    }

    public static function onInit(): void
    {
        do_action("biblio_core_initialized");
    }
}
