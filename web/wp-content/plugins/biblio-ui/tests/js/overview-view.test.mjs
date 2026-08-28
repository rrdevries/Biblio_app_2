import assert from "node:assert/strict";
import test from "node:test";

import { createOverviewView } from "../../assets/js/overview-view.js";

class FakeElement {
    constructor(tagName) {
        this.tagName = tagName.toUpperCase();
        this.attributes = new Map();
        this.children = [];
        this.className = "";
        this.textContent = "";
        this.disabled = false;
        this.listeners = new Map();
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }

    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    }

    append(...children) {
        this.children.push(...children);
    }

    replaceChildren(...children) {
        this.children = [...children];
    }

    addEventListener(type, listener) {
        this.listeners.set(type, listener);
    }

    click() {
        return this.listeners.get("click")?.();
    }
}

const documentImpl = {
    createElement(tagName) {
        return new FakeElement(tagName);
    },
};

function descendants(root, predicate) {
    const matches = [];

    for (const child of root.children) {
        if (predicate(child)) {
            matches.push(child);
        }

        matches.push(...descendants(child, predicate));
    }

    return matches;
}

function byTag(root, tagName) {
    return descendants(root, (node) => node.tagName === tagName.toUpperCase());
}

function text(root) {
    return [
        root.textContent,
        ...root.children.map(text),
    ].filter(Boolean).join(" ");
}

function library(id = "library-1") {
    return {
        library_id: id,
        name: "Mijn testbibliotheek",
        capabilities: {
            use_item_directly: true,
            receive_internal_loan: false,
        },
    };
}

function item(id, overrides = {}) {
    return {
        item_id: id,
        work_id: `work-${id}`,
        edition_id: `edition-${id}`,
        title: `Titel ${id}`,
        authors: { state: "unknown", values: [] },
        cover_reference: { state: "unknown", value: null },
        form: { state: "known", value: "physical_book" },
        location_or_source: { state: "unknown", value: null },
        reading_status: "not_read",
        item_status: "active",
        capabilities: { view_item: true, start_reading: true },
        ...overrides,
    };
}

function overviewModel(overrides = {}) {
    return {
        state: "overview",
        library: library(),
        items: [item("one")],
        nextCursor: null,
        loadingMore: false,
        loadMoreError: false,
        canRetryCursor: false,
        ...overrides,
    };
}

function setup() {
    const root = new FakeElement("div");
    const itemUrls = [];
    const view = createOverviewView(root, {
        documentImpl,
        overviewUrl: "https://example.test/mijn-bibliotheek/",
        itemUrl(libraryId, itemId) {
            itemUrls.push([libraryId, itemId]);
            return "https://example.test/mijn-bibliotheek/"
                + `?library_id=${libraryId}&item_id=${itemId}`;
        },
    });

    return { root, view, itemUrls };
}

test("overview rendering uses only allowlisted known Item presentation", () => {
    const { root, view, itemUrls } = setup();
    const first = item("one", {
        title: "De bekende titel",
        authors: { state: "known", values: ["Auteur A", "Auteur B"] },
        cover_reference: {
            state: "known",
            value: "https://images.example.test/cover.jpg",
        },
        location_or_source: { state: "known", value: "Kast B" },
        reading_status: "reading",
        internal_secret: "never render this",
    });
    const second = item("two", {
        title: "Zonder metadata",
        form: { state: "unknown", value: null },
        reading_status: "read",
        capabilities: { view_item: false, start_reading: false },
    });

    view.render(overviewModel({ items: [first, second] }));

    assert.equal(root.children[0].getAttribute("data-biblio-view"), "overview");
    assert.equal(byTag(root, "h1").length, 1);
    assert.equal(byTag(root, "ul").length, 1);
    assert.equal(byTag(root, "li").length, 2);
    assert.equal(byTag(root, "img").length, 1);
    assert.equal(
        byTag(root, "img")[0].getAttribute("alt"),
        "Omslag van De bekende titel"
    );
    assert.equal(byTag(root, "a").length, 1);
    assert.equal(
        byTag(root, "a")[0].getAttribute("href"),
        "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-1&item_id=one"
    );
    assert.deepEqual(itemUrls, [["library-1", "one"]]);
    assert.match(text(root), /Auteur A, Auteur B/);
    assert.match(text(root), /Boek · Kast B · Aan het lezen/);
    assert.match(text(root), /Zonder metadata Uitgelezen/);
    assert.doesNotMatch(text(root), /Onbekend|never render|work-one|edition-one/);
});

test("all Library bootstrap, chooser, unavailable and request states render safely", () => {
    const { root, view } = setup();
    let selected = null;
    let retries = 0;

    for (const [model, heading] of [
        [{ state: "library-loading" }, "Bibliotheek laden"],
        [
            { state: "overview-loading", library: library() },
            "Boeken laden",
        ],
        [{ state: "zero-libraries" }, "Geen bibliotheek beschikbaar"],
        [
            { state: "library-unavailable" },
            "Bibliotheek niet beschikbaar",
        ],
    ]) {
        view.render(model);
        assert.match(text(root), new RegExp(heading));
    }

    view.render(
        {
            state: "library-chooser",
            libraries: [
                library("direct"),
                {
                    ...library("loan"),
                    name: "Leenbibliotheek",
                    capabilities: {
                        use_item_directly: false,
                        receive_internal_loan: true,
                    },
                },
                {
                    ...library("view"),
                    name: "Kijkbibliotheek",
                    capabilities: {
                        use_item_directly: false,
                        receive_internal_loan: false,
                    },
                },
            ],
        },
        { selectLibrary(id) { selected = id; } }
    );
    assert.match(text(root), /Directe toegang/);
    assert.match(text(root), /Lenen/);
    assert.match(text(root), /Alleen bekijken/);
    byTag(root, "button")[1].click();
    assert.equal(selected, "loan");

    view.render(
        { state: "request-error" },
        { retry() { retries += 1; } }
    );
    assert.match(text(root), /Bibliotheek kon niet worden geladen/);
    byTag(root, "button")[0].click();
    assert.equal(retries, 1);

    view.render({ state: "library-unavailable" });
    assert.equal(
        byTag(root, "a")[0].getAttribute("href"),
        "https://example.test/mijn-bibliotheek/"
    );
});

test("empty overview and cursor controls follow the exact component states", () => {
    const { root, view } = setup();
    let loads = 0;
    let retries = 0;
    let restarts = 0;

    view.render(overviewModel({ items: [] }));
    assert.match(text(root), /Nog geen actieve boeken/);
    assert.equal(byTag(root, "ul").length, 0);

    view.render(
        overviewModel({ nextCursor: "cursor", loadingMore: true }),
        { loadMore() { loads += 1; } }
    );
    assert.equal(byTag(root, "button")[0].disabled, true);
    assert.equal(byTag(root, "button")[0].getAttribute("aria-busy"), "true");

    view.render(
        overviewModel({
            nextCursor: "cursor",
            loadMoreError: true,
            canRetryCursor: true,
        }),
        {
            retryLoadMore() { retries += 1; },
            restart() { restarts += 1; },
        }
    );
    assert.match(text(root), /Meer boeken konden niet worden geladen/);
    assert.equal(byTag(root, "button").length, 2);
    byTag(root, "button")[0].click();
    byTag(root, "button")[1].click();
    assert.equal(retries, 1);
    assert.equal(restarts, 1);

    view.render(
        overviewModel({
            nextCursor: "cursor",
            loadMoreError: true,
            canRetryCursor: false,
        }),
        { restart() { restarts += 1; } }
    );
    assert.equal(byTag(root, "button").length, 1);
});
