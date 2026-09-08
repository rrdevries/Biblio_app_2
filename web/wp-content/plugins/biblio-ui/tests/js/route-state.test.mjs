import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import { defaultCatalogQuery } from "../../assets/js/catalog-query.js";

const catalogQuerySource = await readFile(
    new URL("../../assets/js/catalog-query.js", import.meta.url),
    "utf8"
);
const routeSource = (await readFile(
    new URL("../../assets/js/route-state.js", import.meta.url),
    "utf8"
)).replace(
    '"biblio-ui/catalog-query"',
    JSON.stringify(`data:text/javascript;base64,${Buffer.from(catalogQuerySource).toString("base64")}`)
);
const {
    buildRouteUrl,
    createRouteController,
    readRouteState,
} = await import(`data:text/javascript;base64,${Buffer.from(routeSource).toString("base64")}`);

test("route state reads opaque decoded identifiers and ignores other query data", () => {
    assert.deepEqual(
        readRouteState(
            "https://example.test/mijn-bibliotheek/"
                + "?library_id=library%2Fone&item_id=item+one&ignored=yes#detail"
        ),
        {
            libraryId: "library/one",
            itemId: "item one",
            catalogQuery: defaultCatalogQuery(),
            hasCatalogQuery: false,
        }
    );
    assert.deepEqual(
        readRouteState("https://example.test/mijn-bibliotheek/"),
        {
            libraryId: null,
            itemId: null,
            catalogQuery: defaultCatalogQuery(),
            hasCatalogQuery: false,
        }
    );
    assert.deepEqual(
        readRouteState("https://example.test/mijn-bibliotheek/?library_id="),
        {
            libraryId: "",
            itemId: null,
            catalogQuery: defaultCatalogQuery(),
            hasCatalogQuery: false,
        }
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
    const query = {
        ...defaultCatalogQuery(),
        search: "Dune",
        readingStatuses: ["read", "reading"],
        sort: "author",
    };
    const queryUrl = buildRouteUrl(
        "https://example.test/mijn-bibliotheek/?old=yes#old",
        { libraryId: "library-1", catalogQuery: query }
    );
    assert.equal(
        queryUrl,
        "https://example.test/mijn-bibliotheek/?library_id=library-1"
            + "&catalog_search=Dune&catalog_sort=author"
            + "&catalog_reading_status=read&catalog_reading_status=reading"
    );
    assert.deepEqual(readRouteState(queryUrl), {
        libraryId: "library-1",
        itemId: null,
        catalogQuery: query,
        hasCatalogQuery: true,
    });
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
    assert.deepEqual(routes.read(), {
        libraryId: "library-2",
        itemId: null,
        catalogQuery: defaultCatalogQuery(),
        hasCatalogQuery: false,
    });
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

    assert.deepEqual(received, [{
        libraryId: "library-1",
        itemId: "item-1",
        catalogQuery: defaultCatalogQuery(),
        hasCatalogQuery: false,
    }]);
    unsubscribe();
    assert.equal(browser.listeners.has("popstate"), false);
});
