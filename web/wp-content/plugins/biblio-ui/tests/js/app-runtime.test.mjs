import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import { BiblioApiError } from "../../assets/js/api.js";

const appSourceUrl = new URL("../../assets/js/app.js", import.meta.url);
let appSource = await readFile(appSourceUrl, "utf8");

for (const [moduleId, file] of [
    ["biblio-ui/api", "api.js"],
    ["biblio-ui/library-state", "library-state.js"],
    ["biblio-ui/overview-view", "overview-view.js"],
    ["biblio-ui/route-state", "route-state.js"],
]) {
    appSource = appSource.replaceAll(
        `"${moduleId}"`,
        JSON.stringify(new URL(`../../assets/js/${file}`, import.meta.url).href)
    );
}

const { bootstrapLibraryApps, createLibraryApp } = await import(
    `data:text/javascript;base64,${Buffer.from(appSource).toString("base64")}`
);

function mount() {
    return {
        dataset: {
            restRoot: "https://example.test/wp-json/biblio/v1/",
            restNonce: "rest-nonce",
            overviewUrl: "https://example.test/mijn-bibliotheek/",
            loginUrl: "https://example.test/wp-login.php?redirect_to=library",
        },
    };
}

function browserDouble(url) {
    const location = { href: url };
    const historyCalls = [];
    const listeners = new Map();

    return {
        location,
        historyCalls,
        listeners,
        history: {
            pushState(state, title, nextUrl) {
                historyCalls.push(["push", state, title, nextUrl]);
                location.href = nextUrl;
            },
            replaceState(state, title, nextUrl) {
                historyCalls.push(["replace", state, title, nextUrl]);
                location.href = nextUrl;
            },
        },
        eventTarget: {
            addEventListener(type, listener) {
                listeners.set(type, listener);
            },
            removeEventListener(type, listener) {
                if (listeners.get(type) === listener) {
                    listeners.delete(type);
                }
            },
        },
    };
}

function library(id, {
    designated = false,
    direct = true,
    loan = false,
} = {}) {
    return {
        library_id: id,
        name: `Library ${id}`,
        type: "private",
        status: "active",
        designated_personal: designated,
        capabilities: {
            view_collection: true,
            add_catalog_item: false,
            modify_catalog_context: false,
            manage_classification_terms: false,
            publish_contribution: false,
            moderate_contribution: false,
            use_item_directly: direct,
            receive_internal_loan: loan,
        },
    };
}

function item(id, title = `Book ${id}`) {
    return {
        item_id: id,
        work_id: `work-${id}`,
        edition_id: `edition-${id}`,
        title,
        authors: { state: "unknown", values: [] },
        cover_reference: { state: "unknown", value: null },
        form: { state: "known", value: "physical_book" },
        location_or_source: { state: "known", value: "Library source" },
        reading_status: "not_read",
        item_status: "active",
        capabilities: { view_item: true, start_reading: true },
    };
}

function overview(selectedLibrary, items, nextCursor = null) {
    return {
        library: selectedLibrary,
        items,
        next_cursor: nextCursor,
    };
}

function recorder() {
    const renders = [];

    return {
        renders,
        factory() {
            return {
                render(model, actions = {}) {
                    renders.push({
                        model: structuredClone(model),
                        actions,
                    });
                },
            };
        },
    };
}

function createApp({ url, get, renders }) {
    const browser = browserDouble(url);
    const app = createLibraryApp(mount(), {
        apiFactory() {
            return { get };
        },
        historyImpl: browser.history,
        locationImpl: browser.location,
        eventTarget: browser.eventTarget,
        viewFactory: renders.factory,
    });

    return { app, browser };
}

async function waitFor(predicate) {
    for (let attempt = 0; attempt < 20; attempt += 1) {
        if (predicate()) {
            return;
        }

        await new Promise((resolve) => setImmediate(resolve));
    }

    throw new Error("Timed out waiting for the test condition.");
}

test("real mount bootstrap creates and starts one idempotent app instance", () => {
    const root = mount();
    const calls = [];
    const app = { start() { calls.push("start"); } };
    const documentImpl = {
        querySelectorAll(selector) {
            calls.push(selector);
            return [root];
        },
    };
    const appFactory = (receivedMount, options) => {
        calls.push([receivedMount, options.documentImpl]);
        return app;
    };

    const first = bootstrapLibraryApps({ documentImpl, appFactory });
    const second = bootstrapLibraryApps({ documentImpl, appFactory });

    assert.equal(first[0], app);
    assert.equal(second[0], app);
    assert.deepEqual(calls, [
        "[data-biblio-ui-root]",
        [root, documentImpl],
        "start",
        "[data-biblio-ui-root]",
    ]);
});

test("zero, chooser and unavailable Library states never request Items", async () => {
    const cases = [
        {
            url: "https://example.test/mijn-bibliotheek/",
            libraries: [],
            expected: "zero-libraries",
        },
        {
            url: "https://example.test/mijn-bibliotheek/",
            libraries: [
                library("one", { direct: false, loan: true }),
                library("two", { direct: false }),
            ],
            expected: "library-chooser",
        },
        {
            url: "https://example.test/mijn-bibliotheek/?library_id=missing",
            libraries: [library("one", { designated: true })],
            expected: "library-unavailable",
        },
    ];

    for (const fixture of cases) {
        const requests = [];
        const renders = recorder();
        const { app } = createApp({
            url: fixture.url,
            renders,
            async get(path) {
                requests.push(path);
                return { libraries: fixture.libraries };
            },
        });

        await app.start();

        assert.deepEqual(requests, ["me/libraries"]);
        assert.deepEqual(
            renders.renders.map(({ model }) => model.state),
            ["library-loading", fixture.expected]
        );
    }
});

test("chooser selection writes URL state then rebuilds with fresh Library data", async () => {
    const first = library("library-a", { direct: false, loan: true });
    const selected = library("library-b", { direct: false });
    const requests = [];
    const renders = recorder();
    const { app, browser } = createApp({
        url: "https://example.test/mijn-bibliotheek/",
        renders,
        async get(path) {
            requests.push(path);

            return path === "me/libraries"
                ? { libraries: [first, selected] }
                : overview(selected, [item("one")], null);
        },
    });

    await app.start();
    await renders.renders.at(-1).actions.selectLibrary("library-b");

    assert.deepEqual(browser.historyCalls, [[
        "push",
        null,
        "",
        "https://example.test/mijn-bibliotheek/?library_id=library-b",
    ]]);
    assert.deepEqual(requests, [
        "me/libraries",
        "me/libraries",
        "libraries/library-b/items",
    ]);
    assert.equal(renders.renders.at(-1).model.state, "overview");
    assert.equal(renders.renders.at(-1).model.library.library_id, "library-b");
});

test("a selected Library loads only the exact active overview contract", async () => {
    const selected = library("library/one", { designated: true });
    const requests = [];
    const renders = recorder();
    const { app } = createApp({
        url: "https://example.test/mijn-bibliotheek/",
        renders,
        async get(path, options) {
            requests.push([path, options.signal]);

            return path === "me/libraries"
                ? { libraries: [selected] }
                : overview(selected, [item("one")], "cursor-1");
        },
    });

    await app.start();

    assert.deepEqual(requests.map(([path]) => path), [
        "me/libraries",
        "libraries/library%2Fone/items",
    ]);
    assert.ok(requests.every(([, signal]) => signal instanceof AbortSignal));
    assert.deepEqual(
        renders.renders.map(({ model }) => model.state),
        ["library-loading", "overview-loading", "overview"]
    );
    assert.deepEqual(renders.renders.at(-1).model, {
        state: "overview",
        library: selected,
        items: [item("one")],
        nextCursor: "cursor-1",
        loadingMore: false,
        loadMoreError: false,
        canRetryCursor: false,
    });
});

test("Meer laden follows only the opaque cursor and appends in order", async () => {
    const selected = library("library-1", { designated: true });
    const requests = [];
    const renders = recorder();
    const { app } = createApp({
        url: "https://example.test/mijn-bibliotheek/",
        renders,
        async get(path) {
            requests.push(path);

            if (path === "me/libraries") {
                return { libraries: [selected] };
            }

            return path.includes("?cursor=")
                ? overview(selected, [item("two")], null)
                : overview(selected, [item("one")], "opaque+/=");
        },
    });

    await app.start();
    await renders.renders.at(-1).actions.loadMore();

    assert.deepEqual(requests, [
        "me/libraries",
        "libraries/library-1/items",
        "libraries/library-1/items?cursor=opaque%2B%2F%3D",
    ]);
    assert.deepEqual(
        renders.renders.at(-1).model.items.map(({ item_id: id }) => id),
        ["one", "two"]
    );
    assert.equal(renders.renders.at(-1).model.nextCursor, null);
});

test("invalid cursor keeps Items, permits one retry and can restart page one", async () => {
    const selected = library("library-1", { designated: true });
    const renders = recorder();
    let firstPageRequests = 0;
    let cursorRequests = 0;
    const cursorError = new BiblioApiError({
        kind: "http",
        code: "biblio_invalid_field_syntax",
        status: 400,
        message: "A request field has invalid syntax.",
    });
    const { app } = createApp({
        url: "https://example.test/mijn-bibliotheek/",
        renders,
        async get(path) {
            if (path === "me/libraries") {
                return { libraries: [selected] };
            }

            if (path.includes("?cursor=")) {
                cursorRequests += 1;
                throw cursorError;
            }

            firstPageRequests += 1;
            return overview(selected, [item("one")], "stale-cursor");
        },
    });

    await app.start();
    await renders.renders.at(-1).actions.loadMore();

    assert.deepEqual(
        renders.renders.at(-1).model.items.map(({ item_id: id }) => id),
        ["one"]
    );
    assert.equal(renders.renders.at(-1).model.loadMoreError, true);
    assert.equal(renders.renders.at(-1).model.canRetryCursor, true);

    await renders.renders.at(-1).actions.retryLoadMore();
    assert.equal(renders.renders.at(-1).model.canRetryCursor, false);
    await renders.renders.at(-1).actions.restart();

    assert.equal(cursorRequests, 2);
    assert.equal(firstPageRequests, 2);
    assert.equal(renders.renders.at(-1).model.loadMoreError, false);
});

test("overview failures render only safe retry or Library-unavailable states", async () => {
    const selected = library("library-1", { designated: true });
    const renders = recorder();
    let overviewRequests = 0;
    const temporaryError = new BiblioApiError({
        kind: "http",
        code: "biblio_core_unavailable",
        status: 503,
        message: "Biblio is temporarily unavailable.",
    });
    const unavailableError = new BiblioApiError({
        kind: "http",
        code: "biblio_resource_not_available",
        status: 404,
        message: "The requested Biblio resource is not available.",
    });
    const { app } = createApp({
        url: "https://example.test/mijn-bibliotheek/",
        renders,
        async get(path) {
            if (path === "me/libraries") {
                return { libraries: [selected] };
            }

            overviewRequests += 1;
            throw overviewRequests === 1 ? temporaryError : unavailableError;
        },
    });

    await app.start();
    assert.equal(renders.renders.at(-1).model.state, "request-error");
    await renders.renders.at(-1).actions.retry();

    assert.equal(overviewRequests, 2);
    assert.equal(renders.renders.at(-1).model.state, "library-unavailable");
    assert.doesNotMatch(
        JSON.stringify(renders.renders.map(({ model }) => model)),
        /temporarily unavailable|requested Biblio resource/
    );
});

test("popstate aborts obsolete overview work and rebuilds from fresh URL data", async () => {
    const firstLibrary = library("library-a");
    const secondLibrary = library("library-b");
    const renders = recorder();
    const requests = [];
    let resolveFirstOverview;
    const firstOverview = new Promise((resolve) => {
        resolveFirstOverview = resolve;
    });
    const { app, browser } = createApp({
        url: "https://example.test/mijn-bibliotheek/?library_id=library-a",
        renders,
        async get(path, options) {
            requests.push([path, options.signal]);

            if (path === "me/libraries") {
                return { libraries: [firstLibrary, secondLibrary] };
            }

            if (path === "libraries/library-a/items") {
                return firstOverview;
            }

            return overview(secondLibrary, [item("new")], null);
        },
    });

    const obsoleteRun = app.start();
    await waitFor(() => requests.some(([path]) => (
        path === "libraries/library-a/items"
    )));
    const obsoleteSignal = requests.find(([path]) => (
        path === "libraries/library-a/items"
    ))[1];

    browser.location.href = "https://example.test/mijn-bibliotheek/"
        + "?library_id=library-b";
    browser.listeners.get("popstate")();
    await app.whenIdle();

    assert.equal(obsoleteSignal.aborted, true);
    assert.equal(requests.filter(([path]) => path === "me/libraries").length, 2);
    assert.equal(renders.renders.at(-1).model.library.library_id, "library-b");

    resolveFirstOverview(overview(firstLibrary, [item("stale")], null));
    await obsoleteRun;

    assert.equal(renders.renders.at(-1).model.library.library_id, "library-b");
    assert.doesNotMatch(
        JSON.stringify(renders.renders.map(({ model }) => model)),
        /stale/
    );
    assert.equal(
        renders.renders.some(({ model }) => model.state === "request-error"),
        false
    );
});

test("an aborted view request is control flow and never renders an error", async () => {
    const selected = library("library-a", { designated: true });
    const renders = recorder();
    const aborted = new BiblioApiError({
        kind: "aborted",
        code: "biblio_ui_request_aborted",
        status: null,
        message: "The Biblio request was aborted.",
    });
    let overviewStarted = false;
    const { app, browser } = createApp({
        url: "https://example.test/mijn-bibliotheek/?library_id=library-a",
        renders,
        async get(path, options) {
            if (path === "me/libraries") {
                return browser.location.href.includes("library_id")
                    ? { libraries: [selected] }
                    : { libraries: [] };
            }

            overviewStarted = true;
            return new Promise((resolve, reject) => {
                options.signal.addEventListener("abort", () => reject(aborted));
            });
        },
    });

    const obsoleteRun = app.start();
    await waitFor(() => overviewStarted);
    browser.location.href = "https://example.test/mijn-bibliotheek/";
    browser.listeners.get("popstate")();
    await app.whenIdle();
    await obsoleteRun;

    assert.equal(renders.renders.at(-1).model.state, "zero-libraries");
    assert.equal(
        renders.renders.some(({ model }) => model.state === "request-error"),
        false
    );
});
