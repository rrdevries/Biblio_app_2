import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";
import {
    movedEntryIds,
    readNextReadingList,
    readSourceOptions,
    readWorkPage,
    sourceRequest,
    nextReadingErrorMessage,
} from "../../assets/js/next-reading.js";
import { BiblioApiError } from "../../assets/js/api.js";

const entry = (id, position, title = "Het boek", source = {
    state: "none",
    label: "Geen voorkeursbron",
}) => ({
    entry_id: id,
    position,
    work: { work_id: `work-${id}`, title },
    preferred_source: source,
});

test("Next Reading list preserves separately identified duplicate Works", () => {
    const list = readNextReadingList({
        list_version: 4,
        entries: [
            entry("one", 1, "Dezelfde titel"),
            entry("two", 2, "Dezelfde titel"),
        ],
    });
    assert.equal(list.entries.length, 2);
    assert.notEqual(list.entries[0].entry_id, list.entries[1].entry_id);
});

test("empty and unavailable preferred-source projections are safe", () => {
    const list = readNextReadingList({
        list_version: 1,
        entries: [entry("one", 1, "Boek", {
            state: "unavailable",
            label: "Voorkeursbron niet beschikbaar",
        })],
    });
    assert.deepEqual(Object.keys(list.entries[0].preferred_source), ["state", "label"]);
    assert.throws(() => readNextReadingList({
        list_version: 1,
        entries: [entry("one", 1, "Boek", {
            state: "unavailable",
            label: "Voorkeursbron niet beschikbaar",
            item_id: "private",
        })],
    }));
});

test("list allowlist rejects owner, persistence and malformed order data", () => {
    assert.throws(() => readNextReadingList({ list_version: 1, entries: [], owner_id: "private" }));
    assert.throws(() => readNextReadingList({ list_version: 1, entries: [entry("one", 2)] }));
});

test("bounded Work page validates only minimal discovery fields", () => {
    const page = readWorkPage({
        items: [{ work_id: "work-one", title: "Eerste boek" }],
        next_cursor: "opaque",
    });
    assert.equal(page.items[0].title, "Eerste boek");
    assert.throws(() => readWorkPage({
        items: [{ work_id: "work-one", title: "Boek", owner_id: "private" }],
        next_cursor: null,
    }));
});

test("source discovery and mutation payloads stay typed and human-labelled", () => {
    const [item, loan] = readSourceOptions({ items: [{
        type: "library_item",
        library_id: "library-one",
        item_id: "item-one",
        label: "Exemplaar uit Eigen kast",
    }, {
        type: "external_loan",
        external_loan_id: "loan-one",
        label: "Externe lening",
    }] });
    assert.deepEqual(sourceRequest(item), {
        type: "library_item",
        library_id: "library-one",
        item_id: "item-one",
    });
    assert.deepEqual(sourceRequest(loan), {
        type: "external_loan",
        external_loan_id: "loan-one",
    });
    assert.equal(sourceRequest(null), null);
});

test("reorder uses full stable entry-ID order and respects boundaries", () => {
    const entries = [entry("one", 1), entry("two", 2), entry("three", 3)];
    assert.deepEqual(movedEntryIds(entries, "two", "up"), ["two", "one", "three"]);
    assert.deepEqual(movedEntryIds(entries, "two", "down"), ["one", "three", "two"]);
    assert.deepEqual(movedEntryIds(entries, "one", "up"), ["one", "two", "three"]);
    assert.deepEqual(movedEntryIds(entries, "three", "down"), ["one", "two", "three"]);
});

test("UI contract contains exact empty state and Work-first add copy", async () => {
    const source = await readFile(new URL("../../assets/js/next-reading.js", import.meta.url), "utf8");
    assert.match(source, /Nog niets gepland om hierna te lezen\./);
    assert.match(source, /Boek toevoegen/);
    assert.match(source, /Zoek op titel/);
    assert.match(source, /Geen voorkeursbron/);
});

test("UI contract uses direct remove, server Undo, stale reload and no row navigation", async () => {
    const source = await readFile(new URL("../../assets/js/next-reading.js", import.meta.url), "utf8");
    assert.match(source, /api\.delete\(`me\/next-reading\/\$\{encodeURIComponent\(entry\.entry_id\)\}`/);
    assert.match(source, /api\.post\("me\/next-reading\/undo"/);
    assert.match(source, /undo_token: token/);
    assert.match(source, /error\.status === 409/);
    assert.doesNotMatch(source, /confirm\s*\(/);
    assert.doesNotMatch(source, /createElement\("a"\)/);
});

test("expired or used server Undo receives safe non-technical feedback", () => {
    const error = new BiblioApiError({
        kind: "http",
        code: "biblio_next_reading_undo_unavailable",
        status: 409,
        message: "Safe server message",
    });
    assert.equal(
        nextReadingErrorMessage(error),
        "Ongedaan maken is niet meer beschikbaar."
    );
});

test("UI contract includes abort revision, live status, focus and double-submit guards", async () => {
    const source = await readFile(new URL("../../assets/js/next-reading.js", import.meta.url), "utf8");
    assert.match(source, /searchController\?\.abort\(\)/);
    assert.match(source, /revision !== searchRevision/);
    assert.match(source, /aria-live/);
    assert.match(source, /focusEntry/);
    assert.match(source, /if \(busy\) return/);
});

test("responsive contract wraps actions and preserves minimum controls", async () => {
    const css = await readFile(new URL("../../assets/css/app.css", import.meta.url), "utf8");
    assert.match(css, /biblio-ui__next-reading-actions/);
    assert.match(css, /flex-wrap: wrap/);
    assert.match(css, /@media \(max-width: 767px\)/);
    assert.match(css, /--biblio-control-min: 44px/);
});
