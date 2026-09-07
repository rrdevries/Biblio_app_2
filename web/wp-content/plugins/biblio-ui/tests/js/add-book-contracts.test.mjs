import assert from "node:assert/strict";
import test from "node:test";

import {
    buildAddBookCommitBody,
    normalizeIsbn,
    readAddBookCommit,
    readAddBookLookup,
    readClassificationOptions,
} from "../../assets/js/add-book-contracts.js";

function binding(field = "title") {
    return {
        field,
        target: "edition",
        explicit_mappings: [],
        fallback_target: null,
    };
}

function candidate(id = "candidate-1") {
    return {
        candidate_id: id,
        source: {
            provider_key: "fixture",
            retrieved_at: "2026-09-07T10:00:00.000000Z",
            match_method: "exact_isbn",
        },
        quality: "sufficient",
        identifier: { isbn_10: "0306406152", isbn_13: "9780306406157" },
        fields: {
            title: "Theoretical Physics",
            subtitle: null,
            contributors: ["Ada Auteur"],
            languages: ["nld"],
            publishers: ["Uitgever"],
            publication_date: "2026",
            page_count: 240,
            format: null,
        },
        work_signal: null,
    };
}

function lookup(overrides = {}) {
    return {
        library_id: "library-1",
        status: "single_candidate",
        identifier: { isbn_10: "0306406152", isbn_13: "9780306406157" },
        lookup_id: "lookup-1",
        local_matches: [],
        candidates: [candidate()],
        field_bindings: [binding()],
        manual_available: true,
        retry_available: false,
        ...overrides,
    };
}

test("ISBN input normalizes ISBN-10 and ISBN-13 to canonical ISBN-13", () => {
    assert.equal(normalizeIsbn("0-306-40615-2"), "9780306406157");
    assert.equal(normalizeIsbn("978 0 306 40615 7"), "9780306406157");
    assert.equal(normalizeIsbn("9780306406158"), null);
    assert.equal(normalizeIsbn("not-an-isbn"), null);
});

test("lookup decoder accepts each internally consistent state", () => {
    assert.equal(readAddBookLookup(lookup(), "library-1").status, "single_candidate");
    const existing = {
        work_id: "work-1",
        work_title: "Work",
        work_title_status: "provisional",
        edition_id: "edition-1",
        edition_title: "Edition",
        authors: [{ author_id: "author-1", display_name: "Ada Auteur" }],
        canonical_isbn: "9780306406157",
        existing_item_count: 1,
        existing_items: [{
            item_id: "item-1",
            inventory_number: "INV-1",
            location: { location_id: "location-1", display_name: "Kast" },
        }],
    };
    assert.equal(readAddBookLookup(lookup({
        status: "existing_edition",
        lookup_id: null,
        local_matches: [existing],
        candidates: [],
    }), "library-1").local_matches[0].existing_item_count, 1);
    assert.equal(readAddBookLookup(lookup({
        status: "local_ambiguous",
        lookup_id: null,
        local_matches: [existing, { ...existing, edition_id: "edition-2" }],
        candidates: [],
    }), "library-1").local_matches.length, 2);
    assert.equal(readAddBookLookup(lookup({
        status: "multiple_candidates",
        candidates: [candidate("candidate-1"), candidate("candidate-2")],
    }), "library-1").candidates.length, 2);
    const providerFailure = readAddBookLookup(lookup({
        status: "provider_failure",
        lookup_id: null,
        candidates: [],
        field_bindings: [binding("title"), {
            field: "contributors",
            target: "evidence_only",
            explicit_mappings: {
                author: "work",
                translator: "edition",
            },
            fallback_target: "evidence_only",
        }],
        retry_available: true,
    }), "library-1");
    assert.equal(providerFailure.retry_available, true);
    assert.equal(providerFailure.manual_available, true);
    assert.deepEqual(providerFailure.field_bindings[1].explicit_mappings, {
        author: "work",
        translator: "edition",
    });
});

test("lookup decoder rejects extra data and inconsistent state", () => {
    assert.throws(() => readAddBookLookup({ ...lookup(), provider_payload: {} }, "library-1"));
    assert.throws(() => readAddBookLookup(lookup({ lookup_id: null }), "library-1"));
    assert.throws(() => readAddBookLookup(lookup(), "library-foreign"));
    assert.throws(() => readAddBookLookup(lookup({
        field_bindings: [{
            ...binding("contributors"),
            explicit_mappings: ["work"],
        }],
    }), "library-1"));
});

test("classification and commit decoders are exact and Library-bound", () => {
    const options = readClassificationOptions({
        library_id: "library-1",
        book_types: [{ book_type_id: "book-1", display_name: "Leesboek" }],
        genres: [{ genre_id: "genre-1", display_name: "Roman" }],
        subjects: [],
    }, "library-1");
    assert.equal(options.book_types[0].display_name, "Leesboek");
    assert.throws(() => readClassificationOptions({
        library_id: "library-2",
        book_types: [],
        genres: [],
        subjects: [],
    }, "library-1"));

    assert.equal(readAddBookCommit({
        item_id: "item-1",
        edition_id: "edition-1",
        work_id: "work-1",
        edition_title: "Edition",
        work_title_status: "provisional",
        existing_edition: false,
    }).item_id, "item-1");
});

test("commit body includes only supported Item and observed fields", () => {
    assert.deepEqual(buildAddBookCommitBody({
        identifier: null,
        selection: { type: "manual", work_id: "work-1" },
        observedFields: {
            title: "Edition",
            subtitle: "",
            contributors: ["Ada Auteur"],
            page_count: 240,
        },
        classification: {
            bookTypeId: "book-1",
            genreIds: ["genre-1"],
            subjectIds: [],
        },
        inventoryNumber: " INV-1 ",
    }), {
        identifier: null,
        selection: { type: "manual", work_id: "work-1" },
        observed_fields: {
            title: "Edition",
            contributors: ["Ada Auteur"],
            page_count: 240,
        },
        classification: {
            book_type_id: "book-1",
            genre_ids: ["genre-1"],
            subject_ids: [],
        },
        item: { inventory_number: "INV-1" },
    });
});
