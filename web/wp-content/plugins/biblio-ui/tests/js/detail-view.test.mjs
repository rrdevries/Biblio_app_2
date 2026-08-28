import assert from "node:assert/strict";
import test from "node:test";

import { createDetailView } from "../../assets/js/detail-view.js";

class FakeElement {
    constructor(tagName) {
        this.tagName = tagName.toUpperCase();
        this.attributes = new Map();
        this.children = [];
        this.className = "";
        this.textContent = "";
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

    click(event) {
        return this.listeners.get("click")?.(event);
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

function known(value) {
    return { state: "known", value };
}

function unknown() {
    return { state: "unknown", value: null };
}

function detail(overrides = {}) {
    return {
        library: {
            library_id: "library-1",
            name: "Mijn testbibliotheek",
        },
        item_id: "item-1",
        work_id: "work-internal",
        edition_id: "edition-internal",
        title: "Het bekende boek",
        authors: { state: "known", values: ["Auteur A", "Auteur B"] },
        cover_reference: known("https://images.example.test/cover.jpg"),
        isbn: known("9780000000001"),
        language: known("Nederlands"),
        publisher: known("Uitgeverij Test"),
        publication_date: known("2026"),
        series: known("Reeks 1"),
        form: known("physical_book"),
        location: known("Kast B"),
        condition: known("Goed"),
        acquisition: known("Aankoop"),
        availability: known("Beschikbaar"),
        item_status: "active",
        reading: {
            status: "reading",
            active_rounds: 1,
            completed_rounds: 3,
            stopped_rounds: 2,
            historical_completed_rounds: 1,
        },
        capabilities: { view_item: true, start_reading: false },
        internal_secret: "never render this",
        ...overrides,
    };
}

function setup() {
    const root = new FakeElement("div");
    const view = createDetailView(root, { documentImpl });

    return { root, view };
}

test("detail renders the allowlisted known contract and canonical back link", () => {
    const { root, view } = setup();
    let backCalls = 0;
    const backUrl = "https://example.test/mijn-bibliotheek/"
        + "?library_id=library-1";

    view.render(
        { state: "detail", detail: detail(), backUrl },
        { backToOverview() { backCalls += 1; } }
    );

    assert.equal(root.children[0].getAttribute("data-biblio-view"), "detail");
    assert.equal(byTag(root, "h1").length, 1);
    assert.equal(byTag(root, "h1")[0].textContent, "Het bekende boek");
    assert.deepEqual(byTag(root, "h2").map((node) => node.textContent), [
        "Lezen",
        "Uitgave",
        "Exemplaar",
    ]);
    assert.equal(byTag(root, "a")[0].getAttribute("href"), backUrl);
    assert.equal(byTag(root, "img")[0].getAttribute("src"),
        "https://images.example.test/cover.jpg");
    assert.equal(byTag(root, "img")[0].getAttribute("alt"),
        "Omslag van Het bekende boek");
    assert.match(text(root), /Mijn Bibliotheek/);
    assert.match(text(root), /Auteur A, Auteur B/);
    assert.match(text(root), /Mijn testbibliotheek/);
    assert.match(text(root), /Vorm Boek/);
    assert.match(text(root), /Leesstatus Aan het lezen/);
    assert.match(text(root), /Actieve leesrondes 1/);
    assert.match(text(root), /Uitgelezen leesrondes 3/);
    assert.match(text(root), /Gestopte leesrondes 2/);
    assert.match(text(root), /Waarvan historisch geregistreerd 1/);
    assert.match(text(root), /ISBN 9780000000001/);
    assert.match(text(root), /Locatie Kast B/);
    assert.doesNotMatch(text(root),
        /work-internal|edition-internal|never render this/);

    const clickEvent = {
        button: 0,
        defaultPrevented: false,
        preventDefault() {
            this.defaultPrevented = true;
        },
    };
    byTag(root, "a")[0].click(clickEvent);
    assert.equal(clickEvent.defaultPrevented, true);
    assert.equal(backCalls, 1);

    byTag(root, "a")[0].click({
        button: 0,
        metaKey: true,
        defaultPrevented: false,
        preventDefault() {
            throw new Error("Modified link clicks must keep browser behavior.");
        },
    });
    assert.equal(backCalls, 1);
});

test("unknown, missing and not-applicable values omit labels and sections", () => {
    const { root, view } = setup();
    const omittedDetail = detail({
        authors: { state: "missing", values: [] },
        cover_reference: unknown(),
        isbn: unknown(),
        language: { state: "missing", value: null },
        publisher: { state: "not_applicable", value: null },
        publication_date: unknown(),
        series: unknown(),
        form: unknown(),
        location: unknown(),
        condition: { state: "missing", value: null },
        acquisition: { state: "not_applicable", value: null },
        availability: unknown(),
        reading: {
            status: "not_read",
            active_rounds: 0,
            completed_rounds: 0,
            stopped_rounds: 0,
            historical_completed_rounds: 0,
        },
    });

    view.render({
        state: "detail",
        detail: omittedDetail,
        backUrl: "https://example.test/mijn-bibliotheek/?library_id=library-1",
    });

    assert.deepEqual(
        byTag(root, "h2").map((node) => node.textContent),
        ["Lezen"]
    );
    assert.equal(byTag(root, "img").length, 0);
    assert.match(text(root), /Leesstatus Niet gelezen/);
    assert.doesNotMatch(text(root),
        /Auteur|Vorm|Actieve leesrondes|Uitgelezen leesrondes|Gestopte leesrondes/);
    assert.doesNotMatch(text(root),
        /Waarvan historisch|ISBN|Taal|Uitgever|Publicatiedatum|Serie/);
    assert.doesNotMatch(text(root),
        /Locatie|Conditie|Verwerving|Beschikbaarheid|undefined|null|Onbekend/);
});

test("detail loading and Item-unavailable states expose no server details", () => {
    const { root, view } = setup();
    let backCalls = 0;

    view.render({ state: "detail-loading" });
    assert.equal(
        root.children[0].getAttribute("data-biblio-view"),
        "detail-loading"
    );
    assert.match(text(root), /Boek laden/);

    view.render(
        {
            state: "item-unavailable",
            backUrl: "https://example.test/mijn-bibliotheek/"
                + "?library_id=library-1",
            message: "Foreign title and database details",
        },
        { backToOverview() { backCalls += 1; } }
    );

    assert.equal(
        root.children[0].getAttribute("data-biblio-view"),
        "item-unavailable"
    );
    assert.match(text(root), /Boek niet beschikbaar/);
    assert.doesNotMatch(text(root), /Foreign title|database details/);
    byTag(root, "a")[0].click();
    assert.equal(backCalls, 1);
});
