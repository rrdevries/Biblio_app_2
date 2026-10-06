<?php

declare(strict_types=1);

namespace Biblio\UI;

final class SettingsAppShortcode
{
    public const TAG = 'biblio_settings';
    public const PAGE_SLUG = 'instellingen';
    public const MANAGEMENT_TAG = 'biblio_library_settings';
    public const MANAGEMENT_SLUG = 'bibliotheekinstellingen';

    public function register(): void
    {
        add_shortcode(self::TAG, [$this, 'render']);
        add_shortcode(self::MANAGEMENT_TAG, [$this, 'renderManagement']);
    }

    public function render(): string
    {
        return $this->mount(self::PAGE_SLUG, 'preferences');
    }

    public function renderManagement(): string
    {
        return $this->mount(self::MANAGEMENT_SLUG, 'defaults');
    }

    private function mount(string $slug, string $mode): string
    {
        // Reuse the same account and navigation configuration, including its
        // scoped login return route. The client requires an explicit Library ID.
        $mount = (new EntryAppShortcode())->renderLibrary();
        $returnUrl = home_url('/' . $slug . '/');
        $id = $_GET['library_id'] ?? null;
        if (is_string($id) && $id !== '' && strlen($id) <= 191 && !preg_match('/[\x00-\x1F\x7F]/', $id)) {
            $returnUrl .= '?library_id=' . rawurlencode($id);
        }
        $mount = preg_replace('/data-login-url="[^"]*"/', 'data-login-url="' . esc_url(wp_login_url($returnUrl)) . '"', $mount);
        return str_replace('data-biblio-entry-root', 'data-biblio-settings-root data-settings-mode="' . esc_attr($mode) . '"', $mount ?? '');
    }
}
