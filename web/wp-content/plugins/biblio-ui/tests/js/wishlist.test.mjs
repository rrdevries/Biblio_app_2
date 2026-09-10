import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import { BiblioApiError } from "../../assets/js/api.js";
import {
    readWishlistEntry,
    readWishlistList,
    wishlistErrorMessage,
} from "../../assets/js/wishlist.js";

function entry(overrides = {}) {
    return {
        wishlist_entry_id: "wish-1",
        target_type: "work_only",
        work_id: "work-1",
        edition_id: null,
        display_title: "Het boek",
        authors: [{ author_id: "author-1", display_name: "De Auteur" }],
        created_at: "2026-09-10T08:00:00.000000Z",
        updated_at: "2026-09-10T08:00:00.000000Z",
        ...overrides,
    };
}

test("strict Wishlist decoder preserves separate Work and Edition targets", () => {
    const decoded = readWishlistList({
        entries: [
            entry(),
            entry({
                wishlist_entry_id: "wish-2",
                target_type: "edition_specific",
                edition_id: "edition-1",
                display_title: "Het boek — eerste uitgave",
            }),
            entry({
                wishlist_entry_id: "wish-3",
                target_type: "edition_specific",
                edition_id: "edition-2",
                display_title: "Het boek — tweede uitgave",
            }),
        ],
    });

    assert.equal(decoded.entries.length, 3);
    assert.deepEqual(decoded.entries.map((item) => item.target_type), [
        "work_only",
        "edition_specific",
        "edition_specific",
    ]);
    assert.notEqual(decoded.entries[1].edition_id, decoded.entries[2].edition_id);
    assert.ok(Object.isFrozen(decoded.entries));
    assert.ok(Object.isFrozen(decoded.entries[0].authors));
});

test("strict Wishlist decoder rejects malformed, extra and coerced values", () => {
    assert.throws(() => readWishlistList({ entries: [], owner_id: "private" }));
    assert.throws(() => readWishlistList({ entries: "not-an-array" }));
    assert.throws(() => readWishlistEntry(entry({ wishlist_entry_id: 12 })));
    assert.throws(() => readWishlistEntry(entry({ target_type: "edition" })));
    assert.throws(() => readWishlistEntry(entry({ edition_id: "edition-1" })));
    assert.throws(() => readWishlistEntry(entry({
        target_type: "edition_specific",
        edition_id: null,
    })));
    assert.throws(() => readWishlistEntry(entry({ created_at: "2026-09-10" })));
    assert.throws(() => readWishlistEntry(entry({
        authors: [{ author_id: "author-1", display_name: "Auteur", email: "private" }],
    })));
});

test("Wishlist conflict receives product copy and not a transport error", () => {
    const conflict = new BiblioApiError({
        kind: "http",
        code: "biblio_wishlist_intent_conflict",
        status: 409,
        message: "Internal transport message",
    });

    assert.match(wishlistErrorMessage(conflict), /specifieke uitgaven/);
    assert.match(wishlistErrorMessage(conflict), /verwijdert niets/);
    assert.doesNotMatch(wishlistErrorMessage(conflict), /Internal|409|REST/);
});

test("Wishlist UI uses only approved routes and never emulates reverse collapse", async () => {
    const source = await readFile(
        new URL("../../assets/js/wishlist.js", import.meta.url),
        "utf8"
    );

    assert.match(source, /api\.get\("me\/wishlist"\)/);
    assert.match(source, /api\.post\("me\/wishlist"/);
    assert.match(source, /api\.patch\(/);
    assert.match(source, /api\.delete\(`me\/wishlist\//);
    assert.match(source, /me\/works\?q=/);
    assert.match(source, /searchController\?\.abort\(\)/);
    assert.match(source, /searchRevision/);
    assert.doesNotMatch(source, /candidate_id|lookup_id|provider|fuzzy/i);
    assert.doesNotMatch(source, /Promise\.all\([^)]*api\.delete/s);
});

test("Wishlist copy hides technical target labels and names both meanings", async () => {
    const source = await readFile(
        new URL("../../assets/js/wishlist.js", import.meta.url),
        "utf8"
    );

    assert.match(source, /Uitgave maakt niet uit/);
    assert.match(source, /Specifieke uitgave/);
    assert.match(source, /Deze uitgave kiezen/);
    assert.match(source, /Algemene wens behouden/);
    assert.doesNotMatch(source, /textContent:\s*entry\.target_type/);
});
