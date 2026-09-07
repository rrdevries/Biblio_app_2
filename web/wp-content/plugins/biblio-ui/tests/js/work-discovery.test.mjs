import assert from "node:assert/strict";
import test from "node:test";
import {
    readWorkDiscoveryWork,
    readWorkPage,
} from "../../assets/js/work-discovery.js";

const work = {
    work_id: "work-one",
    title: "Eerste boek",
    authors: [{ author_id: "author-one", display_name: "Auteur" }],
    work_title_status: "provisional",
    series: [{ series_id: "series-one", display_name: "Reeks", position: null }],
};

test("shared Work page accepts the complete current discovery contract", () => {
    const page = readWorkPage({ items: [work], next_cursor: "opaque" });

    assert.equal(page.items[0].title, "Eerste boek");
    assert.equal(page.items[0].authors[0].display_name, "Auteur");
    assert.equal(readWorkDiscoveryWork(work).series[0].display_name, "Reeks");
    assert.ok(Object.isFrozen(page.items));
    assert.ok(Object.isFrozen(page.items[0].authors));
});

test("shared Work decoder rejects extra, private and malformed fields", () => {
    assert.throws(() => readWorkPage({
        items: [{ ...work, owner_id: "private" }],
        next_cursor: null,
    }));
    assert.throws(() => readWorkPage({
        items: [{ ...work, work_title_status: "unknown" }],
        next_cursor: null,
    }));
    assert.throws(() => readWorkPage({
        items: [{ ...work, authors: [{ ...work.authors[0], owner_id: "private" }] }],
        next_cursor: null,
    }));
    assert.throws(() => readWorkPage({
        items: [{ ...work, series: [{ ...work.series[0], position: 1 }] }],
        next_cursor: null,
    }));
});
