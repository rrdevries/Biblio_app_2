<?php

declare(strict_types=1);

namespace Biblio\UI;

final class Plugin
{
    public const VERSION = "0.1.0";

    private bool $booted = false;
    private readonly LibraryAppShortcode $libraryAppShortcode;

    public function __construct(?LibraryAppShortcode $libraryAppShortcode = null)
    {
        $this->libraryAppShortcode = $libraryAppShortcode
            ?? new LibraryAppShortcode();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        add_action("init", [$this->libraryAppShortcode, "register"]);
        $this->booted = true;
    }
}
