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
    ["biblio-ui/route-state", "route-state.js"],
    ["biblio-ui/start-reading-view", "start-reading-view.js"],
]) {
    appSource = appSource.replaceAll(
        `"${moduleId}"`,
        JSON.stringify(new URL(`../../assets/js/${file}`, import.meta.url).href)
    );
}

const { createLibraryApp, readMountConfig } = await import(
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

    return {
        location,
        historyCalls,
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
            addEventListener() {},
            removeEventListener() {},
        },
    };
}

function library(id, designated = false) {
    return {
        library_id: id,
        name: `Library ${id}`,
        type: "private",
        status: "active",
        designated_personal: designated,
        capabilities: {},
    };
}

test("mount configuration is the sole API bootstrap source", () => {
    assert.deepEqual(readMountConfig(mount()), {
        restRoot: "https://example.test/wp-json/biblio/v1/",
        restNonce: "rest-nonce",
        overviewUrl: "https://example.test/mijn-bibliotheek/",
        loginUrl: "https://example.test/wp-login.php?redirect_to=library",
    });
});

test("Library bootstrap uses only me/libraries and canonicalizes one Library", async () => {
    const apiConfigurations = [];
    const requests = [];
    const browser = browserDouble(
        "https://example.test/mijn-bibliotheek/?item_id=item-1"
    );
    const onlyLibrary = library("library-1");
    const app = createLibraryApp(mount(), {
        apiFactory(config) {
            apiConfigurations.push(config);
            return {
                async get(path, options) {
                    requests.push([path, options]);
                    return { libraries: [onlyLibrary] };
                },
            };
        },
        historyImpl: browser.history,
        locationImpl: browser.location,
        eventTarget: browser.eventTarget,
    });

    assert.deepEqual(await app.loadLibraryContext(), {
        state: "selected",
        library: onlyLibrary,
        canonicalize: true,
    });
    assert.deepEqual(apiConfigurations, [{
        restRoot: "https://example.test/wp-json/biblio/v1/",
        restNonce: "rest-nonce",
    }]);
    assert.deepEqual(requests, [["me/libraries", { signal: undefined }]]);
    assert.deepEqual(browser.historyCalls, [[
        "replace",
        null,
        "",
        "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-1&item_id=item-1",
    ]]);
});

test("an unavailable URL Library is not repaired or replaced", async () => {
    const requests = [];
    const browser = browserDouble(
        "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-missing&item_id=item-1"
    );
    const app = createLibraryApp(mount(), {
        apiFactory() {
            return {
                async get(path) {
                    requests.push(path);
                    return { libraries: [library("library-personal", true)] };
                },
            };
        },
        historyImpl: browser.history,
        locationImpl: browser.location,
        eventTarget: browser.eventTarget,
    });

    assert.deepEqual(await app.loadLibraryContext(), {
        state: "unavailable",
        requestedLibraryId: "library-missing",
    });
    assert.deepEqual(requests, ["me/libraries"]);
    assert.deepEqual(browser.historyCalls, []);
    assert.equal(
        browser.location.href,
        "https://example.test/mijn-bibliotheek/"
            + "?library_id=library-missing&item_id=item-1"
    );
});

test("abort is control flow while other transport errors remain unchanged", async () => {
    const browser = browserDouble("https://example.test/mijn-bibliotheek/");
    const aborted = new BiblioApiError({
        kind: "aborted",
        code: "biblio_ui_request_aborted",
        status: null,
        message: "The Biblio request was aborted.",
    });
    const serverError = new BiblioApiError({
        kind: "http",
        code: "biblio_core_unavailable",
        status: 503,
        message: "Biblio is temporarily unavailable.",
    });
    let error = aborted;
    const app = createLibraryApp(mount(), {
        apiFactory() {
            return {
                async get() {
                    throw error;
                },
            };
        },
        historyImpl: browser.history,
        locationImpl: browser.location,
        eventTarget: browser.eventTarget,
    });

    assert.deepEqual(
        await app.loadLibraryContext(),
        { state: "aborted" }
    );
    error = serverError;
    await assert.rejects(app.loadLibraryContext(), (received) => {
        assert.equal(received, serverError);
        return true;
    });
    assert.deepEqual(browser.historyCalls, []);
});

test("step 8 modules contain no storage or later-slice operations", async () => {
    const sources = await Promise.all([
        "app.js",
        "route-state.js",
        "library-state.js",
        "overview-view.js",
        "detail-view.js",
        "end-reading-view.js",
        "start-reading-view.js",
    ].map((file) => readFile(
        new URL(`../../assets/js/${file}`, import.meta.url),
        "utf8"
    )));
    const source = sources.join("\n");

    assert.doesNotMatch(
        source,
        /localStorage|sessionStorage|innerHTML|insertAdjacentHTML/
    );
    assert.doesNotMatch(
        source,
        /Next Reading|ratings|reviews|Elementor|Playwright/
    );
    assert.equal((source.match(/me\/libraries/g) ?? []).length, 1);
    assert.equal((source.match(/page_size/g) ?? []).length, 0);
});
