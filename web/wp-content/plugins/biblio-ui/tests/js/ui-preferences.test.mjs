import assert from "node:assert/strict";
import test from "node:test";

import {
    createUiPreferences,
    SIDEBAR_COLLAPSED_KEY,
} from "../../assets/js/ui-preferences.js";

test("sidebar collapse is the only persisted personal UI preference", () => {
    const values = new Map([[SIDEBAR_COLLAPSED_KEY, "true"]]);
    const storage = {
        getItem(key) { return values.get(key) ?? null; },
        setItem(key, value) { values.set(key, value); },
    };
    const preferences = createUiPreferences({ storage });

    assert.equal(preferences.sidebarCollapsed(), true);
    preferences.setSidebarCollapsed(false);
    assert.equal(values.get(SIDEBAR_COLLAPSED_KEY), "false");
    assert.deepEqual([...values.keys()], [SIDEBAR_COLLAPSED_KEY]);
});

test("unavailable browser storage fails open without breaking the shell", () => {
    const storage = {
        getItem() { throw new Error("blocked"); },
        setItem() { throw new Error("blocked"); },
    };
    const preferences = createUiPreferences({ storage });

    assert.equal(preferences.sidebarCollapsed(), false);
    assert.doesNotThrow(() => preferences.setSidebarCollapsed(true));
});
