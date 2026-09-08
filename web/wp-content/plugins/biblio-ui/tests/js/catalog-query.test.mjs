import assert from "node:assert/strict";
import test from "node:test";

import {
    catalogQueryPath,
    createCatalogQuerySession,
    defaultCatalogQuery,
    normalizeCatalogQuery,
    readCatalogPage,
    readCatalogQueryFromUrl,
    readClassificationOptions,
    writeCatalogQueryToUrl,
} from "../../assets/js/catalog-query.js";

function query(overrides = {}) {
    return { ...defaultCatalogQuery(), ...overrides };
}

function library(id = "library/one") {
    return {
        library_id: id,
        name: "Mijn bibliotheek",
        capabilities: { view_collection: true },
    };
}

function item(overrides = {}) {
    return {
        item_id: "item-1",
        work_id: "work-1",
        edition_id: "edition-1",
        title: "De ontdekking",
        item_status: "active",
        inventory_number: "INV-1",
        authors: [{ author_id: "author-1", display_name: "Ada Auteur" }],
        series: [{ series_id: "series-1", display_name: "Reeks", position: "2" }],
        location: { location_id: "location-1", display_name: "Kast A" },
        classification: {
            book_type_id: "type-1",
            genre_ids: ["genre-1"],
            subject_ids: ["subject-1"],
        },
        collection_ids: ["collection-1"],
        reading_status: "reading",
        contained_match_title: null,
        ...overrides,
    };
}

test("catalog query normalizes supported state and rejects ambiguous combinations", () => {
    assert.deepEqual(normalizeCatalogQuery(query({
        readingStatuses: ["read", "reading"],
        genreIds: ["genre-z", "genre-a"],
    })), query({
        readingStatuses: ["read", "reading"],
        genreIds: ["genre-a", "genre-z"],
    }));
    assert.throws(() => normalizeCatalogQuery(query({ search: "x" })), /2 to 191/);
    assert.throws(() => normalizeCatalogQuery(query({ search: " Dune" })), /trimmed/);
    assert.throws(() => normalizeCatalogQuery(query({
        collectionIds: ["collection-1"],
        withoutCollection: true,
    })), /exclusive/);
    assert.throws(() => normalizeCatalogQuery(query({ sort: "series" })), /Series sort/);
});

test("catalog REST path preserves exact query composition and opaque cursor", () => {
    assert.equal(catalogQueryPath("library/one", query({
        search: "Dune Messiah",
        readingStatuses: ["reading", "read"],
        authorIds: ["author/one"],
        seriesIds: ["series-1"],
        locationIds: ["location-1"],
        bookTypeIds: ["type-1"],
        genreIds: ["genre-1"],
        subjectIds: ["subject-1"],
        collectionIds: ["collection-1"],
        sort: "series",
        archiveScope: "active_and_archived",
    }), "cursor/+=="), "libraries/library%2Fone/catalog?search=Dune+Messiah"
        + "&reading_statuses%5B%5D=read&reading_statuses%5B%5D=reading"
        + "&author_ids%5B%5D=author%2Fone&series_ids%5B%5D=series-1"
        + "&location_ids%5B%5D=location-1&book_type_ids%5B%5D=type-1"
        + "&genre_ids%5B%5D=genre-1&subject_ids%5B%5D=subject-1"
        + "&collection_ids%5B%5D=collection-1&sort=series"
        + "&archive_scope=active_and_archived&cursor=cursor%2F%2B%3D%3D");
});

test("catalog URL state is canonical, repeatable and fail-closed", () => {
    const url = new URL("https://example.test/mijn-bibliotheek/?library_id=library-1&old=yes");
    writeCatalogQueryToUrl(url, query({
        search: "Dune",
        readingStatuses: ["read", "reading"],
        bookTypeIds: ["type-1"],
        sort: "author",
        archiveScope: "active_and_archived",
    }));
    const read = readCatalogQueryFromUrl(url);
    assert.equal(read.explicit, true);
    assert.deepEqual(read.query, query({
        search: "Dune",
        readingStatuses: ["read", "reading"],
        bookTypeIds: ["type-1"],
        sort: "author",
    }));
    assert.equal(url.searchParams.get("old"), "yes");
    assert.throws(() => readCatalogQueryFromUrl(
        "https://example.test/?catalog_search=x"
    ), /2 to 191/);
    assert.throws(() => readCatalogQueryFromUrl(
        "https://example.test/?catalog_sort=series"
    ), /Series sort/);
    assert.equal(readCatalogQueryFromUrl(
        "https://example.test/?catalog_archive=active_and_archived"
    ).explicit, false);
});

test("catalog page decoder maps only strict server fields into presentation", () => {
    const decoded = readCatalogPage({
        library: library(),
        items: [item({ contained_match_title: "Verhaal in bundel" })],
        next_cursor: "opaque-cursor",
    }, "library/one");
    assert.equal(decoded.items[0].title, "De ontdekking");
    assert.deepEqual(decoded.items[0].authors, {
        state: "known",
        values: ["Ada Auteur"],
    });
    assert.deepEqual(decoded.items[0].location_or_source, {
        state: "known",
        value: "Kast A",
    });
    assert.deepEqual(decoded.items[0].capabilities, {
        view_item: true,
        start_reading: false,
    });
    assert.equal(decoded.items[0].contained_match_title, "Verhaal in bundel");
    assert.equal(decoded.nextCursor, "opaque-cursor");
    assert.throws(() => readCatalogPage({
        library: library(),
        items: [{ ...item(), private_field: true }],
        next_cursor: null,
    }, "library/one"), /Catalog Item/);
    assert.throws(() => readCatalogPage({
        library: library("other"),
        items: [],
        next_cursor: null,
    }, "library/one"), /Catalog response/);
});

test("classification option decoder is Library-bound and exact", () => {
    assert.deepEqual(readClassificationOptions({
        library_id: "library-1",
        book_types: [{ book_type_id: "type-1", display_name: "Boek" }],
        genres: [{ genre_id: "genre-1", display_name: "Sciencefiction" }],
        subjects: [{ subject_id: "subject-1", display_name: "Ruimtevaart" }],
    }, "library-1"), {
        bookTypes: [{ id: "type-1", label: "Boek" }],
        genres: [{ id: "genre-1", label: "Sciencefiction" }],
        subjects: [{ id: "subject-1", label: "Ruimtevaart" }],
    });
    assert.throws(() => readClassificationOptions({
        library_id: "other",
        book_types: [],
        genres: [],
        subjects: [],
    }, "library-1"), /Classification options/);
});

test("session persistence is scoped and malformed state fails open", () => {
    const values = new Map();
    const storage = {
        getItem(key) { return values.get(key) ?? null; },
        setItem(key, value) { values.set(key, value); },
    };
    const first = createCatalogQuerySession({ storage, scope: "nonce-a:library-1" });
    const other = createCatalogQuerySession({ storage, scope: "nonce-b:library-1" });
    first.write(query({ search: "Dune" }));
    assert.deepEqual(first.read(), query({ search: "Dune" }));
    first.write(query({ archiveScope: "active_and_archived" }));
    assert.deepEqual(first.read(), query());
    values.set(
        "biblio.catalog.query.nonce-a%3Alibrary-1",
        JSON.stringify(query({
            search: "Dune",
            archiveScope: "active_and_archived",
        }))
    );
    assert.deepEqual(first.read(), query({ search: "Dune" }));
    assert.equal(other.read(), null);
    values.set("biblio.catalog.query.nonce-a%3Alibrary-1", "not-json");
    assert.equal(first.read(), null);
    values.set("biblio.catalog.query.nonce-a%3Alibrary-1", "null");
    assert.equal(first.read(), null);
});
