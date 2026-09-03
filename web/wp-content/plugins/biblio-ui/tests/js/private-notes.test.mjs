import assert from "node:assert/strict";
import test from "node:test";

import { BiblioApiError } from "../../assets/js/api.js";

const sourceUrl = new URL("../../assets/js/private-notes.js", import.meta.url);
let source = await (await import("node:fs/promises")).readFile(sourceUrl, "utf8");
source = source.replaceAll(
    '"biblio-ui/api"',
    JSON.stringify(new URL("../../assets/js/api.js", import.meta.url).href)
);
const {
    createPrivateNotesController,
    createPrivateNotesView,
    privateNotePath,
    privateNotesPath,
    readPrivateNote,
    readPrivateNotesPage,
    safePrivateNoteFragment,
    serializePrivateNoteEditor,
} = await import(
    `data:text/javascript;base64,${Buffer.from(source).toString("base64")}`
);

function note(id = "note-1", html = "<p>Een notitie</p>", version = 1) {
    return { private_note_id: id, content_html: html, version };
}

function page(items = [], nextCursor = null) {
    return { items, next_cursor: nextCursor };
}

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise((resolvePromise, rejectPromise) => {
        resolve = resolvePromise;
        reject = rejectPromise;
    });

    return { promise, resolve, reject };
}

function httpError(status, code) {
    return new BiblioApiError({
        kind: "http",
        status,
        code,
        message: "Unsafe transport detail.",
    });
}

function text(value) {
    return {
        nodeType: 3,
        nodeValue: value,
        textContent: value,
    };
}

function element(tagName, children = [], attributes = 0) {
    const node = {
        nodeType: 1,
        tagName: tagName.toUpperCase(),
        childNodes: children,
        attributes: { length: attributes },
    };
    Object.defineProperty(node, "textContent", {
        get() {
            return children.map((child) => child.textContent ?? "").join("");
        },
    });
    return node;
}

function editor(...children) {
    return {
        childNodes: children,
        get textContent() {
            return children.map((child) => child.textContent ?? "").join("");
        },
    };
}

function paragraph(value) {
    return element("p", [text(value)]);
}

class ViewTextNode {
    constructor(value) {
        this.nodeType = 3;
        this.nodeValue = value;
        this.textContent = value;
        this.parentNode = null;
    }
}

class ViewElement {
    constructor(tagName, nodeType = 1) {
        this.nodeType = nodeType;
        this.tagName = tagName.toUpperCase();
        this.attributes = new Map();
        this.children = [];
        this.className = "";
        this.disabled = false;
        this.focused = false;
        this.focusCount = 0;
        this.listeners = new Map();
        this.parentNode = null;
        this.rootConnected = false;
        this.open = false;
        this._textContent = "";
    }

    get childNodes() {
        return this.children;
    }

    get textContent() {
        return this._textContent
            + this.children.map((child) => child.textContent ?? "").join("");
    }

    set textContent(value) {
        this._textContent = String(value);
        this.children = [];
    }

    get isConnected() {
        return this.rootConnected || this.parentNode?.isConnected === true;
    }

    setAttribute(name, value) {
        this.attributes.set(name, String(value));
    }

    getAttribute(name) {
        return this.attributes.get(name) ?? null;
    }

    append(...nodes) {
        for (const node of nodes) {
            const additions = node?.nodeType === 11 ? [...node.children] : [node];

            for (const addition of additions) {
                addition.parentNode = this;
                this.children.push(addition);
            }
        }
    }

    replaceChildren(...nodes) {
        for (const child of this.children) {
            child.parentNode = null;
        }

        this.children = [];
        this._textContent = "";
        this.append(...nodes);
    }

    addEventListener(type, listener) {
        this.listeners.set(type, listener);
    }

    click() {
        if (!this.disabled) {
            return this.listeners.get("click")?.({ preventDefault() {} });
        }
    }

    focus() {
        assert.equal(this.isConnected, true, "focus target must be connected");
        if (this.ownerDocument?.activeElement) {
            this.ownerDocument.activeElement.focused = false;
        }
        if (this.ownerDocument) {
            this.ownerDocument.activeElement = this;
        }
        this.focused = true;
        this.focusCount += 1;
    }

    querySelector(selector) {
        return viewDescendants(this).find((node) => viewMatches(node, selector)) ?? null;
    }

    querySelectorAll(selector) {
        return viewDescendants(this).filter((node) => viewMatches(node, selector));
    }

    showModal() {
        this.open = true;
    }

    close() {
        this.open = false;
    }

    remove() {
        if (this.parentNode === null) {
            return;
        }

        this.parentNode.children = this.parentNode.children.filter(
            (child) => child !== this
        );
        this.parentNode = null;
    }
}

function viewDescendants(root) {
    return root.children.flatMap((child) => [
        child,
        ...(child.children ? viewDescendants(child) : []),
    ]);
}

function viewMatches(node, selector) {
    const attribute = selector.match(/^\[([^=\]]+)(?:="([^"]*)")?\]$/u);

    if (attribute !== null) {
        const actual = node.getAttribute?.(attribute[1]);
        return attribute[2] === undefined ? actual !== null : actual === attribute[2];
    }

    return node.tagName === selector.toUpperCase();
}

function viewDocument() {
    const documentImpl = {
        body: new ViewElement("body"),
        activeElement: null,
        createElement(tagName) {
            const node = new ViewElement(tagName);
            node.ownerDocument = documentImpl;

            if (tagName === "template") {
                node.content = new ViewElement("fragment", 11);
                Object.defineProperty(node, "innerHTML", {
                    set(value) {
                        const rendered = new ViewElement("p");
                        rendered.append(new ViewTextNode(
                            String(value).replace(/<[^>]*>/gu, "")
                        ));
                        node.content.replaceChildren(rendered);
                    },
                });
            }

            return node;
        },
        createTextNode(value) {
            return new ViewTextNode(value);
        },
        createDocumentFragment() {
            return new ViewElement("fragment", 11);
        },
        execCommand() {},
        queryCommandState() { return false; },
        queryCommandValue() { return ""; },
    };

    return documentImpl;
}

function viewSetup() {
    const root = new ViewElement("div");
    root.rootConnected = true;
    const region = new ViewElement("div");
    region.setAttribute("data-biblio-private-notes", "true");
    root.append(region);

    return {
        root,
        region,
        view: createPrivateNotesView(root, { documentImpl: viewDocument() }),
    };
}

function viewNodes(root, tagName) {
    return viewDescendants(root).filter(
        (node) => node.tagName === tagName.toUpperCase()
    );
}

function viewBase(overrides = {}) {
    return {
        items: [],
        nextCursor: null,
        loading: false,
        error: null,
        paginationLoading: false,
        paginationError: null,
        editor: null,
        mutationPending: false,
        refreshWarning: false,
        notice: null,
        focusEditor: false,
        focusNotice: false,
        focusReadTarget: null,
        focusPagination: null,
        ...overrides,
    };
}

function viewActions(overrides = {}) {
    return {
        add() {},
        edit() {},
        remove() {},
        changed() { return false; },
        format() { return false; },
        paste() {},
        save() {},
        cancel() {},
        loadMore() {},
        refresh() {},
        retry() {},
        reload() {},
        loginUrl: "https://example.test/login",
        ...overrides,
    };
}

function viewRecorder() {
    const renders = [];
    const discards = [];
    const deletes = [];
    const focusRequests = [];

    return {
        renders,
        discards,
        deletes,
        focusRequests,
        render(model, actions) {
            focusRequests.push({
                editor: model.focusEditor,
                notice: model.focusNotice,
                read: model.focusReadTarget === null
                    ? null
                    : { ...model.focusReadTarget },
                pagination: model.focusPagination,
            });
            renders.push({ model, actions });
        },
        validateHtml() {
            return true;
        },
        canonicalizeHtml(html) {
            return html;
        },
        confirmDiscard(options) {
            discards.push(options);
            return true;
        },
        confirmDelete(options) {
            deletes.push(options);
            return true;
        },
        execFormat() { return false; },
        pastePlainText() {},
        destroy() {},
    };
}

function eventTargetRecorder() {
    const listeners = new Map();
    const calls = [];

    return {
        listeners,
        calls,
        addEventListener(type, listener) {
            calls.push(["add", type]);
            listeners.set(type, listener);
        },
        removeEventListener(type, listener) {
            calls.push(["remove", type]);

            if (listeners.get(type) === listener) {
                listeners.delete(type);
            }
        },
    };
}

function fixture({ api = {}, active = () => true } = {}) {
    const view = viewRecorder();
    const events = eventTargetRecorder();
    const calls = [];
    const client = {
        async get(path, options) {
            calls.push(["GET", path, options]);
            return api.get ? api.get(path, options) : page();
        },
        async post(path, body, options) {
            calls.push(["POST", path, body, options]);
            return api.post ? api.post(path, body, options) : note();
        },
        async patch(path, body, options) {
            calls.push(["PATCH", path, body, options]);
            return api.patch ? api.patch(path, body, options) : note();
        },
        async delete(path, body, options) {
            calls.push(["DELETE", path, body, options]);
            return api.delete ? api.delete(path, body, options) : null;
        },
    };
    const abort = new AbortController();
    const controller = createPrivateNotesController({
        root: {},
        api: client,
        workId: "work/authoritative",
        signal: abort.signal,
        isCurrent: active,
        view,
        eventTarget: events,
        loginUrl: "https://example.test/login",
        reload() {},
    });

    return {
        controller,
        view,
        events,
        calls,
        abort,
        latest() {
            return view.renders.at(-1);
        },
    };
}

test("Private Notes paths require typed authoritative IDs and opaque cursors", () => {
    assert.equal(
        privateNotesPath("work/a"),
        "me/works/work%2Fa/private-notes?limit=10"
    );
    assert.equal(
        privateNotesPath("work/a", "cursor/+"),
        "me/works/work%2Fa/private-notes?limit=10&cursor=cursor%2F%2B"
    );
    assert.equal(privateNotePath("note/a"), "me/private-notes/note%2Fa");
    assert.throws(() => privateNotesPath(""), /validated Work ID/);
    assert.throws(() => privateNotesPath("work", 4), /validated Private Notes cursor/);
    assert.throws(() => privateNotePath(""), /validated Private Note ID/);
});

test("strict Note and page readers accept only the exact public allowlist", () => {
    assert.deepEqual(readPrivateNote(note()), note());
    assert.deepEqual(readPrivateNotesPage(page([note()], "next")), {
        items: [note()],
        nextCursor: "next",
    });

    for (const invalid of [
        { ...note(), work_id: "leak" },
        { ...note(), private_note_id: "" },
        { ...note(), content_html: 4 },
        { ...note(), version: 0 },
    ]) {
        assert.throws(() => readPrivateNote(invalid), /invalid/);
    }

    for (const invalid of [
        { items: [], next_cursor: null, total: 0 },
        { items: {}, next_cursor: null },
        { items: [], next_cursor: "" },
    ]) {
        assert.throws(() => readPrivateNotesPage(invalid), /invalid/);
    }
});

test("serializer emits the exact safe subset, normalizes browser aliases and strips attributes", () => {
    const root = editor(
        element("div", [text("Alinea "), element("b", [text("vet")], 2)]),
        element("p", [element("i", [text("cursief")], 1), element("br")]),
        element("ul", [element("li", [text("Een")])]),
        element("ol", [element("li", [text("Twee")])]),
        element("blockquote", [text("Citaat")])
    );

    assert.equal(
        serializePrivateNoteEditor(root),
        "<p>Alinea <strong>vet</strong></p>"
            + "<p><em>cursief</em><br></p>"
            + "<ul><li>Een</li></ul>"
            + "<ol><li>Twee</li></ol>"
            + "<blockquote>Citaat</blockquote>"
    );
});

test("serializer escapes text, supports nested formatting and fails closed", () => {
    assert.equal(
        serializePrivateNoteEditor(editor(
            element("p", [
                element("strong", [text("<&")]),
                element("em", [text(" samen")]),
            ]),
            element("ul", [
                element("li", [text("boven"), element("ul", [
                    element("li", [text("onder")]),
                ])]),
            ])
        )),
        "<p><strong>&lt;&amp;</strong><em> samen</em></p>"
            + "<ul><li>boven<ul><li>onder</li></ul></li></ul>"
    );
    assert.equal(serializePrivateNoteEditor(editor(paragraph("   "))), "");
    assert.throws(
        () => serializePrivateNoteEditor(editor(element("script", [text("x")]))),
        /unsupported markup/
    );
    assert.throws(
        () => serializePrivateNoteEditor(editor({ nodeType: 8 })),
        /unsupported node/
    );
});

test("safe saved rendering reconstructs only validated nodes without attributes", () => {
    const sourceTree = element("p", [element("strong", [text("Veilig")])]);
    const created = [];
    const documentImpl = {
        createElement(tagName) {
            if (tagName === "template") {
                return {
                    content: { childNodes: [sourceTree] },
                    set innerHTML(value) {
                        this.received = value;
                    },
                };
            }

            const node = element(tagName);
            node.append = (...children) => node.childNodes.push(...children);
            created.push(node);
            return node;
        },
        createTextNode(value) {
            return text(value);
        },
        createDocumentFragment() {
            return {
                childNodes: [],
                append(...children) {
                    this.childNodes.push(...children);
                },
            };
        },
    };
    const fragment = safePrivateNoteFragment(documentImpl, "<p><strong>Veilig</strong></p>");

    assert.equal(fragment.childNodes[0].tagName, "P");
    assert.equal(fragment.childNodes[0].childNodes[0].tagName, "STRONG");
    assert.equal(created.every((node) => node.attributes.length === 0), true);

    sourceTree.attributes.length = 1;
    assert.throws(
        () => safePrivateNoteFragment(documentImpl, "<p onclick=x>onveilig</p>"),
        /unsupported attributes/
    );
});

test("plain-text paste prevents rich HTML and inserts only clipboard text", () => {
    const commands = [];
    const root = { querySelector() { return null; }, append() {} };
    const documentImpl = {
        createElement() { return {}; },
        execCommand(...args) { commands.push(args); },
    };
    const view = createPrivateNotesView(root, { documentImpl });
    let prevented = false;
    view.pastePlainText({
        preventDefault() { prevented = true; },
        clipboardData: {
            getData(type) {
                assert.equal(type, "text/plain");
                return "Alleen tekst <script>";
            },
        },
    });

    assert.equal(prevented, true);
    assert.deepEqual(commands, [["insertText", false, "Alleen tekst <script>"]]);
});

test("formatting commands expose toggle state for toolbar aria-pressed", () => {
    const commands = [];
    const root = { querySelector() { return null; }, append() {} };
    const documentImpl = {
        createElement() { return {}; },
        execCommand(...args) { commands.push(args); },
        queryCommandState(command) { return command === "bold"; },
        queryCommandValue(command) {
            return command === "formatBlock" ? "blockquote" : "";
        },
    };
    const view = createPrivateNotesView(root, { documentImpl });

    assert.equal(view.execFormat("bold"), true);
    assert.equal(view.execFormat("italic"), false);
    assert.equal(view.execFormat("blockquote"), true);
    assert.deepEqual(commands, [
        ["bold", false, null],
        ["italic", false, null],
        ["formatBlock", false, "blockquote"],
    ]);
});

test("initial load uses only the validated detail Work and preserves zero state", async () => {
    const f = fixture();
    await f.controller.load();

    assert.equal(f.calls[0][0], "GET");
    assert.equal(
        f.calls[0][1],
        "me/works/work%2Fauthoritative/private-notes?limit=10"
    );
    assert.deepEqual(f.latest().model.items, []);
    assert.equal(f.latest().model.loading, false);
    assert.equal(f.latest().model.error, null);
});

test("initial one/multiple load preserves server order and malformed payload is local", async () => {
    const notes = [note("new"), note("old")];
    const good = fixture({ api: { get: async () => page(notes, "next") } });
    await good.controller.load();
    assert.deepEqual(good.latest().model.items.map((item) => item.private_note_id), [
        "new",
        "old",
    ]);
    assert.equal(good.latest().model.nextCursor, "next");

    const bad = fixture({ api: { get: async () => ({ items: [], next_cursor: null, leak: 1 }) } });
    await bad.controller.load();
    assert.equal(bad.latest().model.error, "retry");
});

test("unsafe saved HTML fails before state commit and leaves the detail-owned controller recoverable", async () => {
    const f = fixture({ api: { get: async () => page([note("unsafe", "<script>x</script>")]) } });
    f.view.validateHtml = () => {
        throw new TypeError("unsafe html");
    };
    await f.controller.load();
    assert.deepEqual(f.latest().model.items, []);
    assert.equal(f.latest().model.error, "retry");
});

test("editor baseline uses the same canonicalizer as dirty serialization", async () => {
    const existing = note("canonical", "<p>Een &amp; twee</p>");
    const f = fixture({ api: { get: async () => page([existing]) } });
    f.view.canonicalizeHtml = () => "<p>Een &amp; twee</p>";
    await f.controller.load();
    f.latest().actions.edit(existing, { focus() {} });
    f.latest().actions.changed(editor(paragraph("Een & twee")));
    assert.equal(f.controller.isDirty(), false);
});

test("locked discard/delete copy and accessibility semantics are explicit", () => {
    for (const copy of [
        "Privénotities",
        "Notitie toevoegen",
        "Wijzigingen niet opslaan?",
        "Je hebt wijzigingen die nog niet zijn opgeslagen.",
        "Terug naar notitie",
        "Doorgaan zonder opslaan",
        "Privénotitie verwijderen?",
        "Deze notitie wordt definitief verwijderd. Dit kan niet ongedaan worden gemaakt.",
        "Definitief verwijderen",
        'role: "toolbar"',
        'role: "textbox"',
        'button.setAttribute("aria-pressed", "false")',
        "Niet-opgeslagen wijzigingen.",
        "Notities vernieuwen",
        "editor.dirtyId",
        'documentImpl, "ul"',
    ]) {
        assert.match(source, new RegExp(copy.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")));
    }

    assert.doesNotMatch(source, /Opslaan en doorgaan|autosave|aria-list/iu);
});

test("view exposes zero/list/editor semantics and programmatic editor status", () => {
    const { region, view } = viewSetup();
    view.render(viewBase(), viewActions());
    assert.deepEqual(viewNodes(region, "h2").map((node) => node.textContent), [
        "Privénotities",
    ]);
    assert.equal(viewNodes(region, "ul").length, 0);
    assert.equal(viewNodes(region, "li").length, 0);
    assert.equal(viewNodes(region, "button")[0].textContent, "Notitie toevoegen");

    const editorModel = {
        mode: "create",
        privateNoteId: null,
        version: null,
        baselineHtml: "",
        contentHtml: "",
        pending: false,
        dirty: true,
        error: null,
        recovery: null,
        labelId: "editor-label",
        helpId: "editor-help",
        dirtyId: "editor-dirty",
        errorId: "editor-error",
    };
    view.render(viewBase({ editor: editorModel, focusEditor: true }), viewActions({
        changed() { return true; },
        format() { return true; },
    }));
    const textbox = region.querySelector('[role="textbox"]');
    const toolbar = region.querySelector('[role="toolbar"]');
    const formatButtons = toolbar.querySelectorAll('[aria-pressed="false"]');
    assert.equal(textbox.getAttribute("contenteditable"), "true");
    assert.equal(textbox.getAttribute("aria-multiline"), "true");
    assert.equal(textbox.getAttribute("aria-labelledby"), "editor-label");
    assert.equal(
        textbox.getAttribute("aria-describedby"),
        "editor-help editor-dirty"
    );
    assert.equal(textbox.getAttribute("aria-invalid"), "false");
    assert.equal(textbox.focused, true);
    assert.equal(formatButtons.length, 5);
    assert.match(region.textContent, /Niet-opgeslagen wijzigingen/);
    formatButtons[0].click();
    assert.equal(formatButtons[0].getAttribute("aria-pressed"), "true");
    assert.equal(textbox.focused, true);
});

test("editor focus is restored after a native keyboard click default action without stealing later focus", async () => {
    const { region, view } = viewSetup();
    const editorModel = {
        mode: "create",
        privateNoteId: null,
        version: null,
        baselineHtml: "",
        contentHtml: "",
        pending: false,
        dirty: false,
        error: null,
        recovery: null,
        labelId: "keyboard-editor-label",
        helpId: "keyboard-editor-help",
        dirtyId: "keyboard-editor-dirty",
        errorId: "keyboard-editor-error",
    };
    view.render(viewBase({ editor: editorModel, focusEditor: true }), viewActions());
    const textbox = region.querySelector('[role="textbox"]');
    const quote = viewNodes(region, "button").find((button) => button.textContent === "Citaat");
    const documentImpl = textbox.ownerDocument;
    documentImpl.activeElement = documentImpl.body;
    textbox.focused = false;

    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(textbox.focused, true);

    view.render(viewBase({ editor: editorModel, focusEditor: true }), viewActions());
    const rerenderedTextbox = region.querySelector('[role="textbox"]');
    const rerenderedQuote = viewNodes(region, "button")
        .find((button) => button.textContent === quote.textContent);
    rerenderedQuote.focus();

    await new Promise((resolve) => setTimeout(resolve, 0));

    assert.equal(rerenderedQuote.focused, true);
    assert.equal(rerenderedTextbox.focused, false);
});

test("replacement controls receive deliberate Cancel and pagination focus", () => {
    const { region, view } = viewSetup();
    const existing = note("focus-note", "<p>Inhoud</p>");

    view.render(viewBase({
        items: [existing],
        focusReadTarget: { kind: "edit", privateNoteId: "focus-note" },
    }), viewActions());
    assert.equal(viewNodes(region, "ul").length, 1);
    assert.equal(viewNodes(region, "li").length, 1);
    assert.equal(viewNodes(region, "button").find(
        (button) => button.textContent === "Bewerken"
    ).focused, true);

    view.render(viewBase({
        items: [existing],
        nextCursor: "next",
        focusPagination: "more",
    }), viewActions());
    const more = viewNodes(region, "button").find(
        (button) => button.textContent === "Meer laden"
    );
    assert.equal(more.getAttribute("aria-disabled"), "false");
    assert.equal(more.focused, true);

    view.render(viewBase({ items: [existing], focusPagination: "heading" }), viewActions());
    const heading = viewNodes(region, "h2")[0];
    assert.equal(heading.getAttribute("tabindex"), "-1");
    assert.equal(heading.focused, true);

    view.render(viewBase({
        items: [existing],
        paginationError: "retry",
        focusPagination: "error",
    }), viewActions());
    const retry = viewNodes(region, "button").find(
        (button) => button.textContent === "Opnieuw proberen"
    );
    assert.equal(retry.focused, true);
});

test("native dialogs are labelled, described and keep deliberate focus", async () => {
    const { root, region, view } = viewSetup();
    let retained = 0;
    assert.equal(view.confirmDiscard({
        opener: new ViewElement("button"),
        discard() {},
        retain() { retained += 1; },
    }), true);
    let dialog = viewNodes(root, "dialog")[0];
    assert.ok(dialog.getAttribute("aria-labelledby"));
    assert.ok(dialog.getAttribute("aria-describedby"));
    assert.equal(dialog.open, true);
    const back = viewNodes(dialog, "button").find(
        (button) => button.textContent === "Terug naar notitie"
    );
    assert.equal(back.focused, true);
    back.click();
    assert.equal(retained, 1);

    const opener = new ViewElement("button");
    opener.rootConnected = true;
    view.render(viewBase({ notice: "Privénotitie verwijderd." }), viewActions());
    assert.equal(view.confirmDelete({
        note: note(),
        opener,
        async submit() { return { state: "deleted" }; },
        async refresh() { return false; },
        reload() {},
        loginUrl: "https://example.test/login",
    }), true);
    dialog = viewNodes(root, "dialog")[0];
    assert.ok(dialog.getAttribute("aria-labelledby"));
    assert.ok(dialog.getAttribute("aria-describedby"));
    assert.equal(viewNodes(dialog, "button").find(
        (button) => button.textContent === "Annuleren"
    ).focused, true);
    await viewNodes(dialog, "button").find(
        (button) => button.textContent === "Definitief verwijderen"
    ).click();
    assert.equal(region.querySelector('[role="status"]').focused, true);
    assert.equal(viewNodes(root, "dialog").length, 0);

    assert.equal(view.confirmDelete({
        note: note(),
        opener,
        async submit() { return { state: "stale" }; },
        async refresh() { return true; },
        reload() {},
        loginUrl: "https://example.test/login",
    }), true);
    dialog = viewNodes(root, "dialog")[0];
    await viewNodes(dialog, "button").find(
        (button) => button.textContent === "Definitief verwijderen"
    ).click();
    await viewNodes(dialog, "button").find(
        (button) => button.textContent === "Vernieuwen"
    ).click();
    assert.equal(viewNodes(region, "h2")[0].focused, true);
    assert.equal(viewNodes(root, "dialog").length, 0);
});

test("initial error exposes session/auth/local retry without affecting other app state", async () => {
    for (const [error, recovery] of [
        [httpError(401, "biblio_authentication_required"), "authentication"],
        [httpError(403, "rest_cookie_invalid_nonce"), "session"],
        [httpError(503, "biblio_core_unavailable"), "retry"],
    ]) {
        const f = fixture({ api: { get: async () => { throw error; } } });
        await f.controller.load();
        assert.equal(f.latest().model.error, recovery);
        assert.deepEqual(f.latest().model.items, []);
    }
});

test("pagination appends in server order, replaces cursor and prevents duplicates/click races", async () => {
    const continuation = deferred();
    let requests = 0;
    const f = fixture({
        api: {
            async get(path) {
                requests += 1;

                if (requests === 1) {
                    return page([note("one")], "cursor-1");
                }

                return continuation.promise;
            },
        },
    });
    await f.controller.load();
    const first = f.latest().actions.loadMore();
    const duplicate = await f.latest().actions.loadMore();
    assert.equal(duplicate, false);
    continuation.resolve(page([note("one"), note("two")], "cursor-2"));
    await first;

    assert.deepEqual(f.latest().model.items.map((item) => item.private_note_id), [
        "one",
        "two",
    ]);
    assert.equal(f.latest().model.nextCursor, "cursor-2");
    assert.equal(requests, 2);
    assert.equal(f.view.focusRequests.at(-1).pagination, "more");
});

test("pagination error preserves Notes and retries the exact same cursor", async () => {
    const paths = [];
    let attempt = 0;
    const f = fixture({
        api: {
            async get(path) {
                paths.push(path);
                attempt += 1;

                if (attempt === 1) {
                    return page([note("one")], "cursor-fixed");
                }

                if (attempt === 2) {
                    throw httpError(503, "biblio_core_unavailable");
                }

                return page([note("two")], null);
            },
        },
    });
    await f.controller.load();
    await f.latest().actions.loadMore();
    assert.deepEqual(f.latest().model.items.map((item) => item.private_note_id), ["one"]);
    assert.equal(f.latest().model.paginationError, "retry");
    assert.equal(f.view.focusRequests.at(-1).pagination, "error");
    await f.latest().actions.loadMore();
    assert.equal(paths[1], paths[2]);
    assert.equal(f.view.focusRequests.at(-1).pagination, "heading");
});

test("create remains local until Save, sends one exact POST and reconciles page 1", async () => {
    let getCount = 0;
    const f = fixture({
        api: {
            async get() {
                getCount += 1;
                return getCount === 1 ? page() : page([note("created", "<p>Nieuw</p>")]);
            },
            async post() {
                return note("created", "<p>Nieuw</p>");
            },
        },
    });
    await f.controller.load();
    f.latest().actions.add({ focus() {} });
    assert.equal(f.calls.filter(([method]) => method === "POST").length, 0);
    await f.latest().actions.save(editor(paragraph("Nieuw")));

    assert.deepEqual(f.calls.filter(([method]) => method === "POST")[0].slice(0, 3), [
        "POST",
        "me/works/work%2Fauthoritative/private-notes",
        { content: "<p>Nieuw</p>" },
    ]);
    assert.equal(f.calls.filter(([method]) => method === "POST").length, 1);
    assert.equal(f.calls.filter(([method]) => method === "GET").length, 2);
    assert.equal(f.latest().model.editor, null);
    assert.equal(f.view.focusRequests.at(-1).notice, true);
    assert.equal(
        f.view.focusRequests.slice(-2).every((request) => request.notice === true),
        true
    );
});

test("pending Save locks duplicate POST and authoritative response, never raw input, becomes read state", async () => {
    const pending = deferred();
    let getCount = 0;
    const f = fixture({
        api: {
            get: async () => {
                getCount += 1;
                return getCount === 1
                    ? page()
                    : page([note("server", "<p>Server</p>", 3)]);
            },
            post: async () => pending.promise,
        },
    });
    await f.controller.load();
    f.latest().actions.add({ focus() {} });
    const save = f.latest().actions.save(editor(paragraph("Rauw")));
    assert.equal(f.latest().model.mutationPending, true);
    const duplicate = await f.latest().actions.save(editor(paragraph("Dubbel")));
    assert.equal(duplicate, false);
    pending.resolve(note("server", "<p>Server</p>", 3));
    await save;
    assert.equal(f.calls.filter(([method]) => method === "POST").length, 1);
    assert.equal(f.latest().model.items[0]?.content_html, "<p>Server</p>");
});

test("successful create plus failed refresh never repeats POST and keeps reliable response", async () => {
    let getCount = 0;
    const f = fixture({
        api: {
            async get() {
                getCount += 1;

                if (getCount === 1) {
                    return page();
                }

                throw httpError(503, "biblio_core_unavailable");
            },
            post: async () => note("server", "<p>Server</p>", 1),
        },
    });
    await f.controller.load();
    f.latest().actions.add({ focus() {} });
    await f.latest().actions.save(editor(paragraph("Server")));
    assert.equal(f.calls.filter(([method]) => method === "POST").length, 1);
    assert.equal(f.latest().model.refreshWarning, true);
    assert.equal(f.latest().model.items[0].private_note_id, "server");
});

test("edit preserves formatting and sends exact PATCH with authoritative version", async () => {
    let getCount = 0;
    const existing = note("note/edit", "<p><strong>Oud</strong></p>", 7);
    const f = fixture({
        api: {
            async get() {
                getCount += 1;
                return page([getCount === 1 ? existing : note("note/edit", "<p><em>Nieuw</em></p>", 8)]);
            },
            patch: async () => note("note/edit", "<p><em>Nieuw</em></p>", 8),
        },
    });
    await f.controller.load();
    f.latest().actions.edit(existing, { focus() {} });
    assert.equal(f.latest().model.editor.contentHtml, "<p><strong>Oud</strong></p>");
    await f.latest().actions.save(editor(element("p", [element("em", [text("Nieuw")])])));
    assert.deepEqual(f.calls.find(([method]) => method === "PATCH").slice(0, 3), [
        "PATCH",
        "me/private-notes/note%2Fedit",
        { content: "<p><em>Nieuw</em></p>", expected_version: 7 },
    ]);
    assert.equal(f.latest().model.items[0].version, 8);
});

test("semantic no-op 200 closes editor without local version arithmetic", async () => {
    const existing = note("same", "<p>Zelfde</p>", 4);
    let getCount = 0;
    const f = fixture({
        api: {
            async get() {
                getCount += 1;
                return page([existing]);
            },
            patch: async () => existing,
        },
    });
    await f.controller.load();
    f.latest().actions.edit(existing, { focus() {} });
    await f.latest().actions.save(editor(paragraph("Zelfde")));
    assert.equal(f.latest().model.editor, null);
    assert.equal(f.latest().model.items[0].version, 4);
});

test("dirty state is semantic, clears after exact revert and owns beforeunload lifecycle", async () => {
    const existing = note("edit", "<p>Basis</p>", 2);
    const f = fixture({ api: { get: async () => page([existing]) } });
    await f.controller.load();
    f.latest().actions.edit(existing, { focus() {} });
    assert.equal(f.controller.isDirty(), false);
    assert.equal(f.latest().actions.changed(editor(paragraph("Anders"))), true);
    assert.equal(f.controller.isDirty(), true);
    assert.equal(f.events.listeners.has("beforeunload"), true);
    const unload = { prevented: false, preventDefault() { this.prevented = true; } };
    f.events.listeners.get("beforeunload")(unload);
    assert.equal(unload.prevented, true);
    assert.equal(unload.returnValue, "");
    assert.equal(f.latest().actions.changed(editor(paragraph("Basis"))), false);
    assert.equal(f.controller.isDirty(), false);
    assert.equal(f.events.listeners.has("beforeunload"), false);
});

test("clean Cancel is immediate while dirty Cancel has only retain/discard behavior", async () => {
    const f = fixture();
    await f.controller.load();
    f.latest().actions.add({ focus() {} });
    f.latest().actions.cancel({ focus() {} });
    assert.equal(f.latest().model.editor, null);
    assert.equal(f.view.discards.length, 0);
    assert.deepEqual(f.view.focusRequests.at(-1).read, { kind: "add" });

    f.latest().actions.add({ focus() {} });
    f.latest().actions.changed(editor(paragraph("Onopgeslagen")));
    f.latest().actions.cancel({ focus() {} });
    assert.equal(f.view.discards.length, 1);
    f.view.discards[0].retain();
    assert.notEqual(f.latest().model.editor, null);
    assert.equal(f.view.focusRequests.at(-1).editor, true);
    f.latest().actions.cancel({ focus() {} });
    f.view.discards[1].discard();
    assert.equal(f.latest().model.editor, null);
    assert.deepEqual(f.view.focusRequests.at(-1).read, { kind: "add" });
    assert.equal(f.calls.filter(([method]) => method !== "GET").length, 0);
});

test("edit Cancel restores the replacement Edit control instead of a stale DOM opener", async () => {
    const existing = note("edit-focus", "<p>Basis</p>", 2);
    const f = fixture({ api: { get: async () => page([existing]) } });
    await f.controller.load();
    f.latest().actions.edit(existing, { focus() {} });
    f.latest().actions.cancel({ focus() {} });

    assert.deepEqual(f.view.focusRequests.at(-1).read, {
        kind: "edit",
        privateNoteId: "edit-focus",
    });
});

test("dirty internal navigation waits, discards without mutation, and runs exactly once", async () => {
    const f = fixture();
    await f.controller.load();
    f.latest().actions.add({ focus() {} });
    f.latest().actions.changed(editor(paragraph("Onopgeslagen")));
    let navigations = 0;
    const guarded = f.controller.guardNavigation(() => {
        navigations += 1;
        return "done";
    });
    assert.equal(navigations, 0);
    f.view.discards[0].discard();
    assert.equal(await guarded, "done");
    assert.equal(navigations, 1);
    assert.equal(f.calls.filter(([method]) => method !== "GET").length, 0);
});

test("update 409 preserves local intent, performs no retry and refreshes only explicitly", async () => {
    const existing = note("edit", "<p>Basis</p>", 2);
    let getCount = 0;
    const f = fixture({
        api: {
            async get() {
                getCount += 1;
                return page([getCount === 1 ? existing : note("edit", "<p>Server</p>", 3)]);
            },
            patch: async () => { throw httpError(409, "biblio_private_note_stale"); },
        },
    });
    await f.controller.load();
    f.latest().actions.edit(existing, { focus() {} });
    await f.latest().actions.save(editor(paragraph("Lokaal")));
    assert.equal(f.latest().model.editor.contentHtml, "<p>Lokaal</p>");
    assert.equal(f.latest().model.editor.recovery.kind, "refresh");
    assert.equal(f.calls.filter(([method]) => method === "PATCH").length, 1);
    assert.equal(getCount, 1);
    await f.latest().model.editor.recovery.action();
    assert.equal(getCount, 2);
    assert.equal(f.latest().model.editor, null);
    assert.equal(f.latest().model.items[0].content_html, "<p>Server</p>");
    assert.deepEqual(f.view.focusRequests.at(-1).read, { kind: "heading" });
});

test("validation errors retain editor content and session errors expose existing recovery", async () => {
    for (const [error, expectation] of [
        [httpError(422, "biblio_validation_failed"), "error"],
        [httpError(403, "rest_cookie_invalid_nonce"), "session"],
        [httpError(401, "biblio_authentication_required"), "authentication"],
    ]) {
        const f = fixture({
            api: {
                get: async () => page(),
                post: async () => { throw error; },
            },
        });
        await f.controller.load();
        f.latest().actions.add({ focus() {} });
        await f.latest().actions.save(editor(paragraph("Bewaren")));
        assert.equal(f.latest().model.editor.contentHtml, "<p>Bewaren</p>");

        if (expectation === "error") {
            assert.match(f.latest().model.editor.error, /Controleer/);
        } else {
            assert.equal(f.latest().model.editor.recovery.kind, expectation);
        }
    }
});

test("delete uses exact ID/version once, pending-locks, then reconciles page 1", async () => {
    const existing = note("delete/me", "<p>Weg</p>", 5);
    const pending = deferred();
    let getCount = 0;
    const f = fixture({
        api: {
            async get() {
                getCount += 1;
                return getCount === 1 ? page([existing]) : page();
            },
            delete: async () => pending.promise,
        },
    });
    await f.controller.load();
    f.latest().actions.remove(existing, { focus() {} });
    const deleting = f.view.deletes[0].submit(existing);
    assert.deepEqual(await f.view.deletes[0].submit(existing), { state: "pending" });
    pending.resolve(null);
    assert.deepEqual(await deleting, { state: "deleted" });
    assert.deepEqual(f.calls.find(([method]) => method === "DELETE").slice(0, 3), [
        "DELETE",
        "me/private-notes/delete%2Fme",
        { expected_version: 5 },
    ]);
    assert.equal(f.calls.filter(([method]) => method === "DELETE").length, 1);
    assert.equal(getCount, 2);
});

test("delete stale and unavailable never disappear silently or retry mutation", async () => {
    for (const [error, expected] of [
        [httpError(409, "biblio_private_note_stale"), "stale"],
        [httpError(404, "biblio_resource_not_available"), "unavailable"],
    ]) {
        const existing = note("keep");
        const f = fixture({
            api: {
                get: async () => page([existing]),
                delete: async () => { throw error; },
            },
        });
        await f.controller.load();
        f.latest().actions.remove(existing, { focus() {} });
        assert.deepEqual(await f.view.deletes[0].submit(existing), { state: expected });
        assert.equal(f.latest().model.items.length, 1);
        assert.equal(f.calls.filter(([method]) => method === "DELETE").length, 1);
    }
});

test("delete session and authentication failures use locked existing recovery", async () => {
    for (const [error, expected] of [
        [httpError(403, "rest_cookie_invalid_nonce"), "session"],
        [httpError(401, "biblio_authentication_required"), "authentication"],
    ]) {
        const existing = note("keep");
        const f = fixture({
            api: {
                get: async () => page([existing]),
                delete: async () => { throw error; },
            },
        });
        await f.controller.load();
        f.latest().actions.remove(existing, { focus() {} });
        assert.deepEqual(await f.view.deletes[0].submit(existing), { state: expected });
        assert.equal(f.latest().model.items.length, 1);
        assert.equal(f.calls.filter(([method]) => method === "DELETE").length, 1);
    }
});

test("successful delete plus refresh failure removes only target and never retries DELETE", async () => {
    const one = note("one");
    const two = note("two");
    let getCount = 0;
    const f = fixture({
        api: {
            async get() {
                getCount += 1;

                if (getCount === 1) {
                    return page([one, two]);
                }

                throw httpError(503, "biblio_core_unavailable");
            },
            delete: async () => null,
        },
    });
    await f.controller.load();
    f.latest().actions.remove(one, { focus() {} });
    assert.deepEqual(await f.view.deletes[0].submit(one), { state: "deleted" });
    assert.deepEqual(f.latest().model.items.map((item) => item.private_note_id), ["two"]);
    assert.equal(f.latest().model.refreshWarning, true);
    assert.equal(f.calls.filter(([method]) => method === "DELETE").length, 1);
});

test("late Work response is ignored after generation invalidation or abort", async () => {
    const pending = deferred();
    let current = true;
    const f = fixture({
        active: () => current,
        api: { get: async () => pending.promise },
    });
    const load = f.controller.load();
    current = false;
    pending.resolve(page([note("late")]));
    assert.equal(await load, false);
    assert.deepEqual(f.latest().model.items, []);
    f.controller.destroy();
    assert.equal(f.events.listeners.has("beforeunload"), false);
});
