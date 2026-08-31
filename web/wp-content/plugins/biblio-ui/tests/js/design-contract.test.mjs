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

test("functional design tokens cover the complete step 10 scale", () => {
    for (const contract of [
        "--biblio-space-1: 0.25rem",
        "--biblio-space-2: 0.5rem",
        "--biblio-space-3: 0.75rem",
        "--biblio-space-4: 1rem",
        "--biblio-space-6: 1.5rem",
        "--biblio-space-8: 2rem",
        "--biblio-space-12: 3rem",
        "--biblio-space-16: 4rem",
        "--biblio-content-max: 72rem",
        "--biblio-reading-max: 48rem",
        "--biblio-dialog-max: 32rem",
        "--biblio-control-min: 44px",
        "--biblio-card-radius: 0.75rem",
        "--biblio-boundary-width: 1px",
        "--biblio-focus-width: 2px",
    ]) {
        assert.match(css, new RegExp(contract.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")));
    }

    assert.match(css, /--biblio-color-primary: #[0-9a-f]{6}/i);
    assert.match(css, /var\(--e-global-typography-primary-font-family, inherit\)/);
    assert.match(css, /:focus-visible/);
    assert.doesNotMatch(css, /\.elementor(?:-|\s|\{|\.)/);
});

test("responsive CSS expresses the three breakpoint families and component layouts", () => {
    assert.match(css, /@media \(min-width: 768px\) and \(max-width: 1023px\)/);
    assert.match(css, /@media \(max-width: 767px\)/);
    assert.match(css, /\.biblio-ui__cover--overview[\s\S]*inline-size: 6rem/);
    assert.match(css, /inline-size: 4\.5rem/);
    assert.match(css, /inline-size: 4rem/);
    assert.match(css, /\.biblio-ui__load-more,[\s\S]*inline-size: 100%/);
    assert.match(css, /\.biblio-ui__detail-layout[\s\S]*grid-template-columns/);
    assert.match(css, /\.biblio-ui__reading-dialog[\s\S]*margin: auto 0 0/);
    assert.match(css, /\.biblio-ui__reading-dialog[\s\S]*32rem/);
    assert.match(css, /\.biblio-ui__radio-option[\s\S]*44px/);
    assert.match(css, /\.biblio-ui__history-list[\s\S]*display: grid/);
    assert.match(css, /\.biblio-ui__history-entry[\s\S]*min-inline-size: 0/);
    assert.match(css, /\.biblio-ui__history-load-more,[\s\S]*inline-size: 100%/);
    assert.doesNotMatch(css, /position:\s*(?:fixed|sticky)/);
    assert.doesNotMatch(css, /\.biblio-ui__history[^\{]*\{[^}]*overflow-x:/);
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

test("existing Biblio text, control and focus colors retain readable contrast", () => {
    assert.ok(contrast("1f2933", "ffffff") >= 4.5);
    assert.ok(contrast("1f2933", "f5f7fa") >= 4.5);
    assert.ok(contrast("243b53", "ffffff") >= 4.5);
    assert.ok(contrast("005fcc", "ffffff") >= 3);
});
