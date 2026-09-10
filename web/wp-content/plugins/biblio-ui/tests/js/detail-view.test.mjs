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
        this.focused = false;
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

    querySelector(selector) {
        return descendants(this, (node) => (
            selector === "h1" && node.tagName === "H1"
        ))[0] ?? null;
    }

    focus() {
        this.focused = true;
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

function byAttribute(root, name, value) {
    return descendants(root, (node) => node.getAttribute(name) === value);
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
        classification: {
            book_types: [{
                book_type_id: "book-type-reading",
                display_name: "Leesboek",
            }],
            genres: [{ genre_id: "genre-literary", display_name: "Literatuur" }, {
                genre_id: "genre-historical",
                display_name: "Historisch",
            }],
            subjects: [{
                subject_id: "subject-long",
                display_name: "Een lang onderwerp dat in de utilitykolom moet kunnen afbreken",
            }],
        },
        collections: [{
            collection_id: "collection-first",
            display_name: "Eerste collectie",
        }, {
            collection_id: "collection-second",
            display_name: "Een lange tweede collectienaam die rustig moet kunnen afbreken",
        }],
        assessments: {
            contributions: [{
                type: "review",
                display_name: "Lezer A",
                published_at: "2026-09-08T10:20:30.123456Z",
                rating: 4.5,
                review_html: "Een rustige &amp; lange review\nmet een tweede regel en &lt;script&gt; als tekst.",
            }, {
                type: "review",
                display_name: "Lezer A",
                published_at: "2026-08-07T09:00:00.000000Z",
                rating: null,
                review_html: "Een tweede leesronde.",
            }, {
                type: "rating",
                display_name: "Lezer B",
                published_at: "2026-07-06T08:00:00.000000Z",
                rating: 3,
            }],
            aggregate: { average: 3.8, voter_count: 2 },
            next_cursor: "opaque-cursor",
            own_not_visible: [{
                type: "rating",
                assessed_at: null,
                reading_round_linked: false,
                rating: 4,
            }, {
                type: "review",
                assessed_at: "2014-03-02T11:12:13.654321Z",
                reading_round_linked: true,
                review_html: "Mijn private &lt;review&gt;",
            }],
        },
        item_status: "active",
        reading: {
            status: "reading",
            read_date_known: null,
            active_rounds: 1,
            completed_rounds: 3,
            stopped_rounds: 2,
            historical_completed_rounds: 1,
        },
        capabilities: {
            view_item: true,
            start_reading: false,
            end_reading: false,
        },
        active_reading_round: null,
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
        "Overzicht",
        "Beoordelingen",
        "Boekdetails",
        "Uitgave",
        "Exemplaar",
        "In collecties",
    ]);
    assert.equal(
        byAttribute(root, "data-biblio-reading-history", "true").length,
        1
    );
    assert.equal(
        byAttribute(root, "data-biblio-private-notes", "true").length,
        1
    );
    assert.equal(
        byTag(root, "h2").some((node) => node.textContent === "Leesgeschiedenis"),
        false
    );
    assert.equal(byTag(root, "a")[0].getAttribute("href"), backUrl);
    assert.equal(byTag(root, "img")[0].getAttribute("src"),
        "https://images.example.test/cover.jpg");
    assert.equal(byTag(root, "img")[0].getAttribute("alt"),
        "Omslag van Het bekende boek");
    assert.equal(byAttribute(root, "role", "group")[0].getAttribute("aria-label"),
        "Leesstatus en boeksoort");
    assert.deepEqual(
        byTag(root, "nav")[0].children[0].children.map((item) => (
            item.children[0].textContent
        )),
        [
            "Overzicht",
            "Leesgeschiedenis",
            "Beoordelingen",
            "Mijn notities",
            "Boekdetails",
            "Uitgave",
            "Exemplaar",
            "In collecties",
        ]
    );
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
    assert.match(text(root), /Leesboek/);
    assert.match(text(root), /Genres Literatuur, Historisch/);
    assert.match(text(root), /Onderwerpen Een lang onderwerp/);
    assert.match(text(root), /In collecties Eerste collectie/);
    assert.match(text(root), /Een lange tweede collectienaam/);
    assert.match(text(root), /3,8 van 5 · 2 beoordelingen/);
    assert.match(text(root), /4,5 van 5/);
    assert.match(text(root), /Een rustige & lange review/);
    assert.match(text(root), /<script> als tekst/);
    assert.match(text(root), /Lezer A · 8 september 2026/);
    assert.match(text(root), /Een tweede leesronde/);
    assert.match(text(root), /Lezer B · 6 juli 2026/);
    assert.match(text(root), /Er zijn meer beoordelingen beschikbaar/);
    assert.match(text(root), /Alleen voor jou Jouw beoordelingen/);
    assert.match(text(root), /Beoordelingsdatum onbekend · Zonder leesronde/);
    assert.match(text(root), /Mijn private <review>/);
    assert.match(text(root), /2 maart 2014 · Gekoppeld aan een leesronde/);
    assert.match(text(root), /Niet zichtbaar in deze bibliotheek/);
    assert.deepEqual(
        descendants(root, (node) => (
            node.className === "biblio-ui__collection-memberships"
        ))[0].children.map((node) => node.textContent),
        [
            "Eerste collectie",
            "Een lange tweede collectienaam die rustig moet kunnen afbreken",
        ]
    );
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
        classification: {
            book_types: [],
            genres: [],
            subjects: [],
        },
        collections: [],
        assessments: {
            contributions: [],
            aggregate: { average: null, voter_count: 0 },
            next_cursor: null,
            own_not_visible: [],
        },
        reading: {
            status: "not_read",
            read_date_known: null,
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
        ["Overzicht", "Uitgave", "Exemplaar"]
    );
    assert.equal(byTag(root, "img").length, 0);
    assert.equal(
        byAttribute(
            root,
            "aria-label",
            "Geen omslag beschikbaar voor Het bekende boek"
        ).length,
        1
    );
    assert.match(text(root), /Leesstatus Niet gelezen/);
    assert.doesNotMatch(text(root),
        /Auteur|Vorm|Actieve leesrondes|Uitgelezen leesrondes|Gestopte leesrondes/);
    assert.doesNotMatch(text(root),
        /Waarvan historisch|ISBN|Taal|Uitgever|Publicatiedatum|Serie|Boekdetails/);
    assert.doesNotMatch(text(root),
        /Locatie|Conditie|Verwerving|Beschikbaarheid|undefined|null|Onbekend/);
    assert.doesNotMatch(text(root), /Genres|Onderwerpen|Leesboek/);
    assert.doesNotMatch(text(root), /In collecties/);
    assert.doesNotMatch(text(root), /Beoordelingen/);
});

test("detail distinguishes date-unknown read truth and explicit unknown", () => {
    const { root, view } = setup();

    view.render({
        state: "detail",
        detail: detail({
            reading: {
                status: "read",
                read_date_known: false,
                active_rounds: 0,
                completed_rounds: 0,
                stopped_rounds: 0,
                historical_completed_rounds: 0,
            },
        }),
        backUrl: "https://example.test/mijn-bibliotheek/?library_id=library-1",
    });
    assert.match(text(root), /Uitgelezen · datum onbekend/);

    view.render({
        state: "detail",
        detail: detail({
            reading: {
                status: "unknown",
                read_date_known: null,
                active_rounds: 0,
                completed_rounds: 0,
                stopped_rounds: 0,
                historical_completed_rounds: 0,
            },
        }),
        backUrl: "https://example.test/mijn-bibliotheek/?library_id=library-1",
    });
    assert.match(text(root), /Leesstatus onbekend/);
});

test("Start Reading is presentation-only and focuses authoritative updates", () => {
    const { root, view } = setup();
    const openers = [];

    view.render(
        {
            state: "detail",
            detail: detail({
                capabilities: { view_item: true, start_reading: true },
            }),
            backUrl: "https://example.test/mijn-bibliotheek/"
                + "?library_id=library-1",
        },
        {
            backToOverview() {},
            startReading(opener) {
                openers.push(opener);
            },
        }
    );

    assert.deepEqual(
        byTag(root, "button").map((node) => node.textContent),
        ["Lezen starten"]
    );
    byTag(root, "button")[0].click();
    assert.equal(openers[0], byTag(root, "button")[0]);

    view.render(
        {
            state: "detail",
            detail: detail({
                capabilities: { view_item: true, start_reading: false },
            }),
            backUrl: "https://example.test/mijn-bibliotheek/"
                + "?library_id=library-1",
        },
        { backToOverview() {} }
    );
    assert.equal(byTag(root, "button").length, 0);

    view.render(
        {
            state: "detail",
            detail: detail({
                capabilities: { view_item: true, start_reading: false },
            }),
            backUrl: "https://example.test/mijn-bibliotheek/"
                + "?library_id=library-1",
            notice: "Lezen is gestart.",
            focusReading: true,
        },
        { backToOverview() {} }
    );
    const status = descendants(root, (node) => (
        node.getAttribute("role") === "status"
    ))[0];
    assert.equal(status.textContent, "Lezen is gestart.");
    assert.equal(status.focused, true);
});

test("End Reading appears only for capability plus active round and passes its opener", () => {
    const { root, view } = setup();
    const openers = [];
    const activeRound = {
        reading_round_id: "private-round",
        version: 4,
        started_on: { year: 2026, month: 8, day: 20 },
    };

    view.render(
        {
            state: "detail",
            detail: detail({
                capabilities: {
                    view_item: true,
                    start_reading: false,
                    end_reading: true,
                },
                active_reading_round: activeRound,
            }),
            backUrl: "https://example.test/mijn-bibliotheek/"
                + "?library_id=library-1",
        },
        {
            backToOverview() {},
            endReading(opener) {
                openers.push(opener);
            },
        }
    );

    assert.deepEqual(
        byTag(root, "button").map((node) => node.textContent),
        ["Leesronde afronden"]
    );
    assert.match(text(root), /Huidige leesronde · gestart op 20 augustus 2026/);
    byTag(root, "button")[0].click();
    assert.equal(openers[0], byTag(root, "button")[0]);
    assert.doesNotMatch(text(root), /private-round|version/);

    for (const [endReading, activeReadingRound] of [
        [false, activeRound],
        [true, null],
    ]) {
        view.render({
            state: "detail",
            detail: detail({
                capabilities: {
                    view_item: true,
                    start_reading: false,
                    end_reading: endReading,
                },
                active_reading_round: activeReadingRound,
            }),
            backUrl: "https://example.test/mijn-bibliotheek/"
                + "?library_id=library-1",
        });
        assert.equal(byTag(root, "button").length, 0);
    }
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
    assert.equal(
        descendants(root, (node) => node.getAttribute("role") === "alert").length,
        1
    );
    assert.doesNotMatch(text(root), /Foreign title|database details/);
    byTag(root, "a")[0].click();
    assert.equal(backCalls, 1);
});

test("detail mirrors busy state and can focus the new view heading", () => {
    const { root, view } = setup();

    view.render({ state: "detail-loading" });
    assert.equal(root.getAttribute("aria-busy"), "true");

    view.render({
        state: "detail",
        detail: detail(),
        backUrl: "https://example.test/mijn-bibliotheek/?library_id=library-1",
        focusHeading: true,
    });
    assert.equal(root.getAttribute("aria-busy"), "false");
    assert.equal(byTag(root, "h1")[0].getAttribute("tabindex"), "-1");
    assert.equal(byTag(root, "h1")[0].focused, true);
});
