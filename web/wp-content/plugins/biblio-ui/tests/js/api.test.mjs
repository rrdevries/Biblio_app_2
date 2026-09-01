import assert from "node:assert/strict";
import test from "node:test";

import {
    BiblioApiError,
    createBiblioApi,
} from "../../assets/js/api.js";

const REST_ROOT = "https://example.test/wp-json/biblio/v1/";
const REST_NONCE = "rest-nonce";

function jsonResponse(status, payload) {
    return {
        ok: status >= 200 && status < 300,
        status,
        async text() {
            return JSON.stringify(payload);
        },
    };
}

function textResponse(status, body) {
    return {
        ok: status >= 200 && status < 300,
        status,
        async text() {
            return body;
        },
    };
}

function assertApiError(error, expected) {
    assert.ok(error instanceof BiblioApiError);
    assert.deepEqual(
        {
            name: error.name,
            kind: error.kind,
            code: error.code,
            status: error.status,
            message: error.message,
        },
        {
            name: "BiblioApiError",
            ...expected,
        }
    );
    assert.deepEqual(Object.keys(error).sort(), ["code", "kind", "name", "status"]);
}

test("GET builds the configured REST URL and unwraps a success envelope", async () => {
    const calls = [];
    const api = createBiblioApi({
        restRoot: REST_ROOT,
        restNonce: REST_NONCE,
        fetchImpl: async (...args) => {
            calls.push(args);
            return jsonResponse(200, { data: { libraries: [] } });
        },
    });

    assert.deepEqual(await api.get("me/libraries"), { libraries: [] });
    assert.deepEqual(calls, [[
        "https://example.test/wp-json/biblio/v1/me/libraries",
        {
            method: "GET",
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "X-WP-Nonce": REST_NONCE,
            },
        },
    ]]);
});

test("POST sends only the JSON transport contract and unwraps 201", async () => {
    const calls = [];
    const api = createBiblioApi({
        restRoot: REST_ROOT,
        restNonce: REST_NONCE,
        fetchImpl: async (...args) => {
            calls.push(args);
            return jsonResponse(201, { data: { reading_round_id: "round-1" } });
        },
    });
    const body = { started_on: "2026-08-24" };

    assert.deepEqual(
        await api.post("libraries/library-1/items/item-1/reading-rounds", body),
        { reading_round_id: "round-1" }
    );
    assert.deepEqual(calls, [[
        "https://example.test/wp-json/biblio/v1/"
            + "libraries/library-1/items/item-1/reading-rounds",
        {
            method: "POST",
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "X-WP-Nonce": REST_NONCE,
                "Content-Type": "application/json",
            },
            body: JSON.stringify(body),
        },
    ]]);
});

test("PATCH and DELETE use the same nonce-protected exact JSON transport", async () => {
    const calls = [];
    const responses = [
        jsonResponse(200, { data: { private_note_id: "note-1" } }),
        textResponse(204, ""),
    ];
    const api = createBiblioApi({
        restRoot: REST_ROOT,
        restNonce: REST_NONCE,
        fetchImpl: async (...args) => {
            calls.push(args);
            return responses.shift();
        },
    });
    const patchBody = { content: "<p>Nieuw</p>", expected_version: 2 };
    const deleteBody = { expected_version: 3 };

    assert.deepEqual(
        await api.patch("me/private-notes/note-1", patchBody),
        { private_note_id: "note-1" }
    );
    assert.equal(
        await api.delete("me/private-notes/note-1", deleteBody),
        null
    );
    assert.deepEqual(calls.map(([, options]) => options), [
        {
            method: "PATCH",
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "X-WP-Nonce": REST_NONCE,
                "Content-Type": "application/json",
            },
            body: JSON.stringify(patchBody),
        },
        {
            method: "DELETE",
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "X-WP-Nonce": REST_NONCE,
                "Content-Type": "application/json",
            },
            body: JSON.stringify(deleteBody),
        },
    ]);
});

test("a successful response without a body resolves to null", async () => {
    const api = createBiblioApi({
        restRoot: REST_ROOT,
        restNonce: REST_NONCE,
        fetchImpl: async () => textResponse(204, ""),
    });

    assert.equal(await api.get("me/libraries"), null);
});

test("a WordPress nonce error preserves its machine contract", async () => {
    const api = createBiblioApi({
        restRoot: REST_ROOT,
        restNonce: REST_NONCE,
        fetchImpl: async () => jsonResponse(403, {
            code: "rest_cookie_invalid_nonce",
            message: "Cookie check failed",
            data: { status: 403 },
        }),
    });

    await assert.rejects(
        api.post("libraries/library-1/items/item-1/reading-rounds", {
            started_on: "2026-08-24",
        }),
        (error) => {
            assertApiError(error, {
                kind: "http",
                code: "rest_cookie_invalid_nonce",
                status: 403,
                message: "Cookie check failed",
            });
            return true;
        }
    );
});

test("a Biblio REST error preserves code, status and safe message", async () => {
    const api = createBiblioApi({
        restRoot: REST_ROOT,
        restNonce: REST_NONCE,
        fetchImpl: async () => jsonResponse(422, {
            code: "biblio_validation_failed",
            message: "The request was rejected by Biblio validation.",
            data: { status: 422 },
        }),
    });

    await assert.rejects(api.get("me/libraries"), (error) => {
        assertApiError(error, {
            kind: "http",
            code: "biblio_validation_failed",
            status: 422,
            message: "The request was rejected by Biblio validation.",
        });
        return true;
    });
});

test("non-JSON and malformed success envelopes become invalid-response errors", async () => {
    for (const response of [
        textResponse(502, "<html>Bad gateway</html>"),
        jsonResponse(200, { libraries: [] }),
    ]) {
        const api = createBiblioApi({
            restRoot: REST_ROOT,
            restNonce: REST_NONCE,
            fetchImpl: async () => response,
        });

        await assert.rejects(api.get("me/libraries"), (error) => {
            assertApiError(error, {
                kind: "invalid_response",
                code: "biblio_ui_invalid_response",
                status: response.status,
                message: "Biblio returned an invalid response.",
            });
            return true;
        });
    }
});

test("network failures become safe errors without leaking the exception", async () => {
    const api = createBiblioApi({
        restRoot: REST_ROOT,
        restNonce: REST_NONCE,
        fetchImpl: async () => {
            throw new TypeError("private network implementation detail");
        },
    });

    await assert.rejects(api.get("me/libraries"), (error) => {
        assertApiError(error, {
            kind: "network",
            code: "biblio_ui_network_error",
            status: null,
            message: "Biblio could not be reached.",
        });
        assert.doesNotMatch(error.message, /private/);
        return true;
    });
});

test("abort behavior is distinct and does not call fetch", async () => {
    const controller = new AbortController();
    let fetchCalls = 0;
    const api = createBiblioApi({
        restRoot: REST_ROOT,
        restNonce: REST_NONCE,
        fetchImpl: async () => {
            fetchCalls += 1;
            return jsonResponse(200, { data: {} });
        },
    });
    controller.abort();

    await assert.rejects(
        api.get("me/libraries", { signal: controller.signal }),
        (error) => {
            assertApiError(error, {
                kind: "aborted",
                code: "biblio_ui_request_aborted",
                status: null,
                message: "The Biblio request was aborted.",
            });
            return true;
        }
    );
    assert.equal(fetchCalls, 0);
});

test("request paths cannot escape the configured Biblio REST root", async () => {
    const api = createBiblioApi({
        restRoot: REST_ROOT,
        restNonce: REST_NONCE,
        fetchImpl: async () => jsonResponse(200, { data: {} }),
    });

    await assert.rejects(
        api.get("../../users"),
        /must stay within the Biblio REST root/
    );
});
