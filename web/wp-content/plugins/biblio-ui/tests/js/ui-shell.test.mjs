import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

let source = await readFile(
    new URL("../../assets/js/ui-shell.js", import.meta.url),
    "utf8"
);
source = source.replace(
    '"./ui-preferences.js"',
    JSON.stringify(new URL(
        "../../assets/js/ui-preferences.js",
        import.meta.url
    ).href)
);
const { createLibraryShell } = await import(
    `data:text/javascript;base64,${Buffer.from(source).toString("base64")}`
);

class FakeElement {
    constructor(tagName) {
        this.tagName = tagName.toUpperCase();
        this.attributes = new Map();
        this.children = [];
        this.className = "";
        this.textContent = "";
        this.listeners = new Map();
        this.focused = false;
    }

    setAttribute(name, value) { this.attributes.set(name, String(value)); }
    getAttribute(name) { return this.attributes.get(name) ?? null; }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = [...children]; }
    addEventListener(type, listener) { this.listeners.set(type, listener); }
    click() { return this.listeners.get("click")?.({}); }
    focus() { this.focused = true; }
}

const documentImpl = {
    createElement(tagName) { return new FakeElement(tagName); },
};

function descendants(root) {
    return root.children.flatMap((child) => [child, ...descendants(child)]);
}

function byClass(root, className) {
    return descendants(root).find((node) => node.className.split(" ").includes(className));
}

test("shell composes Ink Light sidebar, remembered rail and mobile off-canvas", () => {
    const mount = new FakeElement("div");
    const writes = [];
    const listeners = new Map();
    const eventTarget = {
        addEventListener(type, listener) { listeners.set(type, listener); },
        removeEventListener(type, listener) {
            if (listeners.get(type) === listener) { listeners.delete(type); }
        },
    };
    const shellController = createLibraryShell(mount, {
        documentImpl,
        eventTarget,
        overviewUrl: "https://example.test/mijn-bibliotheek/",
        preferences: {
            sidebarCollapsed() { return true; },
            setSidebarCollapsed(value) { writes.push(value); },
        },
    });
    const shell = mount.children[0];

    assert.equal(shell.getAttribute("data-biblio-theme"), "ink");
    assert.equal(shell.getAttribute("data-biblio-appearance"), "light");
    assert.equal(shell.getAttribute("data-sidebar-collapsed"), "true");
    assert.equal(shellController.contentRoot.tagName, "MAIN");
    assert.equal(byClass(shell, "biblio-ui__nav-link").getAttribute("aria-current"), "page");

    byClass(shell, "biblio-ui__sidebar-toggle").click();
    assert.equal(shell.getAttribute("data-sidebar-collapsed"), "false");
    assert.deepEqual(writes, [false]);

    const menu = byClass(shell, "biblio-ui__menu-toggle");
    menu.click();
    assert.equal(shell.getAttribute("data-mobile-nav-open"), "true");
    listeners.get("keydown")({ key: "Escape" });
    assert.equal(shell.getAttribute("data-mobile-nav-open"), "false");
    assert.equal(menu.focused, true);

    shellController.destroy();
    assert.equal(listeners.has("keydown"), false);
});
