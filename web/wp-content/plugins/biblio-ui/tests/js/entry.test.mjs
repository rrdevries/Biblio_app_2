import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

let source = await readFile(new URL("../../assets/js/entry.js", import.meta.url), "utf8");
source = source.replace('"biblio-ui/api"', JSON.stringify(new URL("../../assets/js/api.js", import.meta.url).href));
source = source.replace('"biblio-ui/ui-shell"', JSON.stringify(new URL("../../assets/js/ui-shell.js", import.meta.url).href));
const { createEntryApp, entryLibraryUrl, readEntryLibraries, selectEntryLibrary } = await import(
    `data:text/javascript;base64,${Buffer.from(source).toString("base64")}`
);

class FakeElement {
    constructor(tag) {
        this.tagName = tag.toUpperCase();
        this.children = [];
        this.attributes = new Map();
        this.textContent = "";
        this.className = "";
    }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = [...children]; }
    setAttribute(key, value) { this.attributes.set(key, value); }
    getAttribute(key) { return this.attributes.get(key); }
}

const documentImpl = { createElement: (tag) => new FakeElement(tag) };
const nodes = (parent) => parent.children.flatMap((child) => [child, ...nodes(child)]);
const links = (parent) => nodes(parent).filter((node) => node.tagName === "A");
const labels = (parent) => nodes(parent).map((node) => node.textContent).filter(Boolean);
const library = (id, name = id, canAdd = false) => ({
    library_id: id, name, status: "active",
    capabilities: { view_collection: true, add_catalog_item: canAdd },
});
const dataset = (mode = "personal") => ({
    entryMode: mode,
    accountState: "authenticated",
    platformUrl: "https://example.test/mijn-biblio/",
    libraryHomeUrl: "https://example.test/bibliotheek-home/",
    overviewUrl: "https://example.test/mijn-bibliotheek/",
    searchUrl: "https://example.test/zoeken/",
    wishlistUrl: "https://example.test/verlanglijst/",
    nextReadingUrl: "https://example.test/hierna-lezen/",
    loginUrl: "https://example.test/wp-login.php",
});

function harness(mode, libraries, url) {
    const root = new FakeElement("div");
    root.dataset = dataset(mode);
    const host = new FakeElement("main");
    const contexts = [];
    const app = createEntryApp(root, {
        api: { get: async (path) => {
            if (path === "me/libraries") return { libraries };
            const id = decodeURIComponent(path.split("/")[1]);
            assert.equal(path, `libraries/${encodeURIComponent(id)}/items?page_size=1`);
            return { library: libraries.find((item) => item.library_id === id), items: [] };
        } },
        documentImpl,
        locationImpl: { href: url },
        shellFactory: () => ({ contentRoot: host, setLibraryContext: (value) => contexts.push(value), destroy() {} }),
    });
    return { root, host, contexts, app };
}

test("personal entry lists zero, one and several authorized Libraries without selecting one", async () => {
    for (const available of [[], [library("own", "Eigen kast")], [library("own", "Eigen kast"), library("member", "Leesclub")]]) {
        const { host, contexts, app } = harness("personal", available, "https://example.test/mijn-biblio/");
        await app.load();
        assert.deepEqual(contexts, []);
        const homeLinks = links(host).filter((node) => node.getAttribute("href")?.includes("bibliotheek-home"));
        assert.equal(homeLinks.length, available.length);
        assert.deepEqual(homeLinks.map((node) => node.textContent), available.map((item) => item.name));
        assert.ok(labels(host).includes("Mijn Biblio"));
    }
});

test("Library Home opens only the exact authorized ID and carries it to Catalogus", async () => {
    const available = [library("own", "Eigen kast"), library("member/2", "Leesclub", true)];
    const { host, contexts, app } = harness(
        "library", available, "https://example.test/bibliotheek-home/?library_id=member%2F2"
    );
    await app.load();
    assert.equal(contexts[0], available[1]);
    assert.ok(labels(host).includes("Leesclub"));
    assert.ok(labels(host).some((label) => label.includes("nog geen boeken")));
    assert.ok(links(host).some((node) => node.getAttribute("href") === "https://example.test/mijn-bibliotheek/?library_id=member%2F2"));
    assert.ok(labels(host).includes("Naar Catalogus om een boek toe te voegen"));
});

test("missing, withdrawn and malformed Library choices expose no Library context", async () => {
    for (const url of [
        "https://example.test/bibliotheek-home/",
        "https://example.test/bibliotheek-home/?library_id=revoked",
        "https://example.test/bibliotheek-home/?library_id=",
    ]) {
        const { host, contexts, app } = harness("library", [library("own")], url);
        await app.load();
        assert.deepEqual(contexts, []);
        assert.ok(labels(host).includes("Bibliotheek niet beschikbaar"));
        assert.ok(!links(host).some((node) => node.getAttribute("href")?.includes("mijn-bibliotheek")));
    }
});

test("entry contracts reject untrusted Library data and require explicit IDs", () => {
    assert.throws(() => readEntryLibraries({ libraries: [library("old", "Old", false), { library_id: "x", name: "X" }] }));
    assert.throws(() => entryLibraryUrl("https://example.test/bibliotheek-home/", ""));
    assert.throws(() => readEntryLibraries({ libraries: [library("duplicate"), library("duplicate")] }));
    assert.equal(selectEntryLibrary([library("own")], "https://example.test/bibliotheek-home/"), null);
    assert.equal(entryLibraryUrl("https://example.test/bibliotheek-home/?old=1", "x/y"), "https://example.test/bibliotheek-home/?library_id=x%2Fy");
});

test("Library Home fails closed if scoped Core read denies access after the list", async () => {
    const root = new FakeElement("div");
    root.dataset = dataset("library");
    const host = new FakeElement("main");
    const contexts = [];
    const app = createEntryApp(root, {
        api: { get: async (path) => {
            if (path === "me/libraries") return { libraries: [library("former")] };
            throw { status: 403 };
        } },
        documentImpl,
        locationImpl: { href: "https://example.test/bibliotheek-home/?library_id=former" },
        shellFactory: () => ({ contentRoot: host, setLibraryContext: (value) => contexts.push(value), destroy() {} }),
    });
    await app.load();
    assert.deepEqual(contexts, []);
    assert.ok(labels(host).includes("Bibliotheek niet beschikbaar"));
});

test("Library Home uses the name returned by the fresh scoped Core read", async () => {
    const root = new FakeElement("div");
    root.dataset = dataset("library");
    const host = new FakeElement("main");
    const contexts = [];
    const app = createEntryApp(root, {
        api: { get: async (path) => path === "me/libraries"
            ? { libraries: [library("own", "Oude naam")] }
            : { library: library("own", "Nieuwe naam"), items: [{ item_id: "book" }] } },
        documentImpl,
        locationImpl: { href: "https://example.test/bibliotheek-home/?library_id=own" },
        shellFactory: () => ({ contentRoot: host, setLibraryContext: (value) => contexts.push(value), destroy() {} }),
    });
    await app.load();
    assert.equal(contexts[0].name, "Nieuwe naam");
    assert.ok(labels(host).includes("Nieuwe naam"));
    assert.ok(!labels(host).some((label) => label.includes("nog geen boeken")));
});

test("guest entry does not request the private Library list", async () => {
    const root = new FakeElement("div");
    root.dataset = { ...dataset(), accountState: "guest" };
    const host = new FakeElement("main");
    const app = createEntryApp(root, {
        api: { get: () => { throw new Error("Private API must not be called"); } },
        documentImpl,
        shellFactory: () => ({ contentRoot: host, destroy() {} }),
    });
    await app.load();
    assert.ok(labels(host).includes("Log in om je bibliotheken te bekijken."));
    assert.equal(links(host)[0].textContent, "Inloggen");
});
