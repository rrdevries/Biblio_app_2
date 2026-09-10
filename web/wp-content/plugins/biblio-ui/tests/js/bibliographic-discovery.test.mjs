import assert from "node:assert/strict";
import test from "node:test";

import {
    readBibliographicCandidate,
    readBibliographicDiscovery,
    readBibliographicMaterialization,
} from "../../assets/js/bibliographic-discovery.js";

function candidate(overrides = {}) {
    return {
        candidate_id: "candidate-1",
        type: "local_work",
        work_id: "work-1",
        edition_id: null,
        title: "The Dispossessed",
        subtitle: null,
        contributors: ["Ursula K. Le Guin"],
        languages: [],
        publishers: [],
        publication_date: null,
        page_count: null,
        format: null,
        isbn_10: null,
        isbn_13: null,
        provider_evidence: null,
        presentation_order: 0,
        capabilities: {
            can_add_work_only: true,
            can_add_edition_specific: false,
        },
        ...overrides,
    };
}

function evidence() {
    return {
        provider_key: "open_library",
        provider_record_id: "/books/OL1M",
        provider_work_id: "/works/OL1W",
        retrieved_at: "2026-09-10T12:00:00+00:00",
        match_method: "text_search",
    };
}

test("strict discovery decoder preserves all typed local and external results", () => {
    const decoded = readBibliographicDiscovery({
        query: { type: "text", normalized: "dispossessed le guin" },
        status: "results",
        discovery_id: "lookup-11111111111111111111111111111111",
        results: [
            candidate({
                candidate_id: "external-work",
                type: "external_work_candidate",
                work_id: null,
                provider_evidence: evidence(),
            }),
            candidate({
                candidate_id: "external-edition-1",
                type: "external_edition_candidate",
                work_id: null,
                title: "The Dispossessed — paperback",
                languages: ["eng"],
                publishers: ["Harper"],
                publication_date: "1974",
                page_count: 400,
                format: "Paperback",
                isbn_13: "9780060512750",
                provider_evidence: evidence(),
                presentation_order: 1,
                capabilities: {
                    can_add_work_only: true,
                    can_add_edition_specific: true,
                },
            }),
            candidate({
                candidate_id: "external-edition-2",
                type: "external_edition_candidate",
                work_id: null,
                title: "The Dispossessed — hardcover",
                provider_evidence: {
                    ...evidence(),
                    provider_record_id: "/books/OL2M",
                },
                presentation_order: 2,
                capabilities: {
                    can_add_work_only: true,
                    can_add_edition_specific: true,
                },
            }),
        ],
        provider_attempts: [{
            provider_key: "open_library",
            status: "candidates",
            failure_reason: null,
        }],
    });

    assert.deepEqual(decoded.results.map((result) => result.type), [
        "external_work_candidate",
        "external_edition_candidate",
        "external_edition_candidate",
    ]);
    assert.equal(decoded.results[1].capabilities.can_add_edition_specific, true);
    assert.ok(Object.isFrozen(decoded.results));
    assert.ok(Object.isFrozen(decoded.results[1].contributors));
    assert.ok(Object.isFrozen(decoded.provider_attempts));

    const localEdition = readBibliographicCandidate(candidate({
        candidate_id: "local-edition",
        type: "local_edition",
        edition_id: "edition-1",
        isbn_13: "9780060512750",
        capabilities: {
            can_add_work_only: true,
            can_add_edition_specific: true,
        },
    }));
    assert.equal(localEdition.edition_id, "edition-1");
});

test("strict discovery decoder preserves miss and failure as distinct successful states", () => {
    for (const status of [
        "no_results",
        "provider_failure",
        "configuration_failure",
        "invalid_provider_response",
    ]) {
        const textFailure = status === "configuration_failure"
            || status === "invalid_provider_response";
        const providerAttempts = status === "configuration_failure"
            ? ["open_library", "google_books"].map((provider_key) => ({
                provider_key,
                status: "configuration_error",
                failure_reason: "configuration",
            }))
            : status === "invalid_provider_response"
                ? [{
                    provider_key: "open_library",
                    status: "invalid_response",
                    failure_reason: "malformed",
                }, {
                    provider_key: "google_books",
                    status: "miss",
                    failure_reason: null,
                }]
                : [];
        const decoded = readBibliographicDiscovery({
            query: textFailure
                ? { type: "text", normalized: "missing book" }
                : { type: "isbn", normalized: "9780060512750" },
            status,
            discovery_id: null,
            results: [],
            provider_attempts: providerAttempts,
        });
        assert.equal(decoded.status, status);
    }
});

test("strict discovery decoder rejects extra, coerced and inconsistent fields", () => {
    assert.throws(() => readBibliographicCandidate(candidate({ candidate_id: 1 })));
    assert.throws(() => readBibliographicCandidate(candidate({
        capabilities: { can_add_work_only: 1, can_add_edition_specific: false },
    })));
    assert.throws(() => readBibliographicCandidate(candidate({
        type: "external_work_candidate",
        work_id: null,
    })));
    assert.throws(() => readBibliographicCandidate(candidate({ raw_payload: {} })));
    assert.throws(() => readBibliographicCandidate(candidate({
        isbn_10: "0306406152",
        isbn_13: "9780060512750",
    })));
    assert.throws(() => readBibliographicDiscovery({
        query: { type: "text", normalized: "book" },
        status: "results",
        discovery_id: null,
        results: [],
        provider_attempts: [],
    }));
    assert.throws(() => readBibliographicCandidate(candidate({
        capabilities: { can_add_work_only: false, can_add_edition_specific: true },
    })));
    assert.throws(() => readBibliographicDiscovery({
        query: { type: "isbn", normalized: "not-isbn" },
        status: "no_results",
        discovery_id: null,
        results: [],
        provider_attempts: [],
    }));
    assert.throws(() => readBibliographicDiscovery({
        query: { type: "text", normalized: "mixed results" },
        status: "results",
        discovery_id: "lookup-22222222222222222222222222222222",
        results: [candidate(), candidate({
            candidate_id: "external-work",
            type: "external_work_candidate",
            work_id: null,
            provider_evidence: evidence(),
        })],
        provider_attempts: [{
            provider_key: "open_library",
            status: "candidates",
            failure_reason: null,
        }],
    }));
    assert.throws(() => readBibliographicDiscovery({
        query: { type: "text", normalized: "bad attempt" },
        status: "no_results",
        discovery_id: null,
        results: [],
        provider_attempts: [{
            provider_key: "open_library",
            status: "miss",
            failure_reason: "network",
        }],
    }));
    assert.throws(() => readBibliographicDiscovery({
        query: { type: "text", normalized: "bad attempt" },
        status: "provider_failure",
        discovery_id: null,
        results: [],
        provider_attempts: [{
            provider_key: "open_library",
            status: "unavailable",
            failure_reason: null,
        }],
    }));
    assert.throws(() => readBibliographicDiscovery({
        query: { type: "text", normalized: "wrong summary" },
        status: "no_results",
        discovery_id: null,
        results: [],
        provider_attempts: [{
            provider_key: "open_library",
            status: "unavailable",
            failure_reason: "network",
        }, {
            provider_key: "google_books",
            status: "miss",
            failure_reason: null,
        }],
    }));
    assert.throws(() => readBibliographicDiscovery({
        query: { type: "isbn", normalized: "9780060512750" },
        status: "results",
        discovery_id: "lookup-33333333333333333333333333333333",
        results: [candidate({
            candidate_id: "external-edition",
            type: "external_edition_candidate",
            work_id: null,
            isbn_13: "9780060512750",
            provider_evidence: evidence(),
            capabilities: {
                can_add_work_only: true,
                can_add_edition_specific: true,
            },
        })],
        provider_attempts: [],
    }));
});

test("strict materialization decoder requires intent-specific canonical IDs and exact shape", () => {
    assert.deepEqual(readBibliographicMaterialization({
        work_id: "work-1",
        edition_id: "edition-1",
        reused: true,
    }, "work_and_edition"), {
        work_id: "work-1",
        edition_id: "edition-1",
        reused: true,
    });
    assert.deepEqual(readBibliographicMaterialization({
        work_id: "work-1",
        edition_id: null,
        reused: false,
    }, "work_only"), {
        work_id: "work-1",
        edition_id: null,
        reused: false,
    });
    assert.throws(() => readBibliographicMaterialization({
        work_id: "work-1",
        edition_id: null,
        reused: "yes",
    }, "work_only"));
    assert.throws(() => readBibliographicMaterialization({
        work_id: "work-1",
        edition_id: null,
        reused: false,
        candidate_id: "temporary",
    }, "work_only"));
    assert.throws(() => readBibliographicMaterialization({
        work_id: "work-1",
        edition_id: "edition-1",
        reused: false,
    }, "work_only"));
    assert.throws(() => readBibliographicMaterialization({
        work_id: "work-1",
        edition_id: null,
        reused: false,
    }, "work_and_edition"));
    assert.throws(() => readBibliographicMaterialization({
        work_id: "work-1",
        edition_id: null,
        reused: false,
    }));
});
