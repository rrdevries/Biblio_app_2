import assert from "node:assert/strict";
import test from "node:test";

import {
    createReadingHistoryView,
    formatReadingDate,
    readingHistoryPath,
    readReadingHistoryPage,
} from "../../assets/js/reading-history.js";

class FakeElement {
    constructor(tagName) {
        this.tagName = tagName.toUpperCase();
        this.attributes = new Map();
        this.children = [];
        this.className = "";
        this.textContent = "";
        this.listeners = new Map();
        this.disabled = false;
        this.focused = false;
        this.focusCount = 0;
        this.parentNode = null;
        this.rootConnected = false;
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }

    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    }

    append(...children) {
        for (const child of children) {
            child.parentNode = this;
        }

        this.children.push(...children);
    }

    replaceChildren(...children) {
        for (const child of this.children) {
            child.parentNode = null;
        }

        for (const child of children) {
            child.parentNode = this;
        }

        this.children = [...children];
    }

    addEventListener(type, listener) {
        this.listeners.set(type, listener);
    }

    click() {
        if (!this.disabled) {
            return this.listeners.get("click")?.();
        }
    }

    focus() {
        if (!this.isConnected) {
            throw new Error("Focus target must be connected before focus().");
        }

        this.focused = true;
        this.focusCount += 1;
    }

    get isConnected() {
        return this.rootConnected || this.parentNode?.isConnected === true;
    }

    querySelector(selector) {
        return descendants(this, (node) => (
            selector === "[data-biblio-reading-history]"
            && node.getAttribute("data-biblio-reading-history") === "true"
        ))[0] ?? null;
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

function focused(root) {
    return descendants(root, (node) => node.focused);
}

function text(root) {
    return [root.textContent, ...root.children.map(text)].filter(Boolean).join(" ");
}

function entry(overrides = {}) {
    return {
        outcome: "completed",
        started_on: { year: 2025, month: 3, day: 12 },
        finished_on: { year: 2025, month: 3, day: 28 },
        source_type: "library_item",
        historical_registration: false,
        ...overrides,
    };
}

function ready(overrides = {}) {
    return {
        state: "ready",
        items: [entry()],
        nextCursor: null,
        refreshing: false,
        refreshError: false,
        refreshRecovery: "retry",
        loadingMore: false,
        loadMoreError: false,
        paginationRecovery: "retry",
        focusAfterPagination: false,
        focusAfterPaginationError: false,
        addedCount: 0,
        ...overrides,
    };
}

function setup() {
    const root = new FakeElement("div");
    root.rootConnected = true;
    const region = new FakeElement("div");
    region.setAttribute("data-biblio-reading-history", "true");
    root.append(region);

    return {
        root,
        region,
        view: createReadingHistoryView(root, { documentImpl }),
    };
}

test("strict history contract accepts empty, outcomes, sources and date precision", () => {
    assert.deepEqual(readReadingHistoryPage({
        items: [],
        next_cursor: null,
    }), {
        items: [],
        nextCursor: null,
    });

    const page = readReadingHistoryPage({
        items: [
            entry(),
            entry({
                outcome: "stopped",
                started_on: { year: 2024, month: null, day: null },
                finished_on: { year: 2024, month: 2, day: null },
                source_type: "external_loan",
            }),
            entry({
                started_on: null,
                source_type: "unknown",
                historical_registration: true,
            }),
        ],
        next_cursor: "opaque-cursor",
    });

    assert.equal(page.items.length, 3);
    assert.equal(page.items[1].outcome, "stopped");
    assert.equal(page.items[1].finished_on.day, null);
    assert.equal(page.items[2].started_on, null);
    assert.equal(page.nextCursor, "opaque-cursor");
});

test("strict history contract rejects malformed shapes without false precision", () => {
    for (const invalid of [
        null,
        { items: [], next_cursor: null, extra: true },
        { items: "not-an-array", next_cursor: null },
        { items: [], next_cursor: "" },
        { items: [entry({ outcome: "paused" })], next_cursor: null },
        { items: [entry({ source_type: "library" })], next_cursor: null },
        { items: [entry({ historical_registration: "yes" })], next_cursor: null },
        { items: [entry({ started_on: { year: 2025, month: null, day: 1 } })], next_cursor: null },
        { items: [entry({ finished_on: { year: 2025, month: 2, day: 30 } })], next_cursor: null },
        { items: [{ ...entry(), reading_round_id: "private" }], next_cursor: null },
    ]) {
        assert.throws(() => readReadingHistoryPage(invalid), TypeError);
    }
});

test("history path encodes only validated Work and opaque cursor", () => {
    assert.equal(
        readingHistoryPath("work/één"),
        "me/works/work%2F%C3%A9%C3%A9n/reading-history?limit=10"
    );
    assert.equal(
        readingHistoryPath("work/één", "opaque/+"),
        "me/works/work%2F%C3%A9%C3%A9n/reading-history?limit=10"
            + "&cursor=opaque%2F%2B"
    );
    assert.throws(() => readingHistoryPath(""), TypeError);
    assert.throws(() => readingHistoryPath("work/één", ""), TypeError);
});

test("Dutch formatter preserves exact, month and year precision", () => {
    assert.equal(
        formatReadingDate({ year: 2025, month: 3, day: 12 }),
        "12 maart 2025"
    );
    assert.equal(
        formatReadingDate({ year: 2025, month: 3, day: null }),
        "maart 2025"
    );
    assert.equal(
        formatReadingDate({ year: 2025, month: null, day: null }),
        "2025"
    );
});

test("loading, empty and local retry stay subordinate without stealing focus", () => {
    const { region, view } = setup();
    let retries = 0;
    const actions = {
        retry() { retries += 1; },
        reload() {},
        loginUrl: "https://example.test/login",
    };

    view.render({ state: "loading" }, actions);
    assert.equal(region.getAttribute("aria-busy"), "true");
    assert.match(text(region), /Leesgeschiedenis laden/);
    assert.equal(byTag(region, "h2").length, 0);
    assert.equal(byAttribute(region, "aria-live", "polite").length, 1);
    assert.equal(byAttribute(region, "role", "status").length, 0);
    assert.equal(focused(region).length, 0);

    view.render({ state: "empty" }, actions);
    assert.equal(region.children.length, 0);
    assert.equal(region.getAttribute("aria-busy"), "false");

    view.render({
        state: "error",
        message: "Leesgeschiedenis kon niet worden geladen.",
        recovery: "retry",
    }, actions);
    assert.equal(byTag(region, "h2").length, 0);
    assert.match(text(region), /kon niet worden geladen/);
    assert.equal(region.getAttribute("aria-busy"), "false");
    assert.equal(focused(region).length, 0);
    assert.equal(byAttribute(region, "aria-live", "polite").length, 1);
    assert.equal(byAttribute(region, "role", "status").length, 0);
    const retry = byTag(region, "button")[0];
    assert.equal(retry.getAttribute("type"), "button");
    assert.equal(retry.textContent, "Opnieuw proberen");
    retry.click();
    assert.equal(retries, 1);

    view.render(ready(), actions);
    assert.equal(region.getAttribute("aria-busy"), "false");
    assert.equal(focused(region).length, 0);
});

test("history renders a native list, intact precision and text-only status", () => {
    const { region, view } = setup();
    view.render(ready({
        items: [
            entry(),
            entry({
                outcome: "stopped",
                started_on: null,
                finished_on: { year: 2024, month: 2, day: null },
                source_type: "external_loan",
            }),
            entry({
                source_type: "unknown",
                historical_registration: true,
            }),
            entry({ source_type: "unknown" }),
            entry({
                started_on: { year: 2025, month: 3, day: null },
                finished_on: { year: 2025, month: 4, day: null },
            }),
            entry({
                started_on: { year: 2024, month: null, day: null },
                finished_on: { year: 2025, month: null, day: null },
            }),
            entry({
                started_on: null,
                finished_on: { year: 2025, month: 9, day: null },
            }),
            entry({ started_on: null }),
            entry(),
            entry({ outcome: "stopped" }),
        ],
    }), {});

    assert.deepEqual(byTag(region, "h2").map((node) => node.textContent), [
        "Leesgeschiedenis",
    ]);
    assert.equal(byTag(region, "ul").length, 1);
    assert.equal(byTag(region, "li").length, 10);
    assert.equal(byTag(region, "table").length, 0);
    assert.match(text(region), /Uitgelezen/);
    assert.match(text(region), /Gestopt/);
    assert.match(text(region), /12 maart 2025 – 28 maart 2025/);
    assert.match(text(region), /Afgerond februari 2024/);
    assert.match(text(region), /maart 2025 – april 2025/);
    assert.match(text(region), /2024 – 2025/);
    assert.match(text(region), /Afgerond september 2025/);
    assert.match(text(region), /Afgerond 28 maart 2025/);
    assert.match(text(region), /Externe lening/);
    assert.match(text(region), /Historische registratie/);
    assert.doesNotMatch(text(region), /Eigen exemplaar|Bron onbekend|paused/);
    assert.equal(byAttribute(region, "aria-live", "polite").length, 0);
    assert.equal(byTag(region, "li").some((node) => (
        node.getAttribute("tabindex") !== null
    )), false);
    assert.equal(focused(region).length, 0);
});

test("pagination uses native disabled controls and restores deliberate focus", () => {
    const { region, view } = setup();
    let loads = 0;
    let retries = 0;
    const actions = {
        loadMore() { loads += 1; },
        retryLoadMore() { retries += 1; },
        retry() {},
        reload() {},
        loginUrl: "https://example.test/login",
    };

    view.render(ready({ nextCursor: "next", loadingMore: true }), actions);
    const pendingButton = byTag(region, "button")[0];
    assert.equal(pendingButton.textContent, "Meer laden");
    assert.equal(pendingButton.getAttribute("type"), "button");
    assert.equal(pendingButton.disabled, true);
    assert.equal(region.getAttribute("aria-busy"), "true");
    assert.equal(byAttribute(region, "aria-live", "polite").length, 1);
    assert.equal(byAttribute(region, "role", "status").length, 0);
    assert.equal(pendingButton.listeners.has("keydown"), false);
    pendingButton.click();
    assert.equal(loads, 0);

    view.render(ready({
        nextCursor: "next",
        loadMoreError: true,
        focusAfterPaginationError: true,
    }), actions);
    assert.match(text(region), /Meer leesgeschiedenis kon niet worden geladen/);
    assert.equal(region.getAttribute("aria-busy"), "false");
    const paginationRetry = byTag(region, "button").at(-1);
    assert.equal(paginationRetry.focused, true);
    assert.equal(paginationRetry.getAttribute("type"), "button");
    paginationRetry.click();
    assert.equal(retries, 1);

    view.render(ready({
        nextCursor: "next-2",
        focusAfterPagination: true,
        addedCount: 2,
    }), actions);
    const continuingButton = byTag(region, "button")[0];
    assert.equal(continuingButton.focused, true);
    assert.equal(continuingButton.getAttribute("tabindex"), null);
    assert.match(text(region), /2 leesrondes toegevoegd/);
    assert.equal(byAttribute(region, "aria-live", "polite").length, 1);

    view.render(ready({
        nextCursor: null,
        focusAfterPagination: true,
    }), actions);
    const finalHeading = byTag(region, "h2")[0];
    assert.equal(finalHeading.focused, true);
    assert.equal(finalHeading.getAttribute("tabindex"), "-1");
    assert.equal(byTag(region, "button").length, 0);
});

test("automatic refresh stays busy and never overrides End Reading focus", () => {
    const { region, view } = setup();
    const actions = {
        retry() {},
        reload() {},
        loginUrl: "https://example.test/login",
    };

    view.render(ready({ refreshing: true }), actions);
    assert.equal(region.getAttribute("aria-busy"), "true");
    assert.match(text(region), /Leesgeschiedenis vernieuwen/);
    assert.equal(focused(region).length, 0);

    view.render(ready({
        refreshError: true,
        refreshRecovery: "retry",
    }), actions);
    assert.equal(region.getAttribute("aria-busy"), "false");
    assert.match(
        text(region),
        /leesstatus is bijgewerkt, maar de leesgeschiedenis kon niet worden vernieuwd/
    );
    assert.doesNotMatch(text(region), /500|nonce|REST|\/me\/works|stack/i);
    assert.equal(focused(region).length, 0);
    assert.equal(byAttribute(region, "aria-live", "polite").length, 1);
    assert.equal(byAttribute(region, "role", "status").length, 0);
});
