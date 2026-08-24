import assert from "node:assert/strict";
import test from "node:test";

import { resolveLibraryContext } from "../../assets/js/library-state.js";

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

test("a valid URL Library is selected without URL replacement", () => {
    const requested = library("library-2", false);

    assert.deepEqual(
        resolveLibraryContext(
            [library("library-1", true), requested],
            { libraryId: "library-2", itemId: null }
        ),
        {
            state: "selected",
            library: requested,
            canonicalize: false,
        }
    );
});

test("an unknown URL Library is unavailable and never falls back", () => {
    assert.deepEqual(
        resolveLibraryContext(
            [library("library-1", true)],
            { libraryId: "library-missing", itemId: "item-1" }
        ),
        {
            state: "unavailable",
            requestedLibraryId: "library-missing",
        }
    );
    assert.deepEqual(
        resolveLibraryContext([], { libraryId: "", itemId: null }),
        { state: "unavailable", requestedLibraryId: "" }
    );
});

test("zero Libraries without a URL target produces the neutral empty state", () => {
    assert.deepEqual(
        resolveLibraryContext([], { libraryId: null, itemId: null }),
        { state: "empty" }
    );
});

test("one Library without a URL target is selected for canonicalization", () => {
    const onlyLibrary = library("library-1");

    assert.deepEqual(
        resolveLibraryContext(
            [onlyLibrary],
            { libraryId: null, itemId: "item-1" }
        ),
        {
            state: "selected",
            library: onlyLibrary,
            canonicalize: true,
        }
    );
});

test("multiple Libraries use exactly one designated personal Library", () => {
    const designated = library("library-personal", true);

    assert.deepEqual(
        resolveLibraryContext(
            [library("library-shared"), designated, library("library-other")],
            { libraryId: null, itemId: null }
        ),
        {
            state: "selected",
            library: designated,
            canonicalize: true,
        }
    );
});

test("multiple Libraries without exactly one designation require a chooser", () => {
    const noDesignation = [library("library-1"), library("library-2")];
    const duplicateDesignation = [
        library("library-1", true),
        library("library-2", true),
    ];

    assert.deepEqual(
        resolveLibraryContext(
            noDesignation,
            { libraryId: null, itemId: null }
        ),
        { state: "chooser", libraries: noDesignation }
    );
    assert.deepEqual(
        resolveLibraryContext(
            duplicateDesignation,
            { libraryId: null, itemId: null }
        ),
        { state: "chooser", libraries: duplicateDesignation }
    );
});

test("duplicate matching IDs are not accepted as one accessible Library", () => {
    assert.deepEqual(
        resolveLibraryContext(
            [library("library-1"), library("library-1")],
            { libraryId: "library-1", itemId: null }
        ),
        { state: "unavailable", requestedLibraryId: "library-1" }
    );
});
