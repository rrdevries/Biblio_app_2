import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import { BiblioApiError } from "../../assets/js/api.js";
import {
    applyBibliographicDrilldownPage,
    applyBibliographicSearchPage,
    bibliographicDrilldownErrorMessage,
    bibliographicAuthorPresentation,
    bibliographicAuthorPreview,
    bibliographicSearchErrorMessage,
    initialBibliographicDrilldownPage,
    initialBibliographicSearchState,
    normalizeBibliographicSearchQuery,
    readBibliographicAuthorWorks,
    readBibliographicSearch,
    readBibliographicWorkEditions,
    revealMoreBibliographicAuthors,
    visibleBibliographicAuthors,
} from "../../assets/js/bibliographic-search.js";

const AUTHOR_ID = `search-author-${"a".repeat(64)}`;
const AUTHOR_ID_2 = `search-author-${"b".repeat(64)}`;
const WORK_ID = `search-work-${"c".repeat(64)}`;
const WORK_ID_2 = `search-work-${"d".repeat(64)}`;
const EDITION_ID = `search-edition-${"e".repeat(64)}`;
const EDITION_ID_2 = `search-edition-${"f".repeat(64)}`;

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
        match_quality: "broader",
        name_group_id: `author-name-${"1".repeat(64)}`,
        disambiguation: {
            representative_work_title: "The Dispossessed",
            linked_work_count: 1,
            birth_year: null,
        },
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

function authorWork(overrides = {}) {
    return {
        ...work(),
        provider_identity: null,
        ...overrides,
    };
}

function edition(overrides = {}) {
    return {
        result_id: EDITION_ID,
        result_kind: "local_canonical",
        edition_id: "edition-1",
        provider_identity: null,
        provider_work_identity: null,
        parent_work_result_id: WORK_ID,
        title: "The Dispossessed",
        subtitle: "An Ambiguous Utopia",
        contributors: ["Ursula K. Le Guin"],
        languages: ["eng"],
        publishers: ["Harper & Row"],
        publication_date: "1974",
        isbn_10: "0061054887",
        isbn_13: "9780061054884",
        format: "Paperback",
        page_count: 387,
        presentation_order: 0,
        requires_materialization: false,
        can_add_work_only: true,
        can_add_edition_specific: true,
        ...overrides,
    };
}

function drilldownResponse(items, overrides = {}) {
    return {
        items,
        next_cursor: "drilldown-cursor",
        provider_attempts: [attempt()],
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
    assert.equal(decoded.authors.items[0].match_quality, "broader");
    assert.equal(decoded.authors.items[0].disambiguation.linked_work_count, 1);
    assert.ok(Object.isFrozen(decoded.authors.items[0].disambiguation));
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
    assert.throws(() => readBibliographicSearch(response({ authors: [author({ match_quality: "close" })] })));
    assert.throws(() => readBibliographicSearch(response({ authors: [author({ name_group_id: "Ursula Le Guin" })] })));
    assert.throws(() => readBibliographicSearch(response({ authors: [author({ disambiguation: null })] })));
    assert.throws(() => readBibliographicSearch(response({ authors: [author({
        disambiguation: { ...author().disambiguation, linked_work_count: "1" },
    })] })));
    assert.throws(() => readBibliographicSearch(response({ authors: [author({
        disambiguation: { ...author().disambiguation, birth_year: 999 },
    })] })));
    assert.throws(() => readBibliographicSearch(response({ authors: [author({
        disambiguation: { ...author().disambiguation, provider_top_work: "private" },
    })] })));
    const { match_quality: omittedMatchQuality, ...missingMatchQuality } = author();
    assert.equal(omittedMatchQuality, "broader");
    assert.throws(() => readBibliographicSearch(response({ authors: [missingMatchQuality] })));
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

test("Author presentation uses human context and deterministic visible possibilities", () => {
    const group = `author-name-${"2".repeat(64)}`;
    const presented = bibliographicAuthorPresentation([
        author({ display_name: "Peter King", name_group_id: group }),
        author({
            result_id: AUTHOR_ID_2,
            result_kind: "external_candidate",
            author_id: null,
            display_name: "Peter King",
            author_selector: "second-author-selector",
            match_quality: "exact",
            name_group_id: group,
            disambiguation: {
                representative_work_title: null,
                linked_work_count: null,
                birth_year: null,
            },
        }),
    ]);

    assert.equal(presented[0].context, "Auteur van The Dispossessed");
    assert.equal(presented[1].context, "");
    assert.equal(presented[0].actionLabel, "Bekijk werken van Peter King, auteur van The Dispossessed");

    const indistinguishable = bibliographicAuthorPresentation([
        author({ display_name: "Peter King", name_group_id: group, disambiguation: {
            representative_work_title: null,
            linked_work_count: 0,
            birth_year: null,
        } }),
        author({
            result_id: AUTHOR_ID_2,
            result_kind: "external_candidate",
            author_id: null,
            display_name: "Peter King",
            author_selector: "second-author-selector",
            match_quality: "exact",
            name_group_id: group,
            disambiguation: {
                representative_work_title: null,
                linked_work_count: null,
                birth_year: null,
            },
        }),
    ]);
    assert.equal(indistinguishable[0].context, "Mogelijkheid 1 van 2");
    assert.equal(indistinguishable[1].context, "Mogelijkheid 2 van 2");
    assert.match(indistinguishable[1].actionLabel, /mogelijkheid 2 van 2$/);
});

test("Author preview and progressive disclosure preserve Core order without dropping loaded rows", () => {
    const exactGroup = `author-name-${"3".repeat(64)}`;
    const authors = Array.from({ length: 8 }, (_, index) => author({
        result_id: `search-author-${(index + 10).toString(16).padStart(64, "0")}`,
        result_kind: index === 0 ? "local_canonical" : "external_candidate",
        author_id: index === 0 ? "author-local" : null,
        display_name: index < 5 ? "Stephen King" : `Stephen King ${index}`,
        author_selector: `selector-${index}`,
        match_quality: index < 5 ? "exact" : "broader",
        name_group_id: index < 5 ? exactGroup : `author-name-${(index + 20).toString(16).padStart(64, "0")}`,
        disambiguation: index === 0 ? {
            representative_work_title: "It",
            linked_work_count: 1,
            birth_year: null,
        } : {
            representative_work_title: null,
            linked_work_count: null,
            birth_year: null,
        },
    }));
    const decoded = readBibliographicSearch(response({ authors, works: [], authorCursor: null }));
    const state = applyBibliographicSearchPage(initialBibliographicSearchState(), decoded);

    assert.deepEqual(bibliographicAuthorPreview(state.authors).map((item) => item.result_id), [
        authors[0].result_id,
        authors[1].result_id,
    ]);
    assert.equal(visibleBibliographicAuthors(state).length, 4);
    assert.equal(state.authors.length, 8);
    const revealed = revealMoreBibliographicAuthors(state);
    assert.equal(visibleBibliographicAuthors(revealed).length, 8);
    assert.equal(revealed.authors.length, 8);
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

test("selected Author Works decoder preserves only the opaque Work selector authority", () => {
    const decoded = readBibliographicAuthorWorks(drilldownResponse([
        authorWork(),
        authorWork({
            result_id: WORK_ID_2,
            result_kind: "external_candidate",
            work_id: null,
            work_selector: "external-work-selector",
            provider_identity: { provider_key: "open_library", record_id: "/works/OL2W" },
        }),
    ]));

    assert.equal(decoded.items[1].work_selector, "external-work-selector");
    assert.equal(decoded.items[1].provider_identity.record_id, "/works/OL2W");
    assert.ok(Object.isFrozen(decoded.items));
    assert.throws(() => readBibliographicAuthorWorks(drilldownResponse([
        authorWork({ work_selector: null }),
    ])));
    assert.throws(() => readBibliographicAuthorWorks(drilldownResponse([
        authorWork({ provider_identity: { provider_key: "other", record_id: "work" } }),
    ])));
});

test("selected Work Editions decoder accepts ISBN-less and nullable concrete Editions", () => {
    const decoded = readBibliographicWorkEditions(drilldownResponse([
        edition(),
        edition({
            result_id: EDITION_ID_2,
            result_kind: "external_candidate",
            edition_id: null,
            provider_identity: { provider_key: "open_library", record_id: "/books/OL2M" },
            provider_work_identity: { provider_key: "open_library", record_id: "/works/OL2W" },
            subtitle: null,
            contributors: [],
            languages: [],
            publishers: [],
            publication_date: null,
            isbn_10: null,
            isbn_13: null,
            format: null,
            page_count: null,
            requires_materialization: true,
            can_add_work_only: true,
        }),
    ]));

    assert.equal(decoded.items[1].isbn_13, null);
    assert.equal(decoded.items[1].page_count, null);
    assert.ok(Object.isFrozen(decoded.items[1].languages));
});

test("Edition decoder rejects malformed identity, ISBN, metadata and extra action fields", () => {
    assert.throws(() => readBibliographicWorkEditions(drilldownResponse([
        edition({ parent_work_result_id: WORK_ID_2, isbn_13: "9780061054885" }),
    ])));
    assert.throws(() => readBibliographicWorkEditions(drilldownResponse([
        edition({ page_count: 0 }),
    ])));
    assert.throws(() => readBibliographicWorkEditions(drilldownResponse([
        { ...edition(), wishlist_action: true },
    ])));
});

test("drill-down pagination appends immutably and rejects duplicate result identities", () => {
    const first = applyBibliographicDrilldownPage(
        initialBibliographicDrilldownPage(),
        readBibliographicAuthorWorks(drilldownResponse([authorWork()]))
    );
    const second = applyBibliographicDrilldownPage(
        first,
        readBibliographicAuthorWorks(drilldownResponse([
            authorWork({ result_id: WORK_ID_2, work_id: "work-2" }),
        ], { next_cursor: null })),
        { append: true }
    );

    assert.equal(second.items.length, 2);
    assert.strictEqual(second.items[0], first.items[0]);
    assert.equal(second.cursor, null);
    assert.ok(Object.isFrozen(second));
    assert.throws(() => applyBibliographicDrilldownPage(
        first,
        readBibliographicAuthorWorks(drilldownResponse([authorWork()])),
        { append: true }
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

test("drill-down errors describe stale selectors safely without authority details", () => {
    const stale = new BiblioApiError({
        kind: "http",
        code: "biblio_invalid_field_syntax",
        status: 400,
        message: "private mapping detail",
    });
    const message = bibliographicDrilldownErrorMessage(stale);

    assert.match(message, /niet meer actueel.*Zoek opnieuw/);
    assert.doesNotMatch(message, /selector|mapping|crypto|400|private/i);
});

test("production module uses only read-only discovery routes and exposes no selector copy", async () => {
    const source = await readFile(
        new URL("../../assets/js/bibliographic-search.js", import.meta.url),
        "utf8"
    );

    assert.match(source, /api\.post\("me\/bibliographic-searches"/);
    assert.match(source, /api\.post\("me\/bibliographic-author-works"/);
    assert.match(source, /api\.post\("me\/bibliographic-work-editions"/);
    assert.match(source, /author_cursor:/);
    assert.match(source, /work_cursor:/);
    assert.match(source, /Meer auteurs/);
    assert.match(source, /Meer boeken/);
    assert.match(source, /SEARCH_TABS = \["all", "books", "authors"\]/);
    assert.match(source, /role: "tablist"/);
    assert.match(source, /role: "tab"/);
    assert.match(source, /ArrowRight/);
    assert.match(source, /Bekijk alle/);
    assert.match(source, /Zoekscope/);
    assert.match(source, /Biblio-catalogus/);
    assert.doesNotMatch(source, /textContent:\s*"Externe bron"/);
    assert.doesNotMatch(source, /Aangesloten bibliografische bron/);
    assert.match(source, /Inclusief aangesloten bibliografische bronnen/);
    assert.match(source, /Externe resultaten konden niet volledig worden geladen/);
    assert.match(source, /type: "search"/);
    assert.match(source, /aria-live/);
    assert.match(source, /role: "status"/);
    assert.match(source, /rest_cookie_invalid_nonce/);
    assert.match(source, /data-search-result-group/);
    assert.match(source, /Zoeken op ISBN wordt in een volgende stap aangesloten/);
    assert.match(source, /Bekijk werken/);
    assert.match(source, /Bekijk uitgaven/);
    assert.match(source, /author_selector:\s*selectedAuthor\.author_selector/);
    assert.match(source, /work_selector:\s*selectedWork\.work_selector/);
    assert.doesNotMatch(source, /api\.(?:patch|delete)\(/);
    assert.doesNotMatch(source, /materializations|bibliographic-discoveries|openlibrary|google/i);
    assert.doesNotMatch(source, /textContent:\s*(?:author|work)\.(?:author_selector|work_selector|result_id)/);
    assert.doesNotMatch(source, /In Biblio|Uit bibliografische bron/);
    assert.doesNotMatch(source, /Series.*tab|Collections.*tab/i);
});

test("search CSS reuses tokens, portrait covers and responsive stacking", async () => {
    const css = await readFile(new URL("../../assets/css/app.css", import.meta.url), "utf8");
    const searchCss = css.slice(css.indexOf("[data-biblio-search-root]"));

    assert.match(searchCss, /var\(--biblio-color-page\)|var\(--biblio-color-surface\)/);
    assert.match(searchCss, /var\(--biblio-font-serif\)/);
    assert.match(searchCss, /biblio-ui__search-cover/);
    assert.doesNotMatch(searchCss, /aspect-ratio:\s*1\s*\/\s*1/);
    assert.match(
        searchCss,
        /grid-template-columns: repeat\(auto-fit, minmax\(min\(10\.75rem, 100%\), 1fr\)\)/
    );
    assert.doesNotMatch(searchCss, /biblio-ui__work-results--preview[^}]*overflow:\s*hidden/s);
    assert.match(searchCss, /biblio-ui__search-layout/);
    assert.match(searchCss, /biblio-ui__edition-results/);
    assert.match(searchCss, /biblio-ui__work-result--compact/);
    assert.match(searchCss, /@media \(max-width: 1199px\)/);
    assert.match(searchCss, /@media \(max-width: 767px\)/);
}
);
