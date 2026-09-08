import assert from "node:assert/strict";
import test from "node:test";

import { createOverviewView } from "../../assets/js/overview-view.js";

class FakeElement {
    constructor(tagName, ownerDocument = null) {
        this.tagName = tagName.toUpperCase();
        this.attributes = new Map();
        this.children = [];
        this.className = "";
        this.textContent = "";
        this.disabled = false;
        this.checked = false;
        this.value = "";
        this.selected = false;
        this.listeners = new Map();
        this.focused = false;
        this.open = false;
        this.parent = null;
        this.ownerDocument = ownerDocument;
        this.selectionStart = 0;
        this.selectionEnd = 0;
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }

    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    }

    append(...children) {
        this.children.push(...children);
        for (const child of children) {
            child.parent = this;
        }
    }

    replaceChildren(...children) {
        this.children = [...children];
        for (const child of children) {
            child.parent = this;
        }
    }

    addEventListener(type, listener) {
        this.listeners.set(type, listener);
    }

    click(event) {
        return this.listeners.get("click")?.(event);
    }

    trigger(type, event = {}) {
        return this.listeners.get(type)?.({ currentTarget: this, ...event });
    }

    querySelector(selector) {
        return descendants(this, (node) => (
            (["h1", "dialog"].includes(selector)
                && node.tagName === selector.toUpperCase())
            || (selector.startsWith("#")
                && node.getAttribute("id") === selector.slice(1))
        ))[0] ?? null;
    }

    querySelectorAll(selector) {
        if (selector === "[data-quick-view-for]") {
            return descendants(this, (node) => (
                node.getAttribute("data-quick-view-for") !== null
            ));
        }
        if (selector === "[data-biblio-focus-key]") {
            return descendants(this, (node) => (
                node.getAttribute("data-biblio-focus-key") !== null
            ));
        }
        return [];
    }

    focus() {
        if (this.ownerDocument?.activeElement) {
            this.ownerDocument.activeElement.focused = false;
        }
        if (this.ownerDocument !== null) {
            this.ownerDocument.activeElement = this;
        }
        this.focused = true;
    }

    setSelectionRange(start, end) {
        this.selectionStart = start;
        this.selectionEnd = end;
    }

    showModal() {
        this.open = true;
    }

    close() {
        this.open = false;
        this.listeners.get("close")?.();
    }

    remove() {
        if (this.parent !== null) {
            this.parent.children = this.parent.children.filter((child) => child !== this);
        }
    }
}

const documentImpl = {
    activeElement: null,
    createElement(tagName) {
        return new FakeElement(tagName, this);
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

function byClass(root, className) {
    return descendants(root, (node) => node.className.split(" ").includes(className));
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
            add_catalog_item: false,
            use_item_directly: true,
            receive_internal_loan: false,
        },
    };
}

test("Add Book entry is shown only for the presentation capability", () => {
    const { root, view } = setup();
    let trigger = null;
    const enabled = library();
    enabled.capabilities.add_catalog_item = true;

    view.render(overviewModel({ library: enabled, items: [] }), {
        addBook(opener) { trigger = opener; },
    });
    const add = byTag(root, "button").find((control) => (
        control.textContent === "Boek toevoegen"
    ));
    assert.ok(add);
    add.click({ currentTarget: add });
    assert.equal(trigger, add);

    view.render(overviewModel({ items: [] }), { addBook() {} });
    assert.equal(byTag(root, "button").some((control) => (
        control.textContent === "Boek toevoegen"
    )), false);
});

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
        query: {
            search: "",
            readingStatuses: [],
            authorIds: [],
            seriesIds: [],
            locationIds: [],
            bookTypeIds: [],
            genreIds: [],
            subjectIds: [],
            collectionIds: [],
            withoutCollection: false,
            sort: "title",
            archiveScope: "active_only",
        },
        searchDraft: "",
        filterOptions: {
            bookTypes: [],
            genres: [],
            subjects: [],
        },
        refreshing: false,
        queryError: false,
        resultAnnouncement: "1 boek geladen.",
        ...overrides,
    };
}

function setup() {
    documentImpl.activeElement = null;
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
    const opened = [];
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

    view.render(
        overviewModel({ items: [first, second] }),
        { openItem(itemId) { opened.push(itemId); } }
    );

    assert.equal(root.children[0].getAttribute("data-biblio-view"), "overview");
    assert.equal(byTag(root, "h1").length, 1);
    assert.equal(byTag(root, "ul").length, 1);
    assert.equal(byTag(root, "li").length, 2);
    assert.equal(byTag(root, "img").length, 1);
    assert.equal(byClass(root, "biblio-ui__cover--placeholder").length, 1);
    assert.equal(
        byClass(root, "biblio-ui__cover--placeholder")[0]
            .getAttribute("aria-label"),
        "Geen omslag beschikbaar voor Zonder metadata"
    );
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
    const clickEvent = {
        button: 0,
        defaultPrevented: false,
        preventDefault() {
            this.defaultPrevented = true;
        },
    };
    byTag(root, "a")[0].click(clickEvent);
    assert.equal(clickEvent.defaultPrevented, true);
    assert.deepEqual(opened, ["one"]);

    byTag(root, "a")[0].click({
        button: 0,
        ctrlKey: true,
        defaultPrevented: false,
        preventDefault() {
            throw new Error("Modified link clicks must keep browser behavior.");
        },
    });
    assert.deepEqual(opened, ["one"]);
    assert.match(text(root), /Auteur A, Auteur B/);
    assert.match(text(root), /Boek · Kast B Aan het lezen/);
    assert.match(text(root), /Zonder metadata Auteur onbekend Uitgelezen/);
    assert.doesNotMatch(text(root), /never render|work-one|edition-one/);
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
    assert.equal(
        descendants(root, (node) => node.getAttribute("role") === "alert").length,
        1
    );
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
        {
            loadMore() { loads += 1; },
            quickView() {},
        }
    );
    assert.equal(byClass(root, "biblio-ui__load-more")[0].disabled, true);
    assert.equal(
        byClass(root, "biblio-ui__load-more")[0].getAttribute("aria-busy"),
        "true"
    );

    view.render(
        overviewModel({
            nextCursor: "cursor",
            loadMoreError: true,
            canRetryCursor: true,
        }),
        {
            retryLoadMore() { retries += 1; },
            restart() { restarts += 1; },
            quickView() {},
        }
    );
    assert.match(text(root), /Meer boeken konden niet worden geladen/);
    const error = byClass(root, "biblio-ui__inline-error")[0];
    assert.equal(byTag(error, "button").length, 2);
    byTag(error, "button")[0].click();
    byTag(error, "button")[1].click();
    assert.equal(retries, 1);
    assert.equal(restarts, 1);

    view.render(
        overviewModel({
            nextCursor: "cursor",
            loadMoreError: true,
            canRetryCursor: false,
        }),
        {
            restart() { restarts += 1; },
            quickView() {},
        }
    );
    assert.equal(
        byTag(byClass(root, "biblio-ui__inline-error")[0], "button").length,
        1
    );
});

test("toolbar exposes working query controls, switches Grid/List and disables Bookshelf", () => {
    const { root, view } = setup();
    const received = [];
    const actions = {
        openItem() {},
        quickView() {},
        searchInput(value) { received.push(["search", value]); },
        submitSearch(value) { received.push(["submit", value]); },
        clearSearch() {},
        setFilter(property, value, selected) {
            received.push([property, value, selected]);
        },
        setWithoutCollection(value) { received.push(["withoutCollection", value]); },
        setArchiveScope(value) { received.push(["archive", value]); },
        setSort(value) { received.push(["sort", value]); },
        clearFilters() {},
    };

    view.render(overviewModel({
        filterOptions: {
            bookTypes: [{ id: "type-book", label: "Boek" }],
            genres: [],
            subjects: [],
        },
    }), actions);
    const search = byTag(root, "input")[0];
    search.value = "Dune";
    search.trigger("input");
    assert.deepEqual(received[0], ["search", "Dune"]);
    assert.equal(byTag(root, "select")[0].value, "title");
    assert.equal(
        byClass(root, "biblio-ui__catalog-list")[0].getAttribute("data-catalog-view"),
        "grid"
    );

    byTag(root, "button").find((button) => button.textContent === "Filters").click();
    assert.match(text(root), /Leesstatus/);
    assert.match(text(root), /Boeksoort/);
    const reading = byTag(root, "input").find((control) => (
        control.getAttribute("type") === "checkbox"
    ));
    reading.checked = true;
    reading.trigger("change");
    assert.deepEqual(received.at(-1), ["readingStatuses", "reading", true]);

    const checkboxes = byTag(root, "input").filter((control) => (
        control.getAttribute("type") === "checkbox"
    ));
    const withoutCollection = checkboxes.at(-2);
    withoutCollection.checked = true;
    withoutCollection.trigger("change");
    assert.deepEqual(received.at(-1), ["withoutCollection", true]);

    byTag(root, "button").find((button) => button.textContent === "Lijst").click();
    assert.equal(
        byClass(root, "biblio-ui__catalog-list")[0].getAttribute("data-catalog-view"),
        "list"
    );

    const bookshelf = byTag(root, "button").find((button) => (
        button.textContent === "Boekenplank"
    ));
    assert.equal(bookshelf.disabled, true);
    assert.equal(
        bookshelf.getAttribute("title"),
        "Boekenplank is nog niet beschikbaar"
    );
    const bookshelfDescription = descendants(root, (node) => (
        node.getAttribute("id") === "biblio-toolbar-contract-note"
    ));
    assert.equal(bookshelfDescription.length, 1);
    assert.equal(
        bookshelf.getAttribute("aria-describedby"),
        bookshelfDescription[0].getAttribute("id")
    );
    bookshelf.click();
    assert.equal(
        byClass(root, "biblio-ui__catalog-list")[0].getAttribute("data-catalog-view"),
        "list"
    );
});

test("live query rerenders preserve search focus and caret", () => {
    const { root, view } = setup();
    const actions = {
        searchInput() {},
        submitSearch() {},
        clearSearch() {},
        setFilter() {},
        setWithoutCollection() {},
        setArchiveScope() {},
        setSort() {},
        clearFilters() {},
    };
    view.render(overviewModel({ searchDraft: "Du" }), actions);
    const firstSearch = byTag(root, "input")[0];
    firstSearch.focus();
    firstSearch.setSelectionRange(2, 2);

    view.render(overviewModel({ searchDraft: "Dun", refreshing: true }), actions);

    const nextSearch = byTag(root, "input")[0];
    assert.notEqual(nextSearch, firstSearch);
    assert.equal(nextSearch.focused, true);
    assert.equal(nextSearch.selectionStart, 2);
    assert.equal(nextSearch.selectionEnd, 2);
});

test("filter and sort rerenders preserve the active keyboard control", () => {
    const { root, view } = setup();
    const actions = {
        searchInput() {},
        submitSearch() {},
        clearSearch() {},
        setFilter() {},
        setWithoutCollection() {},
        setArchiveScope() {},
        setSort() {},
        clearFilters() {},
    };
    const filterOptions = {
        bookTypes: [{ id: "type-book", label: "Boek" }],
        genres: [],
        subjects: [],
    };
    view.render(overviewModel({ filterOptions }), actions);

    const sort = byTag(root, "select")[0];
    sort.focus();
    view.render(overviewModel({
        filterOptions,
        query: { ...overviewModel().query, sort: "author" },
    }), actions);
    assert.equal(byTag(root, "select")[0].focused, true);

    const filterToggle = byTag(root, "button").find((button) => (
        button.textContent === "Filters"
    ));
    filterToggle.focus();
    filterToggle.click();
    const reading = byTag(root, "input").find((control) => (
        control.getAttribute("data-biblio-focus-key")
            === "filter:readingStatuses:reading"
    ));
    reading.focus();
    view.render(overviewModel({
        filterOptions,
        refreshing: true,
        query: {
            ...overviewModel().query,
            readingStatuses: ["reading"],
        },
    }), actions);
    const nextReading = byTag(root, "input").find((control) => (
        control.getAttribute("data-biblio-focus-key")
            === "filter:readingStatuses:reading"
    ));
    assert.equal(nextReading.focused, true);
});

test("Quick View is a modal overlay with status text and full-detail route", () => {
    const { root, view } = setup();
    let closed = 0;
    let opened = null;
    const detail = {
        ...item("one", {
            title: "Overlayboek",
            authors: { state: "known", values: ["Auteur"] },
        }),
        reading: { status: "reading" },
    };

    view.render(overviewModel({
        quickView: { state: "ready", itemId: "one", detail },
    }), {
        openItem(itemId) { opened = itemId; },
        quickView() {},
        closeQuickView() { closed += 1; },
    });

    const dialog = byTag(root, "dialog")[0];
    assert.equal(dialog.open, true);
    assert.match(text(dialog), /Overlayboek[\s\S]*Auteur[\s\S]*Leesstatus: Aan het lezen/);
    const detailLink = byTag(dialog, "a")[0];
    detailLink.click({
        button: 0,
        defaultPrevented: false,
        preventDefault() { this.defaultPrevented = true; },
    });
    assert.equal(opened, "one");
    byTag(dialog, "button")[0].click();
    assert.equal(closed, 1);
    assert.equal(byTag(root, "dialog").length, 0);
    assert.equal(byClass(root, "biblio-ui__quick-view-trigger")[0].focused, true);
});

test("overview mirrors busy state and focuses its heading when requested", () => {
    const { root, view } = setup();

    view.render({ state: "library-loading" });
    assert.equal(root.getAttribute("aria-busy"), "true");

    view.render(overviewModel({ focusHeading: true }));
    assert.equal(root.getAttribute("aria-busy"), "false");
    assert.equal(byTag(root, "h1")[0].getAttribute("tabindex"), "-1");
    assert.equal(byTag(root, "h1")[0].focused, true);

    view.render(overviewModel({ refreshing: true }));
    assert.equal(root.getAttribute("aria-busy"), "true");
});
