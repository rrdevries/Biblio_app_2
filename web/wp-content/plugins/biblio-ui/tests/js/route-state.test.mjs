import assert from "node:assert/strict";
import test from "node:test";

import {
    buildRouteUrl,
    createRouteController,
    readRouteState,
} from "../../assets/js/route-state.js";

test("route state reads opaque decoded identifiers and ignores other query data", () => {
    assert.deepEqual(
        readRouteState(
            "https://example.test/mijn-bibliotheek/"
                + "?library_id=library%2Fone&item_id=item+one&ignored=yes#detail"
        ),
        {
            libraryId: "library/one",
            itemId: "item one",
        }
    );
    assert.deepEqual(
        readRouteState("https://example.test/mijn-bibliotheek/"),
        { libraryId: null, itemId: null }
    );
    assert.deepEqual(
        readRouteState("https://example.test/mijn-bibliotheek/?library_id="),
        { libraryId: "", itemId: null }
    );
});

test("route URLs use only the canonical overview URL and encoded query state", () => {
    assert.equal(
        buildRouteUrl(
            "https://example.test/mijn-bibliotheek/?old=yes#old",
            { libraryId: "library/one", itemId: "item one" }
        ),
        "https://example.test/mijn-bibliotheek/"
            + "?library_id=library%2Fone&item_id=item+one"
    );
    assert.equal(
        buildRouteUrl("https://example.test/mijn-bibliotheek/"),
        "https://example.test/mijn-bibliotheek/"
    );
});

function browserDouble(initialUrl) {
    const location = { href: initialUrl };
    const calls = [];
    const listeners = new Map();
    const history = {
        pushState(state, title, url) {
            calls.push(["push", state, title, url]);
            location.href = url;
        },
        replaceState(state, title, url) {
            calls.push(["replace", state, title, url]);
            location.href = url;
        },
    };
    const eventTarget = {
        addEventListener(type, listener) {
            listeners.set(type, listener);
        },
        removeEventListener(type, listener) {
            if (listeners.get(type) === listener) {
                listeners.delete(type);
            }
        },
    };

    return { location, calls, listeners, history, eventTarget };
}

test("route controller uses URL-only push and replace navigation", () => {
    const browser = browserDouble("https://example.test/mijn-bibliotheek/");
    const routes = createRouteController({
        overviewUrl: "https://example.test/mijn-bibliotheek/",
        historyImpl: browser.history,
        locationImpl: browser.location,
        eventTarget: browser.eventTarget,
    });

    routes.push({ libraryId: "library-1", itemId: "item-1" });
    routes.replace({ libraryId: "library-2", itemId: null });

    assert.deepEqual(browser.calls, [
        [
            "push",
            null,
            "",
            "https://example.test/mijn-bibliotheek/"
                + "?library_id=library-1&item_id=item-1",
        ],
        [
            "replace",
            null,
            "",
            "https://example.test/mijn-bibliotheek/?library_id=library-2",
        ],
    ]);
    assert.deepEqual(routes.read(), { libraryId: "library-2", itemId: null });
});

test("popstate rereads the current URL and can be unsubscribed", () => {
    const browser = browserDouble("https://example.test/mijn-bibliotheek/");
    const routes = createRouteController({
        overviewUrl: "https://example.test/mijn-bibliotheek/",
        historyImpl: browser.history,
        locationImpl: browser.location,
        eventTarget: browser.eventTarget,
    });
    const received = [];
    const unsubscribe = routes.onPopState((state) => received.push(state));

    browser.location.href = "https://example.test/mijn-bibliotheek/"
        + "?library_id=library-1&item_id=item-1";
    browser.listeners.get("popstate")();

    assert.deepEqual(received, [{ libraryId: "library-1", itemId: "item-1" }]);
    unsubscribe();
    assert.equal(browser.listeners.has("popstate"), false);
});
