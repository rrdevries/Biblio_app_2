import assert from "node:assert/strict";
import test from "node:test";

import { createEndReadingView } from "../../assets/js/end-reading-view.js";

class FakeElement {
    constructor(tagName) {
        this.tagName = tagName.toUpperCase();
        this.attributes = new Map();
        this.children = [];
        this.className = "";
        this.textContent = "";
        this.value = "";
        this.checked = false;
        this.disabled = false;
        this.focused = false;
        this.listeners = new Map();
        this.open = false;
        this.parent = null;
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));

        if (name === "value") {
            this.value = String(value);
        }
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
        const listeners = this.listeners.get(type) ?? [];
        listeners.push(listener);
        this.listeners.set(type, listeners);
    }

    click(event = {}) {
        if (this.disabled) {
            return undefined;
        }

        return this.dispatch("click", event);
    }

    dispatch(type, event = {}) {
        const results = (this.listeners.get(type) ?? [])
            .map((listener) => listener(event));
        return results.at(-1);
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
    const view = createEndReadingView(root, {
        documentImpl,
        loginUrl: "https://example.test/wp-login.php?redirect_to=library",
        now: () => new Date(2026, 7, 29, 0, 15, 0),
        reload() {
            reloads.push("reload");
        },
        ...overrides,
    });
    const opener = new FakeElement("button");

    return { root, view, opener, reloads };
}

function open(setupResult, submit = async () => ({ state: "reconciled" })) {
    return setupResult.view.open({ opener: setupResult.opener, submit });
}

function controls(root) {
    const radios = byTag(root, "input").filter(
        (input) => input.getAttribute("type") === "radio"
    );
    const date = byTag(root, "input").find(
        (input) => input.getAttribute("type") === "date"
    );
    const buttons = byTag(root, "button");

    return {
        radios,
        date,
        cancel: buttons.find((button) => button.textContent === "Annuleren"),
        submit: buttons.find((button) => button.getAttribute("type") === "submit"),
        form: byTag(root, "form")[0],
        dialog: byTag(root, "dialog")[0],
    };
}

function choose(radio) {
    radio.checked = true;
    radio.dispatch("change");
}

test("dialog is named, grouped and starts with no outcome and local today", () => {
    const fixture = setup();
    const dialog = open(fixture);
    const current = controls(fixture.root);

    assert.equal(dialog.open, true);
    assert.equal(dialog.getAttribute("aria-labelledby") !== null, true);
    assert.equal(dialog.getAttribute("aria-describedby") !== null, true);
    assert.equal(dialog.getAttribute("data-biblio-end-reading"), "true");
    assert.equal(byTag(fixture.root, "fieldset").length, 1);
    assert.equal(byTag(fixture.root, "legend")[0].textContent, "Uitkomst");
    assert.deepEqual(current.radios.map((radio) => radio.value), [
        "completed",
        "stopped",
    ]);
    assert.ok(current.radios.every((radio) => radio.checked === false));
    assert.ok(current.radios.every((radio) => (
        radio.getAttribute("required") === ""
    )));
    assert.equal(current.radios[0].focused, true);
    assert.equal(current.date.value, "2026-08-29");
    assert.equal(current.date.getAttribute("max"), null);
    assert.equal(current.date.getAttribute("required"), "");
    assert.equal(current.submit.disabled, true);
    assert.match(text(fixture.root), /Leesronde afronden/);
    assert.match(text(fixture.root), /Uitgelezen/);
    assert.match(text(fixture.root), /Gestopt/);
    assert.match(text(fixture.root), /Einddatum/);
});

test("completed and stopped map to the exact ID-free runtime intent", async (t) => {
    for (const [index, outcome] of ["completed", "stopped"].entries()) {
        await t.test(outcome, async () => {
            const fixture = setup();
            const received = [];
            open(fixture, async (intent) => {
                received.push(structuredClone(intent));
                return { state: "reconciled" };
            });
            const current = controls(fixture.root);
            choose(current.radios[index]);
            current.date.value = "2026-08-28";
            current.date.dispatch("input");

            assert.equal(current.submit.disabled, false);
            await current.form.dispatch("submit", submitEvent());
            assert.deepEqual(received, [{
                outcome,
                finishedOn: "2026-08-28",
            }]);
            assert.deepEqual(Object.keys(received[0]).sort(), [
                "finishedOn",
                "outcome",
            ]);
            assert.equal(byTag(fixture.root, "dialog").length, 0);
            assert.equal(fixture.opener.focused, false);
        });
    }
});

test("missing outcome and invalid dates remain local with associated focus", async () => {
    const fixture = setup();
    let submissions = 0;
    open(fixture, async () => {
        submissions += 1;
        return { state: "reconciled" };
    });
    const current = controls(fixture.root);

    await current.form.dispatch("submit", submitEvent());
    assert.equal(submissions, 0);
    assert.equal(byTag(fixture.root, "fieldset")[0].getAttribute("aria-invalid"), "true");
    assert.match(text(fixture.root), /Kies Uitgelezen of Gestopt/);
    assert.equal(current.radios[0].focused, true);

    choose(current.radios[0]);
    for (const value of ["", "0999-12-31", "2026-2-03", "2026-02-30"]) {
        current.date.value = value;
        await current.form.dispatch("submit", submitEvent());
        assert.equal(submissions, 0);
        assert.equal(current.date.getAttribute("aria-invalid"), "true");
        assert.match(text(fixture.root), /volledige geldige einddatum/);
        assert.equal(current.date.focused, true);
    }
});

test("idle cancel and Escape close without mutation and restore trigger focus", () => {
    for (const closeWithEscape of [false, true]) {
        const fixture = setup();
        let submissions = 0;
        open(fixture, async () => {
            submissions += 1;
            return { state: "reconciled" };
        });
        const current = controls(fixture.root);

        if (closeWithEscape) {
            const event = submitEvent();
            current.dialog.dispatch("cancel", event);
            assert.equal(event.prevented, true);
        } else {
            current.cancel.click();
        }

        assert.equal(submissions, 0);
        assert.equal(byTag(fixture.root, "dialog").length, 0);
        assert.equal(fixture.opener.focused, true);
    }
});

test("pending blocks duplicate submit, Escape and cancel", async () => {
    const fixture = setup();
    let submissions = 0;
    let resolveSubmit;
    const pending = new Promise((resolve) => { resolveSubmit = resolve; });
    open(fixture, () => {
        submissions += 1;
        return pending;
    });
    const current = controls(fixture.root);
    choose(current.radios[0]);

    const first = current.form.dispatch("submit", submitEvent());
    const duplicate = current.form.dispatch("submit", submitEvent());
    assert.equal(submissions, 1);
    assert.equal(current.form.getAttribute("aria-busy"), "true");
    assert.ok(current.radios.every((radio) => radio.disabled));
    assert.equal(current.date.disabled, true);
    assert.equal(current.cancel.disabled, true);
    assert.equal(current.submit.disabled, true);

    const cancelEvent = submitEvent();
    current.dialog.dispatch("cancel", cancelEvent);
    current.cancel.click();
    assert.equal(cancelEvent.prevented, true);
    assert.equal(byTag(fixture.root, "dialog").length, 1);

    resolveSubmit({ state: "validation-error" });
    await Promise.all([first, duplicate]);
    assert.equal(submissions, 1);
});

test("server validation keeps the dialog usable and does not claim success", async () => {
    const fixture = setup();
    open(fixture, async () => ({ state: "validation-error" }));
    const current = controls(fixture.root);
    choose(current.radios[1]);

    await current.form.dispatch("submit", submitEvent());

    assert.equal(byTag(fixture.root, "dialog").length, 1);
    assert.equal(current.date.disabled, false);
    assert.equal(current.submit.disabled, false);
    assert.match(text(fixture.root), /Controleer de gekozen uitkomst en einddatum/);
    assert.doesNotMatch(text(fixture.root), /afgerond\./);
});

test("nonce and authentication recovery lock the stale form", async (t) => {
    await t.test("nonce", async () => {
        const fixture = setup();
        let submissions = 0;
        open(fixture, async () => {
            submissions += 1;
            return { state: "session-refresh" };
        });
        const current = controls(fixture.root);
        choose(current.radios[0]);
        await current.form.dispatch("submit", submitEvent());

        assert.equal(submissions, 1);
        assert.match(text(fixture.root), /sessie moet worden vernieuwd/);
        assert.deepEqual(fixture.reloads, []);
        assert.equal(current.date.disabled, true);
        assert.equal(current.submit.disabled, true);
        byTag(fixture.root, "button").at(-1).click();
        assert.deepEqual(fixture.reloads, ["reload"]);
    });

    await t.test("authentication", async () => {
        const fixture = setup();
        open(fixture, async () => ({ state: "authentication-required" }));
        const current = controls(fixture.root);
        choose(current.radios[1]);
        await current.form.dispatch("submit", submitEvent());

        assert.match(text(fixture.root), /sessie is verlopen/);
        assert.equal(
            byTag(fixture.root, "a")[0].getAttribute("href"),
            "https://example.test/wp-login.php?redirect_to=library"
        );
        assert.equal(current.submit.disabled, true);
    });
});

test("uncertain outcomes require reload and never show false success", async (t) => {
    for (const state of ["refresh-failed", "outcome-unknown", "unavailable"]) {
        await t.test(state, async () => {
            const fixture = setup();
            let submissions = 0;
            open(fixture, async () => {
                submissions += 1;
                return { state };
            });
            const current = controls(fixture.root);
            choose(current.radios[0]);
            await current.form.dispatch("submit", submitEvent());

            assert.equal(submissions, 1);
            assert.match(text(fixture.root), /Vernieuw de pagina/);
            assert.doesNotMatch(text(fixture.root), /Leesronde afgerond/);
            assert.equal(current.submit.disabled, true);
            await current.form.dispatch("submit", submitEvent());
            assert.equal(submissions, 1);
            byTag(fixture.root, "button").at(-1).click();
            assert.deepEqual(fixture.reloads, ["reload"]);
        });
    }
});

test("503 and 500 remain explicit, retryable and never auto-retry", async (t) => {
    for (const [state, message] of [
        ["service-unavailable", /tijdelijk niet beschikbaar/],
        ["internal-error", /kon niet worden afgerond/],
    ]) {
        await t.test(state, async () => {
            const fixture = setup();
            let submissions = 0;
            open(fixture, async () => {
                submissions += 1;
                return { state };
            });
            const current = controls(fixture.root);
            choose(current.radios[1]);
            await current.form.dispatch("submit", submitEvent());

            assert.equal(submissions, 1);
            assert.match(text(fixture.root), message);
            assert.equal(current.submit.textContent, "Opnieuw proberen");
            assert.equal(current.submit.disabled, false);
            assert.equal(byTag(fixture.root, "dialog").length, 1);
        });
    }
});

test("destroy removes an active dialog without restoring stale focus", () => {
    const fixture = setup();
    open(fixture);
    fixture.view.destroy();

    assert.equal(byTag(fixture.root, "dialog").length, 0);
    assert.equal(fixture.opener.focused, false);
});
