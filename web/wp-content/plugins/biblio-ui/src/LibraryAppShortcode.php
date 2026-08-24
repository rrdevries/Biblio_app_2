<?php

declare(strict_types=1);

namespace Biblio\UI;

final class LibraryAppShortcode
{
    public const TAG = "biblio_library_app";

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
    public function render(
        array|string $attributes = [],
        ?string $content = null,
        string $shortcodeTag = self::TAG
    ): string {
        return '<div data-biblio-ui-root></div>';
    }
}
