import assert from "node:assert/strict";
import test from "node:test";

import { createStartReadingView } from "../../assets/js/start-reading-view.js";

class FakeElement {
    constructor(tagName) {
        this.tagName = tagName.toUpperCase();
        this.attributes = new Map();
        this.children = [];
        this.className = "";
        this.textContent = "";
        this.value = "";
        this.disabled = false;
        this.focused = false;
        this.listeners = new Map();
        this.open = false;
        this.parent = null;
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }

    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    }

    append(...children) {
        for (const child of children) {
            child.parent = this;
            this.children.push(child);
        }
    }

    replaceChildren(...children) {
        for (const child of this.children) {
            child.parent = null;
        }

        this.children = [];
        this.append(...children);
    }

    addEventListener(type, listener) {
        this.listeners.set(type, listener);
    }

    click(event = {}) {
        return this.listeners.get("click")?.(event);
    }

    dispatch(type, event = {}) {
        return this.listeners.get(type)?.(event);
    }

    focus() {
        this.focused = true;
    }

    showModal() {
        this.open = true;
    }

    close() {
        this.open = false;
    }

    remove() {
        if (this.parent === null) {
            return;
        }

        this.parent.children = this.parent.children.filter(
            (child) => child !== this
        );
        this.parent = null;
    }
}

const documentImpl = {
    createElement(tagName) {
        return new FakeElement(tagName);
    },
};

function descendants(root, predicate) {
    const matches = [];

    for (const child of root.children) {
        if (predicate(child)) {
            matches.push(child);
        }

        matches.push(...descendants(child, predicate));
    }

    return matches;
}

function byTag(root, tagName) {
    return descendants(root, (node) => node.tagName === tagName.toUpperCase());
}

function text(root) {
    return [root.textContent, ...root.children.map(text)]
        .filter(Boolean)
        .join(" ");
}

function submitEvent() {
    return {
        prevented: false,
        preventDefault() {
            this.prevented = true;
        },
    };
}

function setup(overrides = {}) {
    const root = new FakeElement("div");
    const reloads = [];
    const view = createStartReadingView(root, {
        documentImpl,
        loginUrl: "https://example.test/wp-login.php?redirect_to=library",
        now: () => new Date(2026, 7, 28, 23, 45, 0),
        reload() {
            reloads.push("reload");
        },
        ...overrides,
    });
    const opener = new FakeElement("button");

    return { root, view, opener, reloads };
}

test("native dialog proposes the local day and cancel never mutates", () => {
    const { root, view, opener } = setup();
    let submits = 0;
    const dialog = view.open({
        opener,
        async submit() {
            submits += 1;
            return { state: "reconciled" };
        },
    });

    assert.equal(dialog.open, true);
    assert.equal(dialog.getAttribute("aria-labelledby") !== null, true);
    assert.equal(dialog.getAttribute("aria-describedby") !== null, true);
    assert.equal(byTag(root, "input")[0].value, "2026-08-28");
    assert.equal(byTag(root, "input")[0].getAttribute("type"), "date");
    assert.equal(byTag(root, "input")[0].focused, true);
    assert.match(text(root), /Lezen starten/);
    assert.match(text(root), /Startdatum/);

    byTag(root, "button")[0].click();
    assert.equal(byTag(root, "dialog").length, 0);
    assert.equal(opener.focused, true);
    assert.equal(submits, 0);
});

test("invalid calendar syntax stays local and is associated with the field", async () => {
    const { root, view, opener } = setup();
    let submits = 0;
    view.open({
        opener,
        async submit() {
            submits += 1;
            return { state: "reconciled" };
        },
    });
    const input = byTag(root, "input")[0];
    const form = byTag(root, "form")[0];

    for (const value of ["", "2026-2-03", "2026-02-30"]) {
        input.value = value;
        const event = submitEvent();
        await form.dispatch("submit", event);
        assert.equal(event.prevented, true);
        assert.equal(input.getAttribute("aria-invalid"), "true");
        assert.match(text(root), /volledige geldige startdatum/);
    }

    assert.equal(submits, 0);
});

test("one in-flight submit keeps the exact date and blocks duplicate submit", async () => {
    const { root, view, opener } = setup();
    let submissions = 0;
    let resolveSubmit;
    const pending = new Promise((resolve) => {
        resolveSubmit = resolve;
    });
    view.open({
        opener,
        submit(startedOn) {
            submissions += 1;
            assert.equal(startedOn, "2026-08-29");
            return pending;
        },
    });
    const input = byTag(root, "input")[0];
    const form = byTag(root, "form")[0];
    input.value = "2026-08-29";

    const first = form.dispatch("submit", submitEvent());
    const second = form.dispatch("submit", submitEvent());
    assert.equal(submissions, 1);
    assert.ok(byTag(root, "button").every((control) => control.disabled));
    assert.equal(input.disabled, true);
    const cancelEvent = submitEvent();
    byTag(root, "dialog")[0].dispatch("cancel", cancelEvent);
    assert.equal(cancelEvent.prevented, true);
    assert.equal(byTag(root, "dialog").length, 1);

    resolveSubmit({ state: "validation-error" });
    await Promise.all([first, second]);

    assert.equal(input.value, "2026-08-29");
    assert.equal(input.disabled, false);
    assert.match(text(root), /Controleer de startdatum/);
});

test("network retry is explicit and nonce recovery only reloads on request", async () => {
    const { root, view, opener, reloads } = setup();
    let outcome = { state: "retryable" };
    view.open({ opener, async submit() { return outcome; } });
    const input = byTag(root, "input")[0];
    const form = byTag(root, "form")[0];

    await form.dispatch("submit", submitEvent());
    assert.equal(input.value, "2026-08-28");
    assert.match(text(root), /Lezen starten is niet gelukt/);
    assert.equal(byTag(root, "button").at(-1).textContent, "Opnieuw proberen");
    assert.deepEqual(reloads, []);

    outcome = { state: "session-refresh" };
    await form.dispatch("submit", submitEvent());
    assert.match(text(root), /sessie moet worden vernieuwd/);
    assert.deepEqual(reloads, []);
    byTag(root, "button").at(-1).click();
    assert.deepEqual(reloads, ["reload"]);
});

test("authentication and acknowledged refresh failure expose safe recovery", async () => {
    const authentication = setup();
    authentication.view.open({
        opener: authentication.opener,
        async submit() {
            return { state: "authentication-required" };
        },
    });
    await byTag(authentication.root, "form")[0]
        .dispatch("submit", submitEvent());
    assert.match(text(authentication.root), /sessie is verlopen/);
    assert.equal(
        byTag(authentication.root, "a")[0].getAttribute("href"),
        "https://example.test/wp-login.php?redirect_to=library"
    );

    const refresh = setup();
    refresh.view.open({
        opener: refresh.opener,
        async submit(startedOn, lifecycle) {
            lifecycle.acknowledge("Lezen is gestart. Leesstatus bijwerken.");
            return {
                state: "refresh-failed",
                message: "Lezen is gestart, maar de actuele pagina kon niet worden vernieuwd.",
            };
        },
    });
    await byTag(refresh.root, "form")[0]
        .dispatch("submit", submitEvent());

    assert.equal(byTag(refresh.root, "dialog").length, 0);
    assert.match(text(refresh.root),
        /Lezen is gestart, maar de actuele pagina kon niet worden vernieuwd/);
    assert.deepEqual(refresh.reloads, []);
    byTag(refresh.root, "button")[0].click();
    assert.deepEqual(refresh.reloads, ["reload"]);
});

test("abort closes silently without presenting a mutation failure", async () => {
    const { root, view, opener } = setup();
    view.open({
        opener,
        async submit() {
            return { state: "aborted" };
        },
    });

    await byTag(root, "form")[0].dispatch("submit", submitEvent());

    assert.equal(byTag(root, "dialog").length, 0);
    assert.doesNotMatch(text(root), /niet gelukt|fout|Opnieuw proberen/);
});
