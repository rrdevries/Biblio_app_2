import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

const css = await readFile(
    new URL("../../assets/css/app.css", import.meta.url),
    "utf8"
);

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
    assert.doesNotMatch(css, /position:\s*(?:fixed|sticky)/);
});
