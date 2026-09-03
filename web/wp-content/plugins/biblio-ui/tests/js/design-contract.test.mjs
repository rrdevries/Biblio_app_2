import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const css = await readFile(
    new URL("../../assets/css/app.css", import.meta.url),
    "utf8"
);

function relativeLuminance(hex) {
    const channels = hex.match(/[0-9a-f]{2}/giu).map((channel) => {
        const value = Number.parseInt(channel, 16) / 255;

        return value <= 0.04045
            ? value / 12.92
            : ((value + 0.055) / 1.055) ** 2.4;
    });

    return (0.2126 * channels[0])
        + (0.7152 * channels[1])
        + (0.0722 * channels[2]);
}

function contrast(foreground, background) {
    const light = Math.max(
        relativeLuminance(foreground),
        relativeLuminance(background)
    );
    const dark = Math.min(
        relativeLuminance(foreground),
        relativeLuminance(background)
    );

    return (light + 0.05) / (dark + 0.05);
}

test("Deep Library exposes the canonical spacing and semantic token architecture", () => {
    for (const contract of [
        "--biblio-space-1: 0.25rem",
        "--biblio-space-2: 0.5rem",
        "--biblio-space-3: 0.75rem",
        "--biblio-space-4: 1rem",
        "--biblio-space-6: 1.5rem",
        "--biblio-space-8: 2rem",
        "--biblio-space-12: 3rem",
        "--biblio-space-16: 4rem",
        "--biblio-content-max: 90rem",
        "--biblio-reading-max: 48rem",
        "--biblio-dialog-max: 32rem",
        "--biblio-control-min: 44px",
        "--biblio-radius-compact: 0.25rem",
        "--biblio-radius-control: 0.375rem",
        "--biblio-radius-overlay: 0.5rem",
        "--biblio-radius-elevated: 0.625rem",
        "--biblio-boundary-width: 1px",
        "--biblio-focus-width: 2px",
    ]) {
        assert.match(css, new RegExp(contract.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")));
    }

    for (const role of [
        "page",
        "surface",
        "surface-elevated",
        "navigation",
        "navigation-hover",
        "navigation-active",
        "text-primary",
        "text-secondary",
        "text-muted",
        "interactive",
        "interactive-hover",
        "interactive-subtle",
        "border",
        "border-strong",
        "focus",
        "brass",
        "brass-subtle",
        "status-success",
        "status-warning",
        "status-danger",
        "book-atmosphere",
    ]) {
        assert.match(css, new RegExp(`--biblio-color-${role}:`));
    }

    assert.match(css, /--biblio-font-serif: "Cormorant Garamond"/);
    assert.match(css, /--biblio-font-sans: "Source Sans 3"/);
    assert.match(css, /font-family: var\(--biblio-font-serif\)/);
    assert.match(css, /inline-size: 100vw/);
    assert.match(css, /margin-inline: calc\(50% - 50vw\)/);
    assert.match(css, /:focus-visible/);
    assert.doesNotMatch(css, /\.elementor(?:-|\s|\{|\.)/);
});

test("shell, views and Quick View recompose across the three breakpoint families", () => {
    assert.match(css, /@media \(min-width: 768px\) and \(max-width: 1023px\)/);
    assert.match(css, /@media \(max-width: 767px\)/);
    assert.match(css, /\.biblio-ui__shell \{[\s\S]*grid-template-columns: 14rem minmax\(0, 1fr\)/);
    assert.match(css, /data-sidebar-collapsed="true"[\s\S]*grid-template-columns: 4\.5rem/);
    assert.match(css, /data-catalog-view="grid"[\s\S]*repeat\(auto-fill, minmax\(min\(100%, 9\.25rem\), 1fr\)\)/);
    assert.match(css, /data-catalog-view="list"/);
    assert.match(css, /\.biblio-ui__bookshelf-placeholder/);
    assert.match(css, /\.biblio-ui__quick-view[\s\S]*position: fixed|\.biblio-ui__sidebar[\s\S]*position: fixed/);
    assert.match(css, /\.biblio-ui__quick-view[\s\S]*min-block-size: calc\(100dvb - var\(--biblio-space-8\)\)/);
    assert.match(css, /data-mobile-nav-open="true"/);
    assert.match(css, /\.biblio-ui__load-more,[\s\S]*inline-size: 100%/);
    assert.match(css, /\.biblio-ui__detail-layout[\s\S]*grid-template-columns/);
    assert.match(css, /\.biblio-ui__reading-dialog[\s\S]*margin: auto 0 0/);
    assert.match(css, /\.biblio-ui__reading-dialog[\s\S]*32rem/);
    assert.match(css, /\.biblio-ui__radio-option[\s\S]*44px/);
    assert.match(css, /\.biblio-ui__history-list[\s\S]*display: grid/);
    assert.match(css, /\.biblio-ui__history-entry[\s\S]*min-inline-size: 0/);
    assert.match(css, /\.biblio-ui__history-load-more,[\s\S]*inline-size: 100%/);
    assert.doesNotMatch(css, /\.biblio-ui__history[^\{]*\{[^}]*overflow-x:/);

    const librarySlice = css.slice(
        css.indexOf("/* Mijn Bibliotheek"),
        css.indexOf("[data-biblio-next-reading-root]")
    );
    assert.doesNotMatch(librarySlice, /#[0-9a-f]{3,8}/iu);
});

test("Reading history CSS preserves reflow, native lists and scoped token use", () => {
    const historyRules = [...css.matchAll(
        /([^{}]*\.biblio-ui__history[^{}]*)\{([^{}]*)\}/gu
    )];

    assert.ok(historyRules.length >= 8);

    for (const [, selector, declarations] of historyRules) {
        assert.match(selector, /\[data-biblio-ui-root\]/);
        assert.doesNotMatch(declarations, /#[0-9a-f]{3,8}/iu);
    }

    assert.match(
        css,
        /\.biblio-ui__history-list[\s\S]*padding-inline-start: var\(--biblio-space-6\)/
    );
    assert.doesNotMatch(
        css,
        /\.biblio-ui__history-list[^\{]*\{[^}]*list-style:\s*none/
    );
    assert.match(
        css,
        /\.biblio-ui__history-entry[^\{]*\{[^}]*display: list-item/
    );
    assert.match(
        css,
        /\.biblio-ui__history-region[\s\S]*max-inline-size: 100%[\s\S]*min-inline-size: 0/
    );
    assert.match(
        css,
        /:where\(\.biblio-ui__history-load-more, \.biblio-ui__history-error \.biblio-ui__control\)[\s\S]*min-inline-size: var\(--biblio-control-min\)/
    );
    assert.match(
        css,
        /@media \(max-width: 767px\)[\s\S]*\.biblio-ui__history-error \.biblio-ui__control,[\s\S]*inline-size: 100%/
    );

    const historyCss = css.slice(
        css.indexOf("[data-biblio-ui-root] .biblio-ui__history-region"),
        css.indexOf(".biblio-ui__reading-dialog")
    );
    assert.doesNotMatch(historyCss, /white-space:\s*nowrap|text-overflow|overflow:\s*hidden/);
    assert.doesNotMatch(historyCss, /(?:min-|max-)?block-size:\s*\d/);
    assert.doesNotMatch(historyCss, /position:\s*absolute|animation:|transition:/);
});

test("Private Notes CSS keeps native list semantics, wrapping editor controls and mobile dialogs", () => {
    const privateRules = [...css.matchAll(
        /([^{}]*\.biblio-ui__(?:private-notes|note|editor|format|dialog|reading-dialog)[^{}]*)\{([^{}]*)\}/gu
    )];

    assert.ok(privateRules.length >= 18);

    for (const [, selector] of privateRules) {
        assert.match(selector, /\[data-biblio-ui-root\]/);
    }

    assert.match(css, /\.biblio-ui__note-list[\s\S]*display: grid/);
    assert.match(css, /\.biblio-ui__note-list[\s\S]*list-style: none/);
    assert.match(css, /\.biblio-ui__note-card[\s\S]*min-inline-size: 0/);
    assert.match(css, /\.biblio-ui__editor-toolbar[\s\S]*flex-wrap: wrap/);
    assert.match(
        css,
        /\.biblio-ui__note-body,\s*\n\[data-biblio-ui-root\] \.biblio-ui__editor-surface \{[^}]*inline-size: 100%[^}]*overflow-wrap: anywhere[^}]*word-break: break-word/
    );
    assert.match(css, /\.biblio-ui__format-control[\s\S]*--biblio-control-min/);
    assert.match(
        css,
        /@media \(max-width: 767px\)[\s\S]*\.biblio-ui__note-actions \.biblio-ui__control[\s\S]*flex: 1 1 10rem/
    );
    assert.match(
        css,
        /@media \(max-width: 767px\)[\s\S]*\.biblio-ui__private-notes > \.biblio-ui__control,[\s\S]*inline-size: 100%/
    );
    assert.match(
        css,
        /\.biblio-ui__reading-dialog \{[^}]*max-block-size: calc\(100dvb - 2rem\)[^}]*overflow: auto/
    );
    assert.match(
        css,
        /@media \(max-width: 767px\)[\s\S]*padding-block-end: max\([^;]*env\(safe-area-inset-bottom\)\)/
    );
    assert.doesNotMatch(css, /\.biblio-ui__(?:note|editor)[^\{]*\{[^}]*overflow-x:/);
    assert.doesNotMatch(
        css,
        /\.biblio-ui__(?:private-notes|note|editor)[^\{]*\{[^}]*(?:white-space:\s*nowrap|text-overflow|overflow:\s*hidden)/
    );
    assert.deepEqual(
        css.split("\n").filter((line) => /^\s*\.biblio-ui__/u.test(line)),
        []
    );
});

test("Ink Light text, navigation and focus work values retain readable contrast", () => {
    assert.ok(contrast("22252b", "f7f4ed") >= 4.5);
    assert.ok(contrast("686e78", "f7f4ed") >= 4.5);
    assert.ok(contrast("ffffff", "172238") >= 4.5);
    assert.ok(contrast("075f9e", "f7f4ed") >= 3);
    assert.ok(contrast("9b1c1c", "ffffff") >= 4.5);
});
