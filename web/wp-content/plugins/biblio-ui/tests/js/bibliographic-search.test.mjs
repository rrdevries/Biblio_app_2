import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import { BiblioApiError } from "../../assets/js/api.js";
import {
    applyBibliographicSearchPage,
    bibliographicSearchErrorMessage,
    initialBibliographicSearchState,
    normalizeBibliographicSearchQuery,
    readBibliographicSearch,
} from "../../assets/js/bibliographic-search.js";

const AUTHOR_ID = `search-author-${"a".repeat(64)}`;
const AUTHOR_ID_2 = `search-author-${"b".repeat(64)}`;
const WORK_ID = `search-work-${"c".repeat(64)}`;
const WORK_ID_2 = `search-work-${"d".repeat(64)}`;

function attempt(overrides = {}) {
    return {
        provider_key: "open_library",
        status: "candidates",
        failure_reason: null,
        ...overrides,
    };
}

function author(overrides = {}) {
    return {
        result_id: AUTHOR_ID,
        result_kind: "local_canonical",
        author_id: "author-1",
        display_name: "Ursula K. Le Guin",
        author_selector: "signed-author-selector",
        ...overrides,
    };
}

function work(overrides = {}) {
    return {
        result_id: WORK_ID,
        result_kind: "local_canonical",
        work_id: "work-1",
        work_selector: "signed-work-selector",
        title: "The Dispossessed",
        authors: [{ author_id: "author-1", display_name: "Ursula K. Le Guin" }],
        series: [{ series_id: "series-1", display_name: "Hainish Cycle", position: "5" }],
        ...overrides,
    };
}

function response({
    query = "Ursula Le Guin",
    authors = [author()],
    works = [work()],
    authorCursor = "author-cursor",
    workCursor = "work-cursor",
    authorAttempts = [attempt()],
    workAttempts = [attempt()],
} = {}) {
    return {
        query,
        authors: {
            items: authors,
            next_cursor: authorCursor,
            provider_attempts: authorAttempts,
        },
        works: {
            items: works,
            next_cursor: workCursor,
            provider_attempts: workAttempts,
        },
    };
}

test("strict grouped decoder preserves opaque Author and Work selectors", () => {
    const decoded = readBibliographicSearch(response());

    assert.equal(decoded.authors.items[0].author_selector, "signed-author-selector");
    assert.equal(decoded.works.items[0].work_selector, "signed-work-selector");
    assert.equal(decoded.works.items[0].series[0].position, "5");
    assert.ok(Object.isFrozen(decoded));
    assert.ok(Object.isFrozen(decoded.authors.items));
    assert.ok(Object.isFrozen(decoded.works.items[0].authors));
    assert.ok(Object.isFrozen(decoded.works.items[0].series));
});

test("decoder accepts Authors-only, Works-only and complete empty pages", () => {
    const cases = [
        response({ works: [], workCursor: null }),
        response({ authors: [], authorCursor: null }),
        response({ authors: [], works: [], authorCursor: null, workCursor: null }),
    ];

    for (const value of cases) {
        assert.doesNotThrow(() => readBibliographicSearch(value));
    }
});

test("decoder rejects extra, malformed, coerced and concrete Edition fields", () => {
    assert.throws(() => readBibliographicSearch({ ...response(), total: 137 }));
    assert.throws(() => readBibliographicSearch(response({ query: "  Ursula Le Guin" })));
    assert.throws(() => readBibliographicSearch(response({ authors: [author({ author_selector: 42 })] })));
    assert.throws(() => readBibliographicSearch(response({ works: [work({ work_selector: null })] })));
    assert.throws(() => readBibliographicSearch(response({ works: [work({ isbn: "9780441172719" })] })));
    assert.throws(() => readBibliographicSearch(response({ works: [work({ work_id: null })] })));
    assert.throws(() => readBibliographicSearch(response({
        authors: [author(), author()],
    })));
    assert.throws(() => readBibliographicSearch(response({
        authorAttempts: [attempt({ status: "unavailable", failure_reason: null })],
    })));
});

test("new query replaces both groups and resets both cursors from the response", () => {
    const previous = applyBibliographicSearchPage(
        initialBibliographicSearchState(),
        response()
    );
    const replaced = applyBibliographicSearchPage(previous, response({
        query: "Octavia Butler",
        authors: [],
        works: [],
        authorCursor: null,
        workCursor: null,
        authorAttempts: [attempt({ status: "miss" })],
        workAttempts: [attempt({ status: "miss" })],
    }));

    assert.equal(replaced.query, "Octavia Butler");
    assert.deepEqual(replaced.authors, []);
    assert.deepEqual(replaced.works, []);
    assert.equal(replaced.authorCursor, null);
    assert.equal(replaced.workCursor, null);
});

test("Author continuation appends Authors and preserves the complete Work state", () => {
    const initial = applyBibliographicSearchPage(
        initialBibliographicSearchState(),
        response()
    );
    const continued = applyBibliographicSearchPage(initial, response({
        authors: [author({
            result_id: AUTHOR_ID_2,
            author_id: null,
            result_kind: "external_candidate",
            display_name: "Ursula Le Guin (external)",
            author_selector: "second-author-selector",
        })],
        works: [],
        authorCursor: null,
        workCursor: null,
    }), "authors");

    assert.equal(continued.authors.length, 2);
    assert.equal(continued.authors[1].author_selector, "second-author-selector");
    assert.strictEqual(continued.works[0], initial.works[0]);
    assert.equal(continued.workCursor, initial.workCursor);
    assert.deepEqual(continued.workAttempts, initial.workAttempts);
});

test("Work continuation appends Works and preserves the complete Author state", () => {
    const initial = applyBibliographicSearchPage(
        initialBibliographicSearchState(),
        response()
    );
    const continued = applyBibliographicSearchPage(initial, response({
        authors: [],
        works: [work({
            result_id: WORK_ID_2,
            work_id: null,
            result_kind: "external_candidate",
            title: "The Left Hand of Darkness",
            work_selector: "second-work-selector",
        })],
        authorCursor: null,
        workCursor: null,
    }), "works");

    assert.equal(continued.works.length, 2);
    assert.equal(continued.works[1].work_selector, "second-work-selector");
    assert.strictEqual(continued.authors[0], initial.authors[0]);
    assert.equal(continued.authorCursor, initial.authorCursor);
    assert.deepEqual(continued.authorAttempts, initial.authorAttempts);
});

test("continuations fail closed when group or normalized query changes", () => {
    const initial = applyBibliographicSearchPage(
        initialBibliographicSearchState(),
        response()
    );

    assert.throws(() => applyBibliographicSearchPage(initial, response(), "editions"));
    assert.throws(() => applyBibliographicSearchPage(
        initial,
        response({ query: "Different query" }),
        "authors"
    ));
});

test("query normalization follows the text contract without inventing search semantics", () => {
    assert.equal(normalizeBibliographicSearchQuery("  Ursula   Le Guin  "), "Ursula Le Guin");
    assert.throws(() => normalizeBibliographicSearchQuery(" \n\t "), /Vul een titel/);
    assert.throws(() => normalizeBibliographicSearchQuery("a".repeat(101)), /100 tekens/);
});

test("error copy distinguishes session, malformed and transport failure without jargon", () => {
    const session = new BiblioApiError({ kind: "http", code: "rest_not_logged_in", status: 401, message: "private" });
    const staleNonce = new BiblioApiError({ kind: "http", code: "rest_cookie_invalid_nonce", status: 403, message: "private" });
    const malformed = new BiblioApiError({ kind: "http", code: "biblio_invalid_bibliographic_search", status: 400, message: "private" });
    const network = new BiblioApiError({ kind: "network", code: "network", status: null, message: "private" });

    assert.match(bibliographicSearchErrorMessage(session), /sessie.*Log opnieuw in/);
    assert.match(bibliographicSearchErrorMessage(staleNonce), /sessie.*pagina opnieuw/);
    assert.match(bibliographicSearchErrorMessage(malformed), /titel of auteur/);
    assert.match(bibliographicSearchErrorMessage(network), /tijdelijk/);
    for (const error of [session, staleNonce, malformed, network]) {
        assert.doesNotMatch(bibliographicSearchErrorMessage(error), /REST|HTTP|401|400|private/);
    }
});

test("production module uses one read-only search route and exposes no selector copy", async () => {
    const source = await readFile(
        new URL("../../assets/js/bibliographic-search.js", import.meta.url),
        "utf8"
    );

    assert.match(source, /api\.post\("me\/bibliographic-searches"/);
    assert.match(source, /author_cursor:/);
    assert.match(source, /work_cursor:/);
    assert.match(source, /Meer auteurs/);
    assert.match(source, /Meer boeken/);
    assert.match(source, /Externe resultaten konden niet volledig worden geladen/);
    assert.match(source, /type: "search"/);
    assert.match(source, /aria-live/);
    assert.match(source, /role: "status"/);
    assert.match(source, /rest_cookie_invalid_nonce/);
    assert.match(source, /data-search-result-group/);
    assert.match(source, /Zoeken op ISBN wordt in een volgende stap aangesloten/);
    assert.doesNotMatch(source, /api\.(?:patch|delete)\(/);
    assert.doesNotMatch(source, /materializations|bibliographic-discoveries|openlibrary|google/i);
    assert.doesNotMatch(source, /textContent:\s*(?:author|work)\.(?:author_selector|work_selector|result_id)/);
    assert.doesNotMatch(source, /Bekijk werken|Bekijk uitgaven|Series.*tab/i);
});

test("search CSS reuses tokens, portrait covers and responsive stacking", async () => {
    const css = await readFile(new URL("../../assets/css/app.css", import.meta.url), "utf8");
    const searchCss = css.slice(css.indexOf("[data-biblio-search-root]"));

    assert.match(searchCss, /var\(--biblio-color-page\)|var\(--biblio-color-surface\)/);
    assert.match(searchCss, /var\(--biblio-font-serif\)/);
    assert.match(searchCss, /biblio-ui__search-cover/);
    assert.doesNotMatch(searchCss, /aspect-ratio:\s*1\s*\/\s*1/);
    assert.match(searchCss, /@media \(max-width: 1023px\)/);
    assert.match(searchCss, /@media \(max-width: 767px\)/);
}
);
