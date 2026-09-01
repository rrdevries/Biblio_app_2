import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import { BiblioApiError } from "../../assets/js/api.js";

const appSourceUrl = new URL("../../assets/js/app.js", import.meta.url);
let appSource = await readFile(appSourceUrl, "utf8");

for (const [moduleId, file] of [
    ["biblio-ui/api", "api.js"],
    ["biblio-ui/detail-view", "detail-view.js"],
    ["biblio-ui/end-reading-view", "end-reading-view.js"],
    ["biblio-ui/library-state", "library-state.js"],
    ["biblio-ui/overview-view", "overview-view.js"],
    ["biblio-ui/private-notes", "private-notes.js"],
    ["biblio-ui/reading-history", "reading-history.js"],
    ["biblio-ui/route-state", "route-state.js"],
    ["biblio-ui/start-reading-view", "start-reading-view.js"],
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

function detail(selectedLibrary, itemId, overrides = {}) {
    const unknown = { state: "unknown", value: null };

    return {
        library: selectedLibrary,
        item_id: itemId,
        work_id: `work-${itemId}`,
        edition_id: `edition-${itemId}`,
        title: `Book ${itemId}`,
        authors: { state: "unknown", values: [] },
        cover_reference: unknown,
        isbn: unknown,
        language: unknown,
        publisher: unknown,
        publication_date: unknown,
        series: unknown,
        form: { state: "known", value: "physical_book" },
        location: unknown,
        condition: unknown,
        acquisition: unknown,
        availability: unknown,
        item_status: "active",
        reading: {
            status: "not_read",
            active_rounds: 0,
            completed_rounds: 0,
            stopped_rounds: 0,
            historical_completed_rounds: 0,
        },
        active_reading_round: null,
        capabilities: {
            view_item: true,
            start_reading: true,
            end_reading: false,
        },
        ...overrides,
    };
}

function startedRound(itemId, startedOn = "2026-08-28") {
    const [year, month, day] = startedOn.split("-").map(Number);

    return {
        reading_round_id: "round-1",
        work_id: `work-${itemId}`,
        source: { type: "library_item", item_id: itemId },
        lifecycle: "active",
        started_on: { year, month, day },
        version: 1,
    };
}

function activeRound(
    readingRoundId = "round/active",
    version = 1,
    startedOn = { year: 2026, month: 8, day: 1 }
) {
    return {
        reading_round_id: readingRoundId,
        version,
        started_on: startedOn,
    };
}

function activeDetail(
    selectedLibrary,
    itemId,
    round = activeRound(),
    overrides = {}
) {
    return detail(selectedLibrary, itemId, {
        active_reading_round: round,
        capabilities: {
            view_item: true,
            start_reading: false,
            end_reading: true,
        },
        reading: {
            status: "reading",
            active_rounds: 1,
            completed_rounds: 0,
            stopped_rounds: 0,
            historical_completed_rounds: 0,
        },
        ...overrides,
    });
}

function endedRound(
    readingRoundId,
    outcome,
    finishedOn,
    version = 2
) {
    const [year, month, day] = finishedOn.split("-").map(Number);

    return {
        reading_round_id: readingRoundId,
        lifecycle: "ended",
        outcome,
        finished_on: { year, month, day },
        version,
    };
}

function historyEntry(overrides = {}) {
    return {
        outcome: "completed",
        started_on: { year: 2025, month: 3, day: 12 },
        finished_on: { year: 2025, month: 3, day: 28 },
        source_type: "library_item",
        historical_registration: false,
        ...overrides,
    };
}

function historyPage(items = [], nextCursor = null) {
    return { items, next_cursor: nextCursor };
}

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((resolvePromise, rejectPromise) => {
        resolve = resolvePromise;
        reject = rejectPromise;
    });

    return { promise, resolve, reject };
}

function apiError(status, code) {
    return new BiblioApiError({
        kind: "http",
        code,
        status,
        message: "Unsafe server detail.",
    });
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

function historyRecorder() {
    return recorder();
}

function startReadingRecorder() {
    const opens = [];
    let destroys = 0;

    return {
        opens,
        get destroys() {
            return destroys;
        },
        factory() {
            return {
                open(options) {
                    opens.push(options);
                    return { state: "open" };
                },
                destroy() {
                    destroys += 1;
                },
            };
        },
    };
}

function endReadingRecorder() {
    return startReadingRecorder();
}

function privateNotesRecorder() {
    const creations = [];

    return {
        creations,
        factory(options) {
            const controller = {
                dirty: false,
                destroyed: false,
                loads: 0,
                renders: 0,
                load() {
                    this.loads += 1;
                    return Promise.resolve(true);
                },
                render() {
                    this.renders += 1;
                },
                isDirty() {
                    return this.dirty;
                },
                guardNavigation(action) {
                    return Promise.resolve(action());
                },
                destroy() {
                    this.destroyed = true;
                },
            };
            creations.push({ options, controller });
            return controller;
        },
    };
}

function createApp({
    url,
    get,
    historyGet = async () => ({ items: [], next_cursor: null }),
    post = async () => {
        throw new Error("Unexpected POST request.");
    },
    renders,
    detailRenders = recorder(),
    endReadingRenders = endReadingRecorder(),
    historyRenders = historyRecorder(),
    privateNotes = privateNotesRecorder(),
    startReadingRenders = startReadingRecorder(),
}) {
    const browser = browserDouble(url);
    const app = createLibraryApp(mount(), {
        apiFactory() {
            return {
                get(path, options) {
                    return path.includes("/reading-history?")
                        ? historyGet(path, options)
                        : get(path, options);
                },
                post,
            };
        },
        historyImpl: browser.history,
        locationImpl: browser.location,
        eventTarget: browser.eventTarget,
        viewFactory: renders.factory,
        detailViewFactory: detailRenders.factory,
        endReadingViewFactory: endReadingRenders.factory,
        readingHistoryViewFactory: historyRenders.factory,
        privateNotesControllerFactory: privateNotes.factory,
        startReadingViewFactory: startReadingRenders.factory,
    });

    return {
        app,
        browser,
        detailRenders,
        endReadingRenders,
        historyRenders,
        privateNotes,
        startReadingRenders,
        submitEndReading(intent) {
            detailRenders.renders.at(-1).actions.endReading({ focus() {} });
            return endReadingRenders.opens.at(-1).submit(intent);
        },
    };
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

test("a direct Item URL dispatches only the encoded detail contract", async () => {
    const selected = library("library/one", { designated: true });
    const requests = [];
    const renders = recorder();
    const detailRenders = recorder();
    const { app, privateNotes } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library%2Fone&item_id=item%2Fone",
        renders,
        detailRenders,
        async get(path, options) {
            requests.push([path, options.signal]);

            return path === "me/libraries"
                ? { libraries: [selected] }
                : detail(selected, "item/one");
        },
    });

    await app.start();

    assert.deepEqual(requests.map(([path]) => path), [
        "me/libraries",
        "libraries/library%2Fone/items/item%2Fone",
    ]);
    assert.ok(requests.every(([, signal]) => signal instanceof AbortSignal));
    assert.deepEqual(
        renders.renders.map(({ model }) => model.state),
        ["library-loading"]
    );
    assert.deepEqual(
        detailRenders.renders.map(({ model }) => model.state),
        ["detail-loading", "detail"]
    );
    assert.equal(detailRenders.renders.at(-1).model.detail.item_id, "item/one");
    assert.equal(privateNotes.creations.length, 1);
    assert.equal(
        privateNotes.creations[0].options.workId,
        "work-item/one"
    );
    assert.equal(privateNotes.creations[0].controller.loads, 1);
    assert.equal(
        detailRenders.renders.at(-1).model.backUrl,
        "https://example.test/mijn-bibliotheek/?library_id=library%2Fone"
    );
});

test("dirty popstate restores the rendered route and applies the target once after discard", async () => {
    const selected = library("library-1", { designated: true });
    const renders = recorder();
    const privateNotes = privateNotesRecorder();
    const { app, browser } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-1&item_id=item-1",
        renders,
        privateNotes,
        async get(path) {
            return path === "me/libraries"
                ? { libraries: [selected] }
                : path === "libraries/library-1/items/item-1"
                    ? detail(selected, "item-1")
                    : overview(selected, [item("item-1")]);
        },
    });
    await app.start();
    const notesController = privateNotes.creations[0].controller;
    let discardAction = null;
    notesController.dirty = true;
    notesController.guardNavigation = (action) => {
        discardAction = action;
        return Promise.resolve(false);
    };
    browser.location.href = "https://example.test/mijn-bibliotheek/"
        + "?library_id=library-1";
    browser.listeners.get("popstate")();
    await app.whenIdle();

    assert.equal(
        browser.location.href,
        "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-1&item_id=item-1"
    );
    assert.equal(typeof discardAction, "function");
    assert.equal(browser.historyCalls.filter(([kind]) => kind === "push").length, 0);

    await discardAction();
    assert.equal(
        browser.location.href,
        "https://example.test/mijn-bibliotheek/?library_id=library-1"
    );
    assert.equal(browser.historyCalls.filter(([kind]) => kind === "push").length, 0);
    assert.equal(renders.renders.at(-1).model.state, "overview");
});

test("overview and detail actions push canonical routes and rebuild fresh", async () => {
    const selected = library("library-1", { designated: true });
    const requests = [];
    const renders = recorder();
    const detailRenders = recorder();
    const { app, browser } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-1",
        renders,
        detailRenders,
        async get(path) {
            requests.push(path);

            if (path === "me/libraries") {
                return { libraries: [selected] };
            }

            return path.endsWith("/item-1")
                ? detail(selected, "item-1")
                : overview(selected, [item("item-1")]);
        },
    });

    await app.start();
    await renders.renders.at(-1).actions.openItem("item-1");
    await detailRenders.renders.at(-1).actions.backToOverview();

    assert.deepEqual(browser.historyCalls, [[
        "push",
        null,
        "",
        "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-1&item_id=item-1",
    ], [
        "push",
        null,
        "",
        "https://example.test/mijn-bibliotheek/?library_id=library-1",
    ]]);
    assert.deepEqual(requests, [
        "me/libraries",
        "libraries/library-1/items",
        "me/libraries",
        "libraries/library-1/items/item-1",
        "me/libraries",
        "libraries/library-1/items",
    ]);
    assert.equal(renders.renders.at(-1).model.state, "overview");
});

test("Item detail strictly validates active ReadingRound and end capability", async (t) => {
    const selected = library("library-1", { designated: true });
    const cases = [{
        name: "null active round",
        payload: detail(selected, "item-1"),
        expectedState: "detail",
    }, {
        name: "valid exact active round",
        payload: activeDetail(selected, "item-1"),
        expectedState: "detail",
    }, {
        name: "active round may be present while end is false",
        payload: activeDetail(selected, "item-1", activeRound(
            "round-legacy",
            3,
            null
        ), {
            capabilities: {
                view_item: true,
                start_reading: false,
                end_reading: false,
            },
        }),
        expectedState: "detail",
    }, {
        name: "malformed reading_round_id",
        payload: activeDetail(selected, "item-1", activeRound("")),
        expectedState: "request-error",
    }, {
        name: "malformed version",
        payload: activeDetail(selected, "item-1", activeRound(
            "round-1",
            "1"
        )),
        expectedState: "request-error",
    }, {
        name: "malformed started_on",
        payload: activeDetail(selected, "item-1", activeRound(
            "round-1",
            1,
            { year: 2026, month: 2, day: 30 }
        )),
        expectedState: "request-error",
    }, {
        name: "malformed end_reading",
        payload: activeDetail(selected, "item-1", activeRound(), {
            capabilities: {
                view_item: true,
                start_reading: false,
                end_reading: "yes",
            },
        }),
        expectedState: "request-error",
    }, {
        name: "end true without active round",
        payload: detail(selected, "item-1", {
            capabilities: {
                view_item: true,
                start_reading: false,
                end_reading: true,
            },
        }),
        expectedState: "request-error",
    }, {
        name: "active round rejects non-allowlisted fields",
        payload: activeDetail(selected, "item-1", {
            ...activeRound(),
            user_id: "other",
        }),
        expectedState: "request-error",
    }];

    for (const fixture of cases) {
        await t.test(fixture.name, async () => {
            const renders = recorder();
            const detailRenders = recorder();
            const { app } = createApp({
                url: "https://example.test/mijn-bibliotheek/"
                    + "?library_id=library-1&item_id=item-1",
                renders,
                detailRenders,
                async get(path) {
                    return path === "me/libraries"
                        ? { libraries: [selected] }
                        : fixture.payload;
                },
            });

            await app.start();
            const lastModel = fixture.expectedState === "detail"
                ? detailRenders.renders.at(-1).model
                : renders.renders.at(-1).model;
            assert.equal(lastModel.state, fixture.expectedState);
        });
    }
});

test("Start Reading posts the exact contract then renders only reread truth", async () => {
    const selected = library("library/one", { designated: true });
    const renders = recorder();
    const detailRenders = recorder();
    const postRequests = [];
    const acknowledgements = [];
    let detailRequests = 0;
    let resolveRefresh;
    const refresh = new Promise((resolve) => {
        resolveRefresh = resolve;
    });
    const { app, startReadingRenders } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library%2Fone&item_id=item%2Fone",
        renders,
        detailRenders,
        async get(path) {
            if (path === "me/libraries") {
                return { libraries: [selected] };
            }

            detailRequests += 1;
            return detailRequests === 1
                ? detail(selected, "item/one")
                : refresh;
        },
        async post(path, body, options) {
            postRequests.push([path, structuredClone(body), options.signal]);
            return startedRound("item/one");
        },
    });

    await app.start();
    const opener = { focus() {} };
    detailRenders.renders.at(-1).actions.startReading(opener);
    const submit = startReadingRenders.opens[0].submit(
        "2026-08-28",
        { acknowledge(message) { acknowledgements.push(message); } }
    );
    await waitFor(() => acknowledgements.length === 1);

    assert.deepEqual(postRequests.map(([path, body]) => [path, body]), [[
        "libraries/library%2Fone/items/item%2Fone/reading-rounds",
        { started_on: "2026-08-28" },
    ]]);
    assert.ok(postRequests[0][2] instanceof AbortSignal);
    assert.deepEqual(acknowledgements, [
        "Lezen is gestart. Leesstatus bijwerken.",
    ]);
    assert.equal(detailRenders.renders.at(-1).model.detail.reading.status,
        "not_read");
    assert.equal(detailRenders.renders.at(-1).model.notice, null);

    resolveRefresh(detail(selected, "item/one", {
        reading: {
            status: "reading",
            active_rounds: 1,
            completed_rounds: 0,
            stopped_rounds: 0,
            historical_completed_rounds: 0,
        },
        capabilities: {
            view_item: true,
            start_reading: false,
            end_reading: false,
        },
    }));
    assert.deepEqual(await submit, { state: "reconciled" });
    assert.equal(detailRequests, 2);
    assert.equal(detailRenders.renders.at(-1).model.detail.reading.status,
        "reading");
    assert.equal(detailRenders.renders.at(-1).model.notice,
        "Lezen is gestart.");
    assert.equal(detailRenders.renders.at(-1).model.focusReading, true);
});

test("Start Reading maps validation, nonce, auth and network without retry", async () => {
    const selected = library("library-1", { designated: true });
    const fixtures = [{
        error: new BiblioApiError({
            kind: "http",
            code: "biblio_invalid_field_syntax",
            status: 400,
            message: "Unsafe parser detail.",
        }),
        expected: "validation-error",
    }, {
        error: new BiblioApiError({
            kind: "http",
            code: "biblio_validation_failed",
            status: 422,
            message: "Unsafe validation detail.",
        }),
        expected: "validation-error",
    }, {
        error: new BiblioApiError({
            kind: "http",
            code: "rest_cookie_invalid_nonce",
            status: 403,
            message: "Cookie nonce is invalid.",
        }),
        expected: "session-refresh",
    }, {
        error: new BiblioApiError({
            kind: "http",
            code: "biblio_authentication_required",
            status: 401,
            message: "Authentication is required.",
        }),
        expected: "authentication-required",
    }, {
        error: new BiblioApiError({
            kind: "network",
            code: "biblio_ui_network_error",
            status: null,
            message: "Network internals.",
        }),
        expected: "retryable",
    }, {
        error: new BiblioApiError({
            kind: "http",
            code: "biblio_internal_error",
            status: 500,
            message: "Internal server detail.",
        }),
        expected: "retryable",
    }, {
        error: new BiblioApiError({
            kind: "http",
            code: "biblio_core_unavailable",
            status: 503,
            message: "Temporary server internals.",
        }),
        expected: "retryable",
    }];

    for (const fixture of fixtures) {
        const renders = recorder();
        const detailRenders = recorder();
        let posts = 0;
        let detailGets = 0;
        const { app, startReadingRenders } = createApp({
            url: "https://example.test/mijn-bibliotheek/"
                + "?library_id=library-1&item_id=item-1",
            renders,
            detailRenders,
            async get(path) {
                if (path === "me/libraries") {
                    return { libraries: [selected] };
                }

                detailGets += 1;
                return detail(selected, "item-1");
            },
            async post() {
                posts += 1;
                throw fixture.error;
            },
        });

        await app.start();
        detailRenders.renders.at(-1).actions.startReading({ focus() {} });
        const outcome = await startReadingRenders.opens[0].submit(
            "2026-08-28",
            { acknowledge() { throw new Error("Must not reconcile."); } }
        );

        assert.deepEqual(outcome, { state: fixture.expected });
        assert.equal(posts, 1);
        assert.equal(detailGets, 1);
        assert.doesNotMatch(JSON.stringify(outcome),
            /Unsafe|Cookie|Authentication|Network/);
    }
});

test("active-source conflict announces change and rereads authoritative detail", async () => {
    const selected = library("library-1", { designated: true });
    const renders = recorder();
    const detailRenders = recorder();
    const acknowledgements = [];
    let detailGets = 0;
    const conflict = new BiblioApiError({
        kind: "http",
        code: "biblio_reading_round_already_active_for_source",
        status: 409,
        message: "Conflict internals.",
    });
    const { app, startReadingRenders } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-1&item_id=item-1",
        renders,
        detailRenders,
        async get(path) {
            if (path === "me/libraries") {
                return { libraries: [selected] };
            }

            detailGets += 1;
            return detail(selected, "item-1", detailGets === 1 ? {} : {
                reading: {
                    status: "reading",
                    active_rounds: 1,
                    completed_rounds: 0,
                    stopped_rounds: 0,
                    historical_completed_rounds: 0,
                },
                capabilities: {
                    view_item: true,
                    start_reading: false,
                    end_reading: false,
                },
            });
        },
        async post() {
            throw conflict;
        },
    });

    await app.start();
    detailRenders.renders.at(-1).actions.startReading({ focus() {} });
    const outcome = await startReadingRenders.opens[0].submit(
        "2026-08-28",
        { acknowledge(message) { acknowledgements.push(message); } }
    );

    assert.deepEqual(outcome, { state: "reconciled" });
    assert.deepEqual(acknowledgements, [
        "De leesstatus is gewijzigd. Leesstatus bijwerken.",
    ]);
    assert.equal(detailGets, 2);
    assert.equal(detailRenders.renders.at(-1).model.detail.reading.status,
        "reading");
    assert.equal(detailRenders.renders.at(-1).model.notice,
        "De leesstatus is gewijzigd.");
});

test("mutation 404 refreshes to the non-enumerating Item state", async () => {
    const selected = library("library-1", { designated: true });
    const renders = recorder();
    const detailRenders = recorder();
    let detailGets = 0;
    const unavailable = new BiblioApiError({
        kind: "http",
        code: "biblio_resource_not_available",
        status: 404,
        message: "Foreign Item detail.",
    });
    const { app, startReadingRenders } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-1&item_id=item-1",
        renders,
        detailRenders,
        async get(path) {
            if (path === "me/libraries") {
                return { libraries: [selected] };
            }

            detailGets += 1;

            if (detailGets > 1) {
                throw unavailable;
            }

            return detail(selected, "item-1");
        },
        async post() {
            throw unavailable;
        },
    });

    await app.start();
    detailRenders.renders.at(-1).actions.startReading({ focus() {} });
    const outcome = await startReadingRenders.opens[0].submit(
        "2026-08-28",
        { acknowledge() {} }
    );

    assert.deepEqual(outcome, { state: "reconciled" });
    assert.equal(detailGets, 2);
    assert.equal(detailRenders.renders.at(-1).model.state,
        "item-unavailable");
    assert.doesNotMatch(
        JSON.stringify(detailRenders.renders.map(({ model }) => model)),
        /Foreign Item detail/
    );
});

test("POST success plus reread failure never repeats mutation or claims refresh", async () => {
    const selected = library("library-1", { designated: true });
    const renders = recorder();
    const detailRenders = recorder();
    let posts = 0;
    let detailGets = 0;
    const temporary = new BiblioApiError({
        kind: "http",
        code: "biblio_core_unavailable",
        status: 503,
        message: "Server internals.",
    });
    const { app, startReadingRenders } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-1&item_id=item-1",
        renders,
        detailRenders,
        async get(path) {
            if (path === "me/libraries") {
                return { libraries: [selected] };
            }

            detailGets += 1;

            if (detailGets > 1) {
                throw temporary;
            }

            return detail(selected, "item-1");
        },
        async post() {
            posts += 1;
            return startedRound("item-1");
        },
    });

    await app.start();
    detailRenders.renders.at(-1).actions.startReading({ focus() {} });
    const outcome = await startReadingRenders.opens[0].submit(
        "2026-08-28",
        { acknowledge() {} }
    );

    assert.deepEqual(outcome, {
        state: "refresh-failed",
        message: "Lezen is gestart, maar de actuele pagina kon niet worden vernieuwd.",
    });
    assert.equal(posts, 1);
    assert.equal(detailGets, 2);
    assert.equal(detailRenders.renders.at(-1).model.detail.reading.status,
        "not_read");
});

test("route change aborts mutation and stale success cannot trigger reread", async () => {
    const selected = library("library-1", { designated: true });
    const renders = recorder();
    const detailRenders = recorder();
    let resolvePost;
    let mutationSignal;
    let detailGets = 0;
    const pendingPost = new Promise((resolve) => {
        resolvePost = resolve;
    });
    const { app, browser, startReadingRenders } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-1&item_id=item-1",
        renders,
        detailRenders,
        async get(path) {
            if (path === "me/libraries") {
                return { libraries: [selected] };
            }

            if (path === "libraries/library-1/items") {
                return overview(selected, [item("item-1")]);
            }

            detailGets += 1;
            return detail(selected, "item-1");
        },
        async post(path, body, options) {
            mutationSignal = options.signal;
            return pendingPost;
        },
    });

    await app.start();
    detailRenders.renders.at(-1).actions.startReading({ focus() {} });
    const mutation = startReadingRenders.opens[0].submit(
        "2026-08-28",
        { acknowledge() { throw new Error("Stale mutation acknowledged."); } }
    );
    await waitFor(() => mutationSignal instanceof AbortSignal);

    browser.location.href = "https://example.test/mijn-bibliotheek/"
        + "?library_id=library-1";
    browser.listeners.get("popstate")();
    await app.whenIdle();
    assert.equal(mutationSignal.aborted, true);

    resolvePost(startedRound("item-1"));
    assert.deepEqual(await mutation, { state: "aborted" });
    assert.equal(detailGets, 1);
    assert.equal(renders.renders.at(-1).model.state, "overview");
});

test("End Reading completed and stopped post server detail identity then reread", async (t) => {
    for (const outcome of ["completed", "stopped"]) {
        await t.test(outcome, async () => {
            const selected = library("library-1", { designated: true });
            const renders = recorder();
            const detailRenders = recorder();
            const posts = [];
            let detailGets = 0;
            const roundId = `round/${outcome}`;
            const finishedOn = outcome === "completed"
                ? "2026-08-29"
                : "2026-08-28";
            const reread = detail(selected, "item-1", {
                active_reading_round: null,
                capabilities: {
                    view_item: true,
                    start_reading: true,
                    end_reading: false,
                },
                reading: {
                    status: outcome === "completed" ? "read" : "not_read",
                    active_rounds: 0,
                    completed_rounds: outcome === "completed" ? 1 : 0,
                    stopped_rounds: outcome === "stopped" ? 1 : 0,
                    historical_completed_rounds: 0,
                },
            });
            const { app, submitEndReading } = createApp({
                url: "https://example.test/mijn-bibliotheek/"
                    + "?library_id=library-1&item_id=item-1",
                renders,
                detailRenders,
                async get(path) {
                    if (path === "me/libraries") {
                        return { libraries: [selected] };
                    }

                    detailGets += 1;
                    return detailGets === 1
                        ? activeDetail(
                            selected,
                            "item-1",
                            activeRound(roundId, 7)
                        )
                        : reread;
                },
                async post(path, body, options) {
                    posts.push([path, structuredClone(body), options.signal]);
                    return endedRound(roundId, outcome, finishedOn, 8);
                },
            });

            await app.start();
            const result = await submitEndReading({
                outcome,
                finishedOn,
            });

            assert.deepEqual(result, { state: "reconciled" });
            assert.deepEqual(posts.map(([path, body]) => [path, body]), [[
                `me/reading-rounds/round%2F${outcome}/end`,
                {
                    outcome,
                    finished_on: finishedOn,
                    expected_version: 7,
                },
            ]]);
            assert.ok(posts[0][2] instanceof AbortSignal);
            assert.equal(detailGets, 2);
            assert.deepEqual(
                detailRenders.renders.at(-1).model.detail,
                reread
            );
            assert.equal(
                detailRenders.renders.at(-1).model.notice,
                "De leesstatus is bijgewerkt."
            );
            assert.equal(
                detailRenders.renders.at(-1).model.focusReading,
                true
            );
        });
    }
});

test("End Reading rejects presentation-supplied identity and unavailable state", async () => {
    const selected = library("library-1", { designated: true });
    const invalidIntents = [{
        outcome: "done",
        finishedOn: "2026-08-29",
    }, {
        outcome: "completed",
        finishedOn: "2026-02-30",
    }, {
        outcome: "completed",
        finishedOn: "2026-08-29",
        user_id: "other",
    }, {
        outcome: "completed",
        finishedOn: "2026-08-29",
        reading_round_id: "caller-round",
        expected_version: 99,
    }];
    let posts = 0;
    const activeRenders = recorder();
    const { app: activeApp, submitEndReading: submitActive } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-1&item_id=item-1",
        renders: recorder(),
        detailRenders: activeRenders,
        async get(path) {
            return path === "me/libraries"
                ? { libraries: [selected] }
                : activeDetail(selected, "item-1");
        },
        async post() {
            posts += 1;
        },
    });
    await activeApp.start();

    for (const intent of invalidIntents) {
        assert.deepEqual(
            await submitActive(intent),
            { state: "validation-error" }
        );
    }

    const inactiveRenders = recorder();
    const { app: inactiveApp, submitEndReading: submitInactive } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-1&item_id=item-1",
        renders: recorder(),
        detailRenders: inactiveRenders,
        async get(path) {
            return path === "me/libraries"
                ? { libraries: [selected] }
                : detail(selected, "item-1");
        },
        async post() {
            posts += 1;
        },
    });
    await inactiveApp.start();
    assert.deepEqual(await submitInactive({
        outcome: "completed",
        finishedOn: "2026-08-29",
    }), { state: "unavailable" });
    assert.equal(posts, 0);
});

test("End Reading duplicate submit shares the mutation lock and sends one POST", async () => {
    const selected = library("library-1", { designated: true });
    const detailRenders = recorder();
    let resolvePost;
    let posts = 0;
    let detailGets = 0;
    const pendingPost = new Promise((resolve) => {
        resolvePost = resolve;
    });
    const { app, submitEndReading } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-1&item_id=item-1",
        renders: recorder(),
        detailRenders,
        async get(path) {
            if (path === "me/libraries") {
                return { libraries: [selected] };
            }

            detailGets += 1;
            return detailGets === 1
                ? activeDetail(selected, "item-1")
                : detail(selected, "item-1");
        },
        async post() {
            posts += 1;
            return pendingPost;
        },
    });
    await app.start();
    const intent = { outcome: "completed", finishedOn: "2026-08-29" };
    const first = submitEndReading(intent);
    await waitFor(() => posts === 1);
    const duplicate = await submitEndReading(intent);

    assert.deepEqual(duplicate, { state: "pending" });
    assert.equal(posts, 1);
    resolvePost(endedRound("round/active", "completed", "2026-08-29"));
    assert.deepEqual(await first, { state: "reconciled" });
    assert.equal(posts, 1);
    assert.equal(detailGets, 2);
});

test("End Reading 409 and 404 reconcile without retry or stale payload trust", async (t) => {
    const fixtures = [{
        name: "stale",
        error: apiError(409, "biblio_reading_round_stale"),
        rereadUnavailable: false,
    }, {
        name: "unavailable",
        error: apiError(404, "biblio_resource_not_available"),
        rereadUnavailable: true,
    }];

    for (const fixture of fixtures) {
        await t.test(fixture.name, async () => {
            const selected = library("library-1", { designated: true });
            const detailRenders = recorder();
            let posts = 0;
            let detailGets = 0;
            const { app, submitEndReading } = createApp({
                url: "https://example.test/mijn-bibliotheek/"
                    + "?library_id=library-1&item_id=item-1",
                renders: recorder(),
                detailRenders,
                async get(path) {
                    if (path === "me/libraries") {
                        return { libraries: [selected] };
                    }

                    detailGets += 1;

                    if (detailGets > 1 && fixture.rereadUnavailable) {
                        throw apiError(404, "biblio_resource_not_available");
                    }

                    return detailGets === 1
                        ? activeDetail(selected, "item-1")
                        : detail(selected, "item-1", {
                            reading: {
                                status: "read",
                                active_rounds: 0,
                                completed_rounds: 1,
                                stopped_rounds: 0,
                                historical_completed_rounds: 0,
                            },
                        });
                },
                async post() {
                    posts += 1;
                    throw fixture.error;
                },
            });
            await app.start();
            const result = await submitEndReading({
                outcome: "completed",
                finishedOn: "2026-08-29",
            });

            assert.deepEqual(result, { state: "reconciled" });
            assert.equal(posts, 1);
            assert.equal(detailGets, 2);
            assert.equal(
                detailRenders.renders.at(-1).model.state,
                fixture.rereadUnavailable ? "item-unavailable" : "detail"
            );
            assert.equal(
                detailRenders.renders.at(-1).model.focusHeading === true,
                fixture.rereadUnavailable
            );
            assert.doesNotMatch(
                JSON.stringify(detailRenders.renders.map(({ model }) => model)),
                /Unsafe server detail/
            );
        });
    }
});

test("End Reading normalizes non-reconciled errors without retry", async (t) => {
    const fixtures = [{
        status: 400,
        code: "biblio_invalid_field_syntax",
        expected: "validation-error",
    }, {
        status: 401,
        code: "biblio_authentication_required",
        expected: "authentication-required",
    }, {
        status: 403,
        code: "rest_cookie_invalid_nonce",
        expected: "session-refresh",
    }, {
        status: 422,
        code: "biblio_validation_failed",
        expected: "validation-error",
    }, {
        status: 503,
        code: "biblio_core_unavailable",
        expected: "service-unavailable",
    }, {
        status: 500,
        code: "biblio_internal_error",
        expected: "internal-error",
    }];

    for (const fixture of fixtures) {
        await t.test(String(fixture.status), async () => {
            const selected = library("library-1", { designated: true });
            const detailRenders = recorder();
            let posts = 0;
            let detailGets = 0;
            const { app, submitEndReading } = createApp({
                url: "https://example.test/mijn-bibliotheek/"
                    + "?library_id=library-1&item_id=item-1",
                renders: recorder(),
                detailRenders,
                async get(path) {
                    if (path === "me/libraries") {
                        return { libraries: [selected] };
                    }

                    detailGets += 1;
                    return activeDetail(selected, "item-1");
                },
                async post() {
                    posts += 1;
                    throw apiError(fixture.status, fixture.code);
                },
            });
            await app.start();
            const result = await submitEndReading({
                outcome: "completed",
                finishedOn: "2026-08-29",
            });

            assert.deepEqual(result, { state: fixture.expected });
            assert.equal(posts, 1);
            assert.equal(detailGets, 1);
            assert.doesNotMatch(JSON.stringify(result), /Unsafe/);
        });
    }
});

test("End Reading malformed success reconciles but reread failure stays uncertain", async (t) => {
    for (const rereadFails of [false, true]) {
        await t.test(rereadFails ? "reread failure" : "malformed success", async () => {
            const selected = library("library-1", { designated: true });
            const detailRenders = recorder();
            let posts = 0;
            let detailGets = 0;
            const { app, submitEndReading } = createApp({
                url: "https://example.test/mijn-bibliotheek/"
                    + "?library_id=library-1&item_id=item-1",
                renders: recorder(),
                detailRenders,
                async get(path) {
                    if (path === "me/libraries") {
                        return { libraries: [selected] };
                    }

                    detailGets += 1;

                    if (detailGets > 1 && rereadFails) {
                        throw apiError(503, "biblio_core_unavailable");
                    }

                    return detailGets === 1
                        ? activeDetail(selected, "item-1")
                        : detail(selected, "item-1");
                },
                async post() {
                    posts += 1;

                    return rereadFails
                        ? endedRound(
                            "round/active",
                            "completed",
                            "2026-08-29"
                        )
                        : endedRound(
                            "wrong-round",
                            "completed",
                            "2026-08-29"
                        );
                },
            });
            await app.start();
            const result = await submitEndReading({
                outcome: "completed",
                finishedOn: "2026-08-29",
            });

            assert.equal(posts, 1);
            assert.equal(detailGets, 2);

            if (rereadFails) {
                assert.deepEqual(result, {
                    state: "refresh-failed",
                    message: "De aanvraag is verwerkt, maar de actuele pagina kon niet worden vernieuwd.",
                });
                assert.equal(
                    detailRenders.renders.at(-1).model.detail
                        .active_reading_round.reading_round_id,
                    "round/active"
                );
            } else {
                assert.deepEqual(result, { state: "reconciled" });
                assert.equal(
                    detailRenders.renders.at(-1).model.detail
                        .active_reading_round,
                    null
                );
            }
        });
    }
});

test("End Reading navigation abort marks outcome unknown and cannot reread stale detail", async () => {
    const selected = library("library-1", { designated: true });
    const renders = recorder();
    const detailRenders = recorder();
    let resolvePost;
    let mutationSignal;
    let detailGets = 0;
    const pendingPost = new Promise((resolve) => {
        resolvePost = resolve;
    });
    const { app, browser, submitEndReading } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-1&item_id=item-1",
        renders,
        detailRenders,
        async get(path) {
            if (path === "me/libraries") {
                return { libraries: [selected] };
            }

            if (path === "libraries/library-1/items") {
                return overview(selected, [item("item-1")]);
            }

            detailGets += 1;
            return activeDetail(selected, "item-1");
        },
        async post(path, body, options) {
            mutationSignal = options.signal;
            return pendingPost;
        },
    });
    await app.start();
    const mutation = submitEndReading({
        outcome: "completed",
        finishedOn: "2026-08-29",
    });
    await waitFor(() => mutationSignal instanceof AbortSignal);

    browser.location.href = "https://example.test/mijn-bibliotheek/"
        + "?library_id=library-1";
    browser.listeners.get("popstate")();
    await app.whenIdle();
    assert.equal(mutationSignal.aborted, true);

    resolvePost(endedRound(
        "round/active",
        "completed",
        "2026-08-29"
    ));
    assert.deepEqual(await mutation, { state: "outcome-unknown" });
    assert.equal(detailGets, 1);
    assert.equal(renders.renders.at(-1).model.state, "overview");
});

test("Item 404 is non-enumerating while other detail errors stay generic", async () => {
    const selected = library("library-1", { designated: true });
    const unavailable = new BiblioApiError({
        kind: "http",
        code: "biblio_resource_not_available",
        status: 404,
        message: "A foreign title must not be displayed.",
    });
    const temporary = new BiblioApiError({
        kind: "http",
        code: "biblio_core_unavailable",
        status: 503,
        message: "Internal server detail.",
    });

    for (const [error, expectedState] of [
        [unavailable, "item-unavailable"],
        [temporary, "request-error"],
    ]) {
        const renders = recorder();
        const detailRenders = recorder();
        const { app } = createApp({
            url: "https://example.test/mijn-bibliotheek/"
                + "?library_id=library-1&item_id=foreign-item",
            renders,
            detailRenders,
            async get(path) {
                if (path === "me/libraries") {
                    return { libraries: [selected] };
                }

                throw error;
            },
        });

        await app.start();

        const state = expectedState === "item-unavailable"
            ? detailRenders.renders.at(-1).model.state
            : renders.renders.at(-1).model.state;
        assert.equal(state, expectedState);
        assert.doesNotMatch(
            JSON.stringify([
                ...renders.renders.map(({ model }) => model),
                ...detailRenders.renders.map(({ model }) => model),
            ]),
            /foreign title|Internal server detail/
        );
    }
});

test("an invalid URL Library with item_id never requests Item detail", async () => {
    const selected = library("library-1", { designated: true });
    const requests = [];
    const renders = recorder();
    const detailRenders = recorder();
    const { app } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=missing&item_id=item-1",
        renders,
        detailRenders,
        async get(path) {
            requests.push(path);
            return { libraries: [selected] };
        },
    });

    await app.start();

    assert.deepEqual(requests, ["me/libraries"]);
    assert.equal(renders.renders.at(-1).model.state, "library-unavailable");
    assert.equal(detailRenders.renders.length, 0);
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

test("popstate switches overview and detail from current URL with fresh context", async () => {
    const selected = library("library-1", { designated: true });
    const requests = [];
    const renders = recorder();
    const detailRenders = recorder();
    const { app, browser } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-1",
        renders,
        detailRenders,
        async get(path) {
            requests.push(path);

            if (path === "me/libraries") {
                return { libraries: [selected] };
            }

            return path.endsWith("/item-1")
                ? detail(selected, "item-1")
                : overview(selected, [item("item-1")]);
        },
    });

    await app.start();
    browser.location.href = "https://example.test/mijn-bibliotheek/"
        + "?library_id=library-1&item_id=item-1";
    browser.listeners.get("popstate")();
    await app.whenIdle();
    assert.equal(detailRenders.renders.at(-1).model.state, "detail");
    assert.equal(detailRenders.renders.at(-1).model.focusHeading, true);

    browser.location.href = "https://example.test/mijn-bibliotheek/"
        + "?library_id=library-1";
    browser.listeners.get("popstate")();
    await app.whenIdle();
    assert.equal(renders.renders.at(-1).model.state, "overview");
    assert.equal(renders.renders.at(-1).model.focusHeading, true);

    assert.deepEqual(requests, [
        "me/libraries",
        "libraries/library-1/items",
        "me/libraries",
        "libraries/library-1/items/item-1",
        "me/libraries",
        "libraries/library-1/items",
    ]);
});

test("popstate aborts stale detail and it cannot overwrite a newer overview", async () => {
    const selected = library("library-1", { designated: true });
    const requests = [];
    const renders = recorder();
    const detailRenders = recorder();
    let resolveDetail;
    const pendingDetail = new Promise((resolve) => {
        resolveDetail = resolve;
    });
    const { app, browser } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-1&item_id=stale-item",
        renders,
        detailRenders,
        async get(path, options) {
            requests.push([path, options.signal]);

            if (path === "me/libraries") {
                return { libraries: [selected] };
            }

            if (path.endsWith("/stale-item")) {
                return pendingDetail;
            }

            return overview(selected, [item("current-item")]);
        },
    });

    const staleRun = app.start();
    await waitFor(() => requests.some(([path]) => path.endsWith("/stale-item")));
    const staleSignal = requests.find(([path]) => (
        path.endsWith("/stale-item")
    ))[1];

    browser.location.href = "https://example.test/mijn-bibliotheek/"
        + "?library_id=library-1";
    browser.listeners.get("popstate")();
    await app.whenIdle();

    assert.equal(staleSignal.aborted, true);
    assert.equal(renders.renders.at(-1).model.state, "overview");
    assert.equal(renders.renders.at(-1).model.items[0].item_id, "current-item");

    resolveDetail(detail(selected, "stale-item"));
    await staleRun;

    assert.equal(renders.renders.at(-1).model.state, "overview");
    assert.notEqual(detailRenders.renders.at(-1).model.state, "detail");
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

test("Item detail renders before its independent history request resolves", async () => {
    const selected = library("library-history");
    const renders = recorder();
    const historyRenders = historyRecorder();
    const pendingHistory = deferred();
    const { app, detailRenders } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-history&item_id=item-history",
        renders,
        historyRenders,
        async get(path) {
            return path === "me/libraries"
                ? { libraries: [selected] }
                : detail(selected, "item-history");
        },
        historyGet(path) {
            assert.equal(
                path,
                "me/works/work-item-history/reading-history?limit=10"
            );
            return pendingHistory.promise;
        },
    });

    const start = app.start();
    await waitFor(() => historyRenders.renders.length >= 1);

    assert.equal(detailRenders.renders.at(-1).model.state, "detail");
    assert.equal(
        detailRenders.renders.at(-1).model.detail.item_id,
        "item-history"
    );
    assert.equal(
        historyRenders.renders.at(-1).model.state,
        "loading"
    );

    pendingHistory.resolve(historyPage([historyEntry()]));
    await start;

    assert.equal(historyRenders.renders.at(-1).model.state, "ready");
    assert.equal(historyRenders.renders.at(-1).model.items.length, 1);
});

test("history pagination uses one opaque cursor request and retries that cursor", async () => {
    const selected = library("library-pages");
    const renders = recorder();
    const historyRenders = historyRecorder();
    const pendingPage = deferred();
    const paths = [];
    let pageCall = 0;
    const { app } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-pages&item_id=item-pages",
        renders,
        historyRenders,
        async get(path) {
            return path === "me/libraries"
                ? { libraries: [selected] }
                : detail(selected, "item-pages");
        },
        historyGet(path) {
            paths.push(path);
            pageCall += 1;

            if (pageCall === 1) {
                return historyPage([historyEntry()], "opaque/next");
            }

            if (pageCall === 2) {
                return pendingPage.promise;
            }

            if (pageCall === 3) {
                throw apiError(503, "biblio_core_unavailable");
            }

            return historyPage([historyEntry({ outcome: "stopped" })]);
        },
    });

    await app.start();
    const firstLoad = historyRenders.renders.at(-1).actions.loadMore();
    const duplicate = historyRenders.renders.at(-1).actions.loadMore();
    assert.equal(await duplicate, false);
    assert.equal(paths.length, 2);

    pendingPage.resolve(historyPage([
        historyEntry({ finished_on: { year: 2024, month: null, day: null } }),
    ], "opaque/retry"));
    assert.equal(await firstLoad, true);
    assert.equal(historyRenders.renders.at(-1).model.items.length, 2);
    assert.equal(historyRenders.renders.at(-1).model.nextCursor, "opaque/retry");

    assert.equal(
        await historyRenders.renders.at(-1).actions.loadMore(),
        false
    );
    const failed = historyRenders.renders.at(-1);
    assert.equal(failed.model.loadMoreError, true);
    assert.equal(failed.model.focusAfterPaginationError, true);
    assert.equal(failed.model.items.length, 2);
    assert.equal(
        await failed.actions.retryLoadMore(),
        true
    );
    assert.deepEqual(paths.slice(2), [
        "me/works/work-item-pages/reading-history?limit=10"
            + "&cursor=opaque%2Fretry",
        "me/works/work-item-pages/reading-history?limit=10"
            + "&cursor=opaque%2Fretry",
    ]);
    assert.equal(historyRenders.renders.at(-1).model.items.length, 3);
    assert.equal(historyRenders.renders.at(-1).model.nextCursor, null);
    assert.equal(historyRenders.renders.at(-1).model.focusAfterPagination, true);
    assert.equal(
        historyRenders.renders.at(-1).model.focusAfterPaginationError,
        false
    );
});

test("navigation aborts old Work history and ignores its late response", async () => {
    const selected = library("library-switch");
    const renders = recorder();
    const historyRenders = historyRecorder();
    const oldHistory = deferred();
    let oldSignal;
    const { app, browser } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-switch&item_id=item-one",
        renders,
        historyRenders,
        async get(path) {
            if (path === "me/libraries") {
                return { libraries: [selected] };
            }

            return path.endsWith("item-one")
                ? detail(selected, "item-one")
                : detail(selected, "item-two");
        },
        historyGet(path, options) {
            if (path.includes("work-item-one")) {
                oldSignal = options.signal;
                return oldHistory.promise;
            }

            return historyPage([historyEntry({
                finished_on: { year: 2026, month: null, day: null },
            })]);
        },
    });

    const oldRun = app.start();
    await waitFor(() => oldSignal !== undefined);
    browser.location.href = "https://example.test/mijn-bibliotheek/"
        + "?library_id=library-switch&item_id=item-two";
    browser.listeners.get("popstate")();
    await app.whenIdle();

    assert.equal(oldSignal.aborted, true);
    assert.equal(
        historyRenders.renders.at(-1).model.items[0].finished_on.year,
        2026
    );

    oldHistory.resolve(historyPage([historyEntry({
        finished_on: { year: 1999, month: null, day: null },
    })]));
    await oldRun;
    assert.equal(
        historyRenders.renders.at(-1).model.items[0].finished_on.year,
        2026
    );
});

test("Start Reading preserves history without a duplicate history GET", async () => {
    const selected = library("library-start-history");
    const renders = recorder();
    const historyRenders = historyRecorder();
    let detailReads = 0;
    let historyReads = 0;
    const {
        app,
        detailRenders,
        privateNotes,
        startReadingRenders,
    } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-start-history&item_id=item-start-history",
        renders,
        historyRenders,
        async get(path) {
            if (path === "me/libraries") {
                return { libraries: [selected] };
            }

            detailReads += 1;
            return detailReads === 1
                ? detail(selected, "item-start-history")
                : activeDetail(selected, "item-start-history");
        },
        historyGet() {
            historyReads += 1;
            return historyPage([historyEntry()]);
        },
        async post() {
            return startedRound("item-start-history");
        },
    });

    await app.start();
    const noteRendersBeforeMutation = privateNotes.creations[0].controller.renders;
    detailRenders.renders.at(-1).actions.startReading({ focus() {} });
    const result = await startReadingRenders.opens.at(-1).submit(
        "2026-08-28",
        { acknowledge() {} }
    );

    assert.deepEqual(result, { state: "reconciled" });
    assert.equal(historyReads, 1);
    assert.equal(historyRenders.renders.at(-1).model.items.length, 1);
    assert.equal(
        privateNotes.creations[0].controller.renders,
        noteRendersBeforeMutation + 1
    );
    assert.equal(privateNotes.creations[0].controller.loads, 1);
});

test("End Reading rereads detail then replaces history from page one only", async () => {
    const selected = library("library-end-history");
    const renders = recorder();
    const historyRenders = historyRecorder();
    let detailReads = 0;
    const historyPaths = [];
    let historyReads = 0;
    let posts = 0;
    const round = activeRound("round-history", 4);
    const newlyEnded = historyEntry({
        finished_on: { year: 2026, month: 8, day: 30 },
    });
    const { app, submitEndReading } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-end-history&item_id=item-end-history",
        renders,
        historyRenders,
        async get(path) {
            if (path === "me/libraries") {
                return { libraries: [selected] };
            }

            detailReads += 1;
            return detailReads === 1
                ? activeDetail(selected, "item-end-history", round)
                : detail(selected, "item-end-history", {
                    reading: {
                        status: "read",
                        active_rounds: 0,
                        completed_rounds: 1,
                        stopped_rounds: 0,
                        historical_completed_rounds: 0,
                    },
                    capabilities: {
                        view_item: true,
                        start_reading: true,
                        end_reading: false,
                    },
                });
        },
        historyGet(path) {
            historyPaths.push(path);
            historyReads += 1;

            return historyReads === 1
                ? historyPage([historyEntry()], "old-cursor")
                : historyPage([newlyEnded, historyEntry()], "new-cursor");
        },
        async post() {
            posts += 1;
            return endedRound("round-history", "completed", "2026-08-30", 5);
        },
    });

    await app.start();
    const result = await submitEndReading({
        outcome: "completed",
        finishedOn: "2026-08-30",
    });

    assert.deepEqual(result, { state: "reconciled" });
    assert.equal(posts, 1);
    assert.deepEqual(historyPaths, [
        "me/works/work-item-end-history/reading-history?limit=10",
        "me/works/work-item-end-history/reading-history?limit=10",
    ]);
    assert.deepEqual(
        historyRenders.renders.at(-1).model.items,
        [newlyEnded, historyEntry()]
    );
    assert.equal(historyRenders.renders.at(-1).model.nextCursor, "new-cursor");
    assert.equal(
        historyRenders.renders.at(-1).model.focusAfterPagination,
        false
    );
    assert.equal(
        historyRenders.renders.at(-1).model.focusAfterPaginationError,
        false
    );
});

test("history refresh failure after End stays local and never repeats mutation", async () => {
    const selected = library("library-refresh-error");
    const renders = recorder();
    const historyRenders = historyRecorder();
    let detailReads = 0;
    let historyReads = 0;
    let posts = 0;
    const { app, submitEndReading } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-refresh-error&item_id=item-refresh-error",
        renders,
        historyRenders,
        async get(path) {
            if (path === "me/libraries") {
                return { libraries: [selected] };
            }

            detailReads += 1;
            return detailReads === 1
                ? activeDetail(selected, "item-refresh-error", activeRound("round-refresh"))
                : detail(selected, "item-refresh-error");
        },
        historyGet() {
            historyReads += 1;

            if (historyReads === 1) {
                return historyPage([historyEntry()]);
            }

            if (historyReads === 2) {
                throw apiError(500, "biblio_internal_error");
            }

            return historyPage([historyEntry({ outcome: "stopped" })]);
        },
        async post() {
            posts += 1;
            return endedRound("round-refresh", "stopped", "2026-08-30");
        },
    });

    await app.start();
    assert.deepEqual(await submitEndReading({
        outcome: "stopped",
        finishedOn: "2026-08-30",
    }), { state: "reconciled" });

    const failed = historyRenders.renders.at(-1);
    assert.equal(failed.model.state, "ready");
    assert.equal(failed.model.refreshError, true);
    assert.equal(failed.model.items.length, 1);
    assert.equal(failed.model.focusAfterPagination, false);
    assert.equal(failed.model.focusAfterPaginationError, false);
    assert.equal(posts, 1);

    assert.equal(await failed.actions.retry(), true);
    assert.equal(posts, 1);
    assert.equal(historyReads, 3);
    assert.equal(historyRenders.renders.at(-1).model.refreshError, false);
});

test("deep-link history is requested fresh on every runtime start", async () => {
    const selected = library("library-deep");
    let historyReads = 0;

    for (let load = 0; load < 2; load += 1) {
        const renders = recorder();
        const { app } = createApp({
            url: "https://example.test/mijn-bibliotheek/"
                + "?library_id=library-deep&item_id=item-deep",
            renders,
            async get(path) {
                return path === "me/libraries"
                    ? { libraries: [selected] }
                    : detail(selected, "item-deep");
            },
            historyGet() {
                historyReads += 1;
                return historyPage([historyEntry()]);
            },
        });

        await app.start();
    }

    assert.equal(historyReads, 2);
});

test("malformed initial history fails locally and explicit retry recovers", async () => {
    const selected = library("library-history-retry");
    const renders = recorder();
    const historyRenders = historyRecorder();
    let historyReads = 0;
    const { app, detailRenders } = createApp({
        url: "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-history-retry&item_id=item-history-retry",
        renders,
        historyRenders,
        async get(path) {
            return path === "me/libraries"
                ? { libraries: [selected] }
                : detail(selected, "item-history-retry");
        },
        historyGet() {
            historyReads += 1;

            return historyReads === 1
                ? { items: [], next_cursor: null, unexpected: true }
                : historyPage([historyEntry()]);
        },
    });

    await app.start();
    const failed = historyRenders.renders.at(-1);
    assert.equal(failed.model.state, "error");
    assert.equal(failed.model.recovery, "retry");
    assert.equal(detailRenders.renders.at(-1).model.state, "detail");

    assert.equal(await failed.actions.retry(), true);
    assert.equal(historyReads, 2);
    assert.equal(historyRenders.renders.at(-1).model.state, "ready");
});

test("initial history errors map to local authentication, session or retry recovery", async (t) => {
    const fixtures = [{
        status: 401,
        code: "biblio_authentication_required",
        expected: "authentication",
    }, {
        status: 403,
        code: "rest_cookie_invalid_nonce",
        expected: "session",
    }, {
        status: 503,
        code: "biblio_core_unavailable",
        expected: "retry",
    }, {
        status: 500,
        code: "biblio_internal_error",
        expected: "retry",
    }];

    for (const fixture of fixtures) {
        await t.test(String(fixture.status), async () => {
            const selected = library(`library-history-${fixture.status}`);
            const renders = recorder();
            const historyRenders = historyRecorder();
            const { app, detailRenders } = createApp({
                url: "https://example.test/mijn-bibliotheek/"
                    + `?library_id=${selected.library_id}`
                    + `&item_id=item-history-${fixture.status}`,
                renders,
                historyRenders,
                async get(path) {
                    return path === "me/libraries"
                        ? { libraries: [selected] }
                        : detail(selected, `item-history-${fixture.status}`);
                },
                historyGet() {
                    throw apiError(fixture.status, fixture.code);
                },
            });

            await app.start();
            assert.equal(detailRenders.renders.at(-1).model.state, "detail");
            assert.equal(historyRenders.renders.at(-1).model.state, "error");
            assert.equal(
                historyRenders.renders.at(-1).model.recovery,
                fixture.expected
            );
        });
    }
});
