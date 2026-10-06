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

function allByClass(root, className) {
    return descendants(root).filter((node) => node.className.split(" ").includes(className));
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
        searchUrl: "https://example.test/zoeken/",
        wishlistUrl: "https://example.test/verlanglijst/",
        nextReadingUrl: "https://example.test/hierna-lezen/",
        activeDestination: "wishlist",
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
    const links = allByClass(shell, "biblio-ui__nav-link");
    assert.deepEqual(links.map((link) => link.getAttribute("title")), [
        "Mijn Bibliotheek",
        "Zoeken",
        "Verlanglijst",
        "Hierna lezen",
    ]);
    assert.deepEqual(links.map((link) => link.getAttribute("aria-current")), [
        null,
        null,
        "page",
        null,
    ]);

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

test("guest account action uses the supplied login route in the bottom rail and mobile navigation", () => {
    const mount = new FakeElement("div");
    createLibraryShell(mount, {
        documentImpl,
        eventTarget: null,
        overviewUrl: "/mijn-bibliotheek/",
        loginUrl: "/wp-login.php?redirect_to=mijn-bibliotheek",
        accountState: "guest",
        preferences: { sidebarCollapsed: () => true },
    });
    const shell = mount.children[0];
    const account = byClass(shell, "biblio-ui__sidebar-account");
    const action = byClass(account, "biblio-ui__account-action");
    assert.equal(account.tagName, "NAV");
    assert.equal(account.getAttribute("aria-label"), "Account");
    assert.equal(action.getAttribute("href"), "/wp-login.php?redirect_to=mijn-bibliotheek");
    assert.equal(action.getAttribute("aria-label"), "Inloggen");
    assert.equal(action.getAttribute("title"), "Inloggen");
    assert.equal(byClass(account, "biblio-ui__sidebar-context"), undefined);
    assert.equal(shell.getAttribute("data-sidebar-collapsed"), "true");
    byClass(shell, "biblio-ui__menu-toggle").click();
    assert.equal(shell.getAttribute("data-mobile-nav-open"), "true");
    assert.equal(action.getAttribute("href"), "/wp-login.php?redirect_to=mijn-bibliotheek");
});

test("signed-in account displays its identity and only the supplied logout action", () => {
    const mount = new FakeElement("div");
    createLibraryShell(mount, {
        documentImpl,
        eventTarget: null,
        overviewUrl: "/mijn-bibliotheek/",
        loginUrl: "/wp-login.php",
        accountState: "authenticated",
        accountName: 'Renée <Admin>',
        logoutUrl: "/wp-login.php?action=logout&_wpnonce=signed",
        preferences: { sidebarCollapsed: () => false },
    });
    const account = byClass(mount.children[0], "biblio-ui__sidebar-account");
    const identity = byClass(account, "biblio-ui__sidebar-context");
    const action = byClass(account, "biblio-ui__account-action");
    assert.equal(byClass(identity, "biblio-ui__nav-label").textContent, 'Renée <Admin>');
    assert.equal(identity.getAttribute("title"), 'Renée <Admin>');
    assert.equal(action.getAttribute("href"), "/wp-login.php?action=logout&_wpnonce=signed");
    assert.equal(action.getAttribute("aria-label"), "Uitloggen");
    assert.equal(action.getAttribute("title"), "Uitloggen");
    assert.equal(allByClass(account, "biblio-ui__account-action").length, 1);
});

test("platform navigation adds exact contextual Home and Catalogus only after Library authorization", () => {
    const mount = new FakeElement("div");
    const shell = createLibraryShell(mount, {
        documentImpl,
        eventTarget: null,
        platformUrl: "https://example.test/mijn-biblio/",
        libraryHomeUrl: "https://example.test/bibliotheek-home/",
        overviewUrl: "https://example.test/mijn-bibliotheek/",
        wishlistUrl: "https://example.test/verlanglijst/",
        nextReadingUrl: "https://example.test/hierna-lezen/",
        activeDestination: "library",
        preferences: { sidebarCollapsed: () => false },
    });
    const navigation = byClass(mount.children[0], "biblio-ui__nav");
    assert.deepEqual(allByClass(navigation, "biblio-ui__nav-link").map((item) => item.getAttribute("title")), [
        "Mijn Biblio", "Verlanglijst", "Hierna lezen",
    ]);
    shell.setLibraryContext({ library_id: "library/2", name: "Leesclub" });
    const contextual = allByClass(navigation, "biblio-ui__nav-link");
    assert.deepEqual(contextual.slice(1, 3).map((item) => item.getAttribute("title")), [
        "Home", "Catalogus",
    ]);
    assert.equal(contextual[2].getAttribute("href"), "https://example.test/mijn-bibliotheek/?library_id=library%2F2");
    assert.equal(contextual[1].getAttribute("href"), "https://example.test/bibliotheek-home/?library_id=library%2F2");
    assert.deepEqual(allByClass(navigation, "biblio-ui__nav-section").map((item) => item.textContent), ["Leesclub", "Persoonlijk"]);
    shell.setLibraryContext(null);
    assert.equal(allByClass(navigation, "biblio-ui__nav-link").length, 3);
});


test("Settings is an exact authorized Library account link, absent from Mijn Biblio and guests", () => {
    for (const accountState of ["authenticated","guest"]) {
        const mount = new FakeElement("div");
        const shell = createLibraryShell(mount,{documentImpl,eventTarget:null,accountState,platformUrl:"https://example.test/mijn-biblio/",libraryHomeUrl:"https://example.test/bibliotheek-home/",overviewUrl:"https://example.test/mijn-bibliotheek/",settingsUrl:"https://example.test/instellingen/",activeDestination:"settings"});
        const settings = () => descendants(mount).find(node=>node.getAttribute("title")==="Mijn voorkeuren");
        assert.equal(settings(),undefined);
        shell.setLibraryContext({library_id:"exact/a",name:"Eigen naam"});
        if (accountState === "authenticated") {
            assert.equal(new URL(settings().getAttribute("href")).searchParams.get("library_id"),"exact/a");
            assert.equal(settings().getAttribute("aria-current"),"page");
        } else assert.equal(settings(),undefined);
        shell.setLibraryContext(null); assert.equal(settings(),undefined);
    }
});

test("Library settings is a separate management destination based only on its explicit capability", () => {
    for (const capability of [true, false, undefined, "true"]) {
        const mount = new FakeElement("div");
        const shell = createLibraryShell(mount, {documentImpl,eventTarget:null,
            accountState:"authenticated",platformUrl:"https://example.test/mijn-biblio/",
            libraryHomeUrl:"https://example.test/bibliotheek-home/",overviewUrl:"https://example.test/mijn-bibliotheek/",
            settingsUrl:"https://example.test/instellingen/",librarySettingsUrl:"https://example.test/bibliotheekinstellingen/",
            activeDestination:"library-settings"});
        const management = () => descendants(mount).find(node=>node.getAttribute("title")==="Bibliotheekinstellingen");
        assert.equal(management(),undefined);
        shell.setLibraryContext({library_id:"exact/a",name:"Eigen naam",capabilities:{manage_defaults:capability,modify_catalog_context:true}});
        if (capability === true) {
            assert.equal(new URL(management().getAttribute("href")).pathname,"/bibliotheekinstellingen/");
            assert.equal(new URL(management().getAttribute("href")).searchParams.get("library_id"),"exact/a");
            assert.equal(management().getAttribute("aria-current"),"page");
            assert.equal(management().getAttribute("aria-label"),"Bibliotheekinstellingen voor Eigen naam");
        } else assert.equal(management(),undefined);
        shell.setLibraryContext(null); assert.equal(management(),undefined);
    }
});
