const ITEM_FIELDS = ["private_note_id", "content_html", "version"];
const PAGE_FIELDS = ["items", "next_cursor"];
const ALLOWED_ELEMENTS = new Set([
    "P", "BR", "STRONG", "EM", "UL", "OL", "LI", "BLOCKQUOTE",
]);
const ELEMENT_NODE = 1;
const TEXT_NODE = 3;
let dialogSequence = 0;

function isRecord(value) {
    return value !== null && typeof value === "object" && !Array.isArray(value);
}

function hasExactFields(value, fields) {
    const keys = Object.keys(value);

    return keys.length === fields.length
        && fields.every((field) => Object.hasOwn(value, field));
}

function freezeNote(note) {
    return Object.freeze({
        private_note_id: note.private_note_id,
        content_html: note.content_html,
        version: note.version,
    });
}

export function readPrivateNote(value) {
    if (
        !isRecord(value)
        || !hasExactFields(value, ITEM_FIELDS)
        || typeof value.private_note_id !== "string"
        || value.private_note_id.length === 0
        || typeof value.content_html !== "string"
        || !Number.isInteger(value.version)
        || value.version < 1
    ) {
        throw new TypeError("The Biblio Private Note response is invalid.");
    }

    return freezeNote(value);
}

export function readPrivateNotesPage(value) {
    if (
        !isRecord(value)
        || !hasExactFields(value, PAGE_FIELDS)
        || !Array.isArray(value.items)
        || !(
            value.next_cursor === null
            || (typeof value.next_cursor === "string"
                && value.next_cursor.length > 0)
        )
    ) {
        throw new TypeError("The Biblio Private Notes response is invalid.");
    }

    return Object.freeze({
        items: Object.freeze(value.items.map(readPrivateNote)),
        nextCursor: value.next_cursor,
    });
}

export function privateNotesPath(workId, cursor = null) {
    if (typeof workId !== "string" || workId.length === 0) {
        throw new TypeError("A validated Work ID is required for Private Notes.");
    }

    if (!(cursor === null || (typeof cursor === "string" && cursor.length > 0))) {
        throw new TypeError("A validated Private Notes cursor is required.");
    }

    const path = `me/works/${encodeURIComponent(workId)}/private-notes?limit=10`;

    return cursor === null
        ? path
        : `${path}&cursor=${encodeURIComponent(cursor)}`;
}

export function privateNotePath(privateNoteId) {
    if (typeof privateNoteId !== "string" || privateNoteId.length === 0) {
        throw new TypeError("A validated Private Note ID is required.");
    }

    return `me/private-notes/${encodeURIComponent(privateNoteId)}`;
}

function escapeText(value) {
    return value
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;");
}

function childNodes(node) {
    return Array.from(node?.childNodes ?? node?.children ?? []);
}

function attributeCount(node) {
    if (Number.isInteger(node?.attributes?.length)) {
        return node.attributes.length;
    }

    if (node?.attributes instanceof Map) {
        return node.attributes.size;
    }

    return 0;
}

function serializeNode(node, { editor = false, topLevel = false } = {}) {
    if (node?.nodeType === TEXT_NODE) {
        const text = escapeText(String(node.nodeValue ?? node.textContent ?? ""));

        return topLevel && text.trim().length > 0 ? `<p>${text}</p>` : text;
    }

    if (node?.nodeType !== ELEMENT_NODE) {
        throw new TypeError("Private Note content contains an unsupported node.");
    }

    let tagName = String(node.tagName ?? "").toUpperCase();

    if (editor && tagName === "B") {
        tagName = "STRONG";
    } else if (editor && tagName === "I") {
        tagName = "EM";
    } else if (editor && tagName === "DIV" && topLevel) {
        tagName = "P";
    }

    if (!ALLOWED_ELEMENTS.has(tagName)) {
        throw new TypeError("Private Note content contains unsupported markup.");
    }

    if (!editor && attributeCount(node) !== 0) {
        throw new TypeError("Private Note content contains unsupported attributes.");
    }

    if (tagName === "BR") {
        if (childNodes(node).length !== 0) {
            throw new TypeError("Private Note content contains malformed markup.");
        }

        return "<br>";
    }

    const content = childNodes(node)
        .map((child) => serializeNode(child, { editor, topLevel: false }))
        .join("");
    const tag = tagName.toLowerCase();

    return `<${tag}>${content}</${tag}>`;
}

function meaningfulText(node) {
    return String(node?.textContent ?? "").replaceAll("\u00a0", " ").trim();
}

export function serializePrivateNoteEditor(editorRoot) {
    if (editorRoot === null || editorRoot === undefined) {
        throw new TypeError("A Private Note editor is required.");
    }

    const serialized = childNodes(editorRoot)
        .map((node) => serializeNode(node, { editor: true, topLevel: true }))
        .join("");

    return meaningfulText(editorRoot).length === 0 ? "" : serialized;
}

function cloneValidatedNode(documentImpl, node) {
    if (node.nodeType === TEXT_NODE) {
        return documentImpl.createTextNode(node.nodeValue ?? "");
    }

    serializeNode(node);
    const clone = documentImpl.createElement(node.tagName.toLowerCase());

    for (const child of childNodes(node)) {
        clone.append(cloneValidatedNode(documentImpl, child));
    }

    return clone;
}

export function safePrivateNoteFragment(documentImpl, html) {
    if (
        typeof html !== "string"
        || typeof documentImpl?.createElement !== "function"
        || typeof documentImpl?.createDocumentFragment !== "function"
    ) {
        throw new TypeError("Private Note HTML and a Document are required.");
    }

    const template = documentImpl.createElement("template");
    template.innerHTML = html;
    const source = template.content ?? template;
    const fragment = documentImpl.createDocumentFragment();

    for (const node of childNodes(source)) {
        fragment.append(cloneValidatedNode(documentImpl, node));
    }

    return fragment;
}

function element(documentImpl, tagName, {
    className,
    text,
    attributes = {},
} = {}) {
    const node = documentImpl.createElement(tagName);

    if (className !== undefined) {
        node.className = className;
    }

    if (text !== undefined) {
        node.textContent = text;
    }

    for (const [name, value] of Object.entries(attributes)) {
        node.setAttribute(name, value);
    }

    return node;
}

function control(documentImpl, label, kind = "secondary") {
    return element(documentImpl, "button", {
        className: `biblio-ui__control biblio-ui__control--${kind}`,
        text: label,
        attributes: { type: "button" },
    });
}

function recoveryControl(documentImpl, recovery, actions) {
    if (recovery === "authentication") {
        return element(documentImpl, "a", {
            className: "biblio-ui__control biblio-ui__control--secondary",
            text: "Opnieuw inloggen",
            attributes: { href: actions.loginUrl },
        });
    }

    const button = control(
        documentImpl,
        recovery === "session"
            ? "Sessie vernieuwen"
            : recovery === "refresh"
                ? "Notities vernieuwen"
                : "Opnieuw proberen"
    );
    button.addEventListener(
        "click",
        recovery === "session" ? actions.reload : actions.retry
    );

    return button;
}

function renderMessage(documentImpl, message, recovery, actions) {
    const wrapper = element(documentImpl, "div", {
        className: "biblio-ui__inline-error biblio-ui__notes-error",
    });
    let recoveryElement = null;
    wrapper.append(element(documentImpl, "p", {
        text: message,
        attributes: { "aria-live": "polite" },
    }));

    if (recovery !== null) {
        recoveryElement = recoveryControl(documentImpl, recovery, actions);
        wrapper.append(recoveryElement);
    }

    return { wrapper, recoveryElement };
}

function appendSafeHtml(documentImpl, parent, html) {
    parent.append(safePrivateNoteFragment(documentImpl, html));
}

function formatButton(documentImpl, label, command, actions, restoreEditorFocus) {
    const button = control(documentImpl, label);
    button.className += " biblio-ui__format-control";
    button.setAttribute("data-biblio-format", command);
    button.setAttribute("aria-pressed", "false");
    button.addEventListener("click", () => {
        const pressed = actions.format(command);

        if (typeof pressed === "boolean") {
            button.setAttribute("aria-pressed", pressed ? "true" : "false");
        }

        restoreEditorFocus();
    });

    return button;
}

function renderEditor(documentImpl, editor, actions) {
    const wrapper = element(documentImpl, "div", {
        className: "biblio-ui__note-editor",
        attributes: {
            "aria-busy": editor.pending ? "true" : "false",
        },
    });
    const label = element(documentImpl, "p", {
        className: "biblio-ui__editor-label",
        text: editor.mode === "create" ? "Nieuwe privénotitie" : "Privénotitie bewerken",
        attributes: { id: editor.labelId },
    });
    const help = element(documentImpl, "p", {
        className: "biblio-ui__editor-help",
        text: "Gebruik alinea’s, vet, cursief, lijsten of een citaat.",
        attributes: { id: editor.helpId },
    });
    const dirtyStatus = element(documentImpl, "p", {
        className: "biblio-ui__editor-dirty-status",
        text: editor.dirty ? "Niet-opgeslagen wijzigingen." : "",
        attributes: { id: editor.dirtyId },
    });
    const editable = element(documentImpl, "div", {
        className: "biblio-ui__editor-surface",
        attributes: {
            contenteditable: editor.pending ? "false" : "true",
            role: "textbox",
            "aria-multiline": "true",
            "aria-labelledby": editor.labelId,
            "aria-describedby": editor.error
                ? `${editor.helpId} ${editor.dirtyId} ${editor.errorId}`
                : `${editor.helpId} ${editor.dirtyId}`,
            "aria-invalid": editor.error ? "true" : "false",
            "data-biblio-note-editor": "true",
        },
    });
    const toolbar = element(documentImpl, "div", {
        className: "biblio-ui__editor-toolbar",
        attributes: { "aria-label": "Notitie opmaken", role: "toolbar" },
    });
    const restoreEditorFocus = () => editable.focus();
    toolbar.append(
        formatButton(documentImpl, "Vet", "bold", actions, restoreEditorFocus),
        formatButton(documentImpl, "Cursief", "italic", actions, restoreEditorFocus),
        formatButton(documentImpl, "Opsomming", "insertUnorderedList", actions, restoreEditorFocus),
        formatButton(documentImpl, "Nummering", "insertOrderedList", actions, restoreEditorFocus),
        formatButton(documentImpl, "Citaat", "blockquote", actions, restoreEditorFocus)
    );
    for (const button of Array.from(toolbar.children)) {
        button.disabled = editor.pending;
    }

    if (editor.contentHtml !== "") {
        appendSafeHtml(documentImpl, editable, editor.contentHtml);
    }

    editable.addEventListener("input", () => {
        const dirty = actions.changed(editable);
        dirtyStatus.textContent = dirty ? "Niet-opgeslagen wijzigingen." : "";
    });
    editable.addEventListener("paste", (event) => actions.paste(event));
    const error = element(documentImpl, "p", {
        className: "biblio-ui__field-error",
        text: editor.error ?? "",
        attributes: { id: editor.errorId, "aria-live": "polite" },
    });
    const controls = element(documentImpl, "div", {
        className: "biblio-ui__note-actions",
    });
    const save = control(documentImpl, "Opslaan", "primary");
    const cancel = control(documentImpl, "Annuleren");
    save.disabled = editor.pending;
    cancel.disabled = editor.pending;
    save.addEventListener("click", () => actions.save(editable));
    cancel.addEventListener("click", () => actions.cancel(cancel));
    controls.append(save, cancel);
    wrapper.append(label, help, dirtyStatus, toolbar, editable, error, controls);

    if (editor.recovery !== null) {
        wrapper.append(renderMessage(
            documentImpl,
            editor.recovery.message,
            editor.recovery.kind,
            {
                ...actions,
                retry: editor.recovery.action,
            }
        ).wrapper);
    }

    return { wrapper, editable };
}

function renderNotes(documentImpl, model, actions) {
    const section = element(documentImpl, "section", {
        className: "biblio-ui__section biblio-ui__private-notes",
        attributes: {
            "aria-busy": model.loading || model.paginationLoading ? "true" : "false",
        },
    });
    const heading = element(documentImpl, "h2", { text: "Privénotities" });
    const add = control(documentImpl, "Notitie toevoegen", "primary");
    add.disabled = model.mutationPending;
    add.addEventListener("click", () => actions.add(add));
    section.append(heading, add);

    if (model.loading) {
        section.append(element(documentImpl, "p", {
            text: "Privénotities laden…",
            attributes: { "aria-live": "polite" },
        }));
    }

    if (model.error !== null) {
        section.append(renderMessage(
            documentImpl,
            "Privénotities konden niet worden geladen.",
            model.error,
            actions
        ).wrapper);
    }

    let editorElement = null;
    const editElements = new Map();
    let moreElement = null;
    let paginationRecoveryElement = null;

    if (model.editor?.mode === "create") {
        ({ editable: editorElement } = renderEditor(
            documentImpl,
            model.editor,
            actions
        ));
        section.append(editorElement.parentNode ?? editorElement.parent);
    }

    if (model.items.length > 0) {
        const list = element(documentImpl, "ul", {
            className: "biblio-ui__note-list",
        });

        for (const note of model.items) {
            const item = element(documentImpl, "li", {
                className: "biblio-ui__note-card",
                attributes: { "data-private-note-id": note.private_note_id },
            });

            if (
                model.editor?.mode === "edit"
                && model.editor.privateNoteId === note.private_note_id
            ) {
                const rendered = renderEditor(documentImpl, model.editor, actions);
                editorElement = rendered.editable;
                item.append(rendered.wrapper);
            } else {
                const body = element(documentImpl, "div", {
                    className: "biblio-ui__note-body",
                });
                appendSafeHtml(documentImpl, body, note.content_html);
                const controls = element(documentImpl, "div", {
                    className: "biblio-ui__note-actions",
                });
                const edit = control(documentImpl, "Bewerken");
                const remove = control(documentImpl, "Verwijderen");
                edit.disabled = model.mutationPending;
                remove.disabled = model.mutationPending;
                edit.addEventListener("click", () => actions.edit(note, edit));
                remove.addEventListener("click", () => actions.remove(note, remove));
                editElements.set(note.private_note_id, edit);
                controls.append(edit, remove);
                item.append(body, controls);
            }

            list.append(item);
        }

        section.append(list);
    }

    if (model.nextCursor !== null) {
        const more = control(documentImpl, "Meer laden");
        more.setAttribute(
            "aria-disabled",
            model.paginationLoading ? "true" : "false"
        );
        more.addEventListener("click", actions.loadMore);
        section.append(more);
        moreElement = more;
    }

    if (model.paginationLoading) {
        section.append(element(documentImpl, "p", {
            text: "Meer privénotities laden…",
            attributes: { "aria-live": "polite" },
        }));
    }

    if (model.paginationError !== null) {
        const rendered = renderMessage(
            documentImpl,
            "Meer privénotities konden niet worden geladen.",
            model.paginationError,
            { ...actions, retry: actions.loadMore }
        );
        section.append(rendered.wrapper);
        paginationRecoveryElement = rendered.recoveryElement;
    }

    if (model.refreshWarning) {
        section.append(renderMessage(
            documentImpl,
            "De wijziging is opgeslagen, maar de notitielijst kon niet worden vernieuwd.",
            "refresh",
            { ...actions, retry: actions.refresh }
        ).wrapper);
    }

    if (model.notice !== null) {
        section.append(element(documentImpl, "p", {
            text: model.notice,
            attributes: { role: "status", "aria-live": "polite", tabindex: "-1" },
        }));
    }

    return {
        section,
        editorElement,
        heading,
        add,
        editElements,
        moreElement,
        paginationRecoveryElement,
    };
}

function focusAfterKeyboardActivation(target) {
    target.focus();
    const ownerDocument = target.ownerDocument ?? globalThis.document ?? null;

    globalThis.setTimeout(() => {
        const browserDroppedFocus = ownerDocument === null
            || ownerDocument.activeElement === null
            || ownerDocument.activeElement === ownerDocument.body;

        if (target.isConnected !== false && browserDroppedFocus) {
            target.focus();
        }
    }, 0);
}

function dialogShell(documentImpl, className, titleText, bodyText) {
    dialogSequence += 1;
    const id = `biblio-private-note-dialog-${dialogSequence}`;
    const titleId = `${id}-title`;
    const bodyId = `${id}-body`;
    const dialog = element(documentImpl, "dialog", {
        className: `biblio-ui__reading-dialog ${className}`,
        attributes: {
            "aria-labelledby": titleId,
            "aria-describedby": bodyId,
        },
    });
    const content = element(documentImpl, "div", {
        className: "biblio-ui__dialog-content",
    });
    content.append(
        element(documentImpl, "h2", { text: titleText, attributes: { id: titleId } }),
        element(documentImpl, "p", { text: bodyText, attributes: { id: bodyId } })
    );
    dialog.append(content);

    return { dialog, content };
}

export function createPrivateNotesView(root, {
    documentImpl = globalThis.document,
} = {}) {
    let activeDialog = null;

    function region() {
        return root.querySelector("[data-biblio-private-notes]");
    }

    function render(model, actions) {
        const target = region();

        if (target === null) {
            return null;
        }

        const rendered = renderNotes(documentImpl, model, actions);
        target.replaceChildren(rendered.section);

        if (model.focusEditor && rendered.editorElement !== null) {
            focusAfterKeyboardActivation(rendered.editorElement);
        }

        if (model.focusNotice) {
            rendered.section.querySelector('[role="status"]')?.focus();
        }

        if (model.focusReadTarget?.kind === "add") {
            rendered.add.focus();
        } else if (model.focusReadTarget?.kind === "edit") {
            rendered.editElements.get(model.focusReadTarget.privateNoteId)?.focus();
        } else if (model.focusReadTarget?.kind === "heading") {
            rendered.heading.setAttribute("tabindex", "-1");
            rendered.heading.focus();
        }

        if (model.focusPagination === "more") {
            rendered.moreElement?.focus();
        } else if (model.focusPagination === "heading") {
            rendered.heading.setAttribute("tabindex", "-1");
            rendered.heading.focus();
        } else if (model.focusPagination === "error") {
            rendered.paginationRecoveryElement?.focus();
        }

        return target;
    }

    function closeDialog(restoreFocus = true) {
        if (activeDialog === null) {
            return;
        }

        const { dialog, opener } = activeDialog;
        activeDialog = null;

        if (dialog.open) {
            dialog.close();
        }

        dialog.remove();

        if (restoreFocus) {
            opener?.focus();
        }
    }

    function confirmDiscard({ opener, discard, retain }) {
        if (activeDialog !== null) {
            return false;
        }

        const { dialog, content } = dialogShell(
            documentImpl,
            "biblio-ui__discard-dialog",
            "Wijzigingen niet opslaan?",
            "Je hebt wijzigingen die nog niet zijn opgeslagen."
        );
        const actions = element(documentImpl, "div", {
            className: "biblio-ui__dialog-actions",
        });
        const back = control(documentImpl, "Terug naar notitie");
        const proceed = control(documentImpl, "Doorgaan zonder opslaan", "primary");
        back.addEventListener("click", () => {
            closeDialog(false);
            retain();
        });
        proceed.addEventListener("click", () => {
            closeDialog(false);
            discard();
        });
        dialog.addEventListener("cancel", (event) => {
            event.preventDefault();
            closeDialog(false);
            retain();
        });
        actions.append(back, proceed);
        content.append(actions);
        root.append(dialog);
        activeDialog = { dialog, opener };
        dialog.showModal();
        back.focus();

        return true;
    }

    function confirmDelete({
        note,
        opener,
        submit,
        refresh,
        reload: reloadPage,
        loginUrl: loginHref,
    }) {
        if (activeDialog !== null) {
            return false;
        }

        const { dialog, content } = dialogShell(
            documentImpl,
            "biblio-ui__delete-note-dialog",
            "Privénotitie verwijderen?",
            "Deze notitie wordt definitief verwijderd. Dit kan niet ongedaan worden gemaakt."
        );
        const status = element(documentImpl, "p", {
            className: "biblio-ui__dialog-status",
            attributes: { "aria-live": "polite", tabindex: "-1" },
        });
        const actions = element(documentImpl, "div", {
            className: "biblio-ui__dialog-actions",
        });
        const cancel = control(documentImpl, "Annuleren");
        const remove = control(documentImpl, "Definitief verwijderen", "primary");
        let pending = false;

        function setPending(value) {
            pending = value;
            cancel.disabled = value;
            remove.disabled = value;
            content.setAttribute("aria-busy", value ? "true" : "false");
        }

        cancel.addEventListener("click", () => {
            if (!pending) {
                closeDialog();
            }
        });
        dialog.addEventListener("cancel", (event) => {
            event.preventDefault();

            if (!pending) {
                closeDialog();
            }
        });
        remove.addEventListener("click", async () => {
            if (pending) {
                return;
            }

            setPending(true);
            const result = await submit(note);

            if (result.state === "deleted") {
                closeDialog(false);
                region()?.querySelector('[role="status"]')?.focus();
                return;
            }

            if (activeDialog?.dialog !== dialog) {
                return;
            }

            setPending(false);

            if (["stale", "unavailable", "uncertain"].includes(result.state)) {
                status.textContent = result.state === "stale"
                    ? "Deze notitie is intussen gewijzigd. Vernieuw de notities."
                    : "Deze notitie is niet meer actueel. Vernieuw de notities.";
                const refreshButton = control(documentImpl, "Vernieuwen", "primary");
                refreshButton.addEventListener("click", async () => {
                    setPending(true);
                    const refreshed = await refresh();

                    if (refreshed) {
                        closeDialog(false);
                        const heading = region()?.querySelector("h2");
                        heading?.setAttribute("tabindex", "-1");
                        heading?.focus();
                    } else {
                        setPending(false);
                        status.textContent = "De notities konden niet worden vernieuwd.";
                        status.focus();
                    }
                });
                actions.replaceChildren(cancel, refreshButton);
            } else if (result.state === "session") {
                status.textContent = "Je sessie moet worden vernieuwd.";
                const reloadButton = control(
                    documentImpl,
                    "Sessie vernieuwen",
                    "primary"
                );
                reloadButton.addEventListener("click", reloadPage);
                actions.replaceChildren(cancel, reloadButton);
            } else if (result.state === "authentication") {
                status.textContent = "Je sessie is verlopen.";
                const loginLink = element(documentImpl, "a", {
                    className: "biblio-ui__control biblio-ui__control--primary",
                    text: "Opnieuw inloggen",
                    attributes: { href: loginHref },
                });
                actions.replaceChildren(cancel, loginLink);
            } else {
                status.textContent = "De privénotitie kon niet worden verwijderd.";
            }

            status.focus();
        });
        actions.append(cancel, remove);
        content.append(status, actions);
        root.append(dialog);
        activeDialog = { dialog, opener };
        dialog.showModal();
        cancel.focus();

        return true;
    }

    function execFormat(command) {
        const browserCommand = command === "blockquote" ? "formatBlock" : command;
        const value = command === "blockquote" ? "blockquote" : null;
        documentImpl.execCommand?.(browserCommand, false, value);

        if (command === "blockquote") {
            return String(documentImpl.queryCommandValue?.("formatBlock") ?? "")
                .toLowerCase() === "blockquote";
        }

        return Boolean(documentImpl.queryCommandState?.(browserCommand));
    }

    function pastePlainText(event) {
        event.preventDefault();
        const text = event.clipboardData?.getData("text/plain") ?? "";
        documentImpl.execCommand?.("insertText", false, text);
    }

    function destroy() {
        closeDialog(false);
    }

    return Object.freeze({
        render,
        validateHtml(html) {
            safePrivateNoteFragment(documentImpl, html);
            return true;
        },
        canonicalizeHtml(html) {
            const container = documentImpl.createElement("div");
            container.append(safePrivateNoteFragment(documentImpl, html));
            return serializePrivateNoteEditor(container);
        },
        confirmDiscard,
        confirmDelete,
        execFormat,
        pastePlainText,
        destroy,
    });
}

function errorRecovery(error) {
    if (
        error?.kind === "http"
        && error.status === 401
        && error.code === "biblio_authentication_required"
    ) {
        return "authentication";
    }

    if (
        error?.kind === "http"
        && error.status === 403
        && error.code === "rest_cookie_invalid_nonce"
    ) {
        return "session";
    }

    return "retry";
}

function isHttp(error, status, code = null) {
    return error?.kind === "http"
        && error.status === status
        && (code === null || error.code === code);
}

function isAborted(error) {
    return error?.kind === "aborted";
}

export function createPrivateNotesController({
    root,
    api,
    workId,
    signal,
    isCurrent = () => true,
    view = createPrivateNotesView(root),
    eventTarget = globalThis,
    loginUrl,
    reload,
}) {
    if (typeof workId !== "string" || workId.length === 0) {
        throw new TypeError("Authoritative Item detail Work ID is required.");
    }

    let revision = 0;
    let paginationPending = false;
    let destroyed = false;
    let editorSequence = 0;
    let beforeUnloadRegistered = false;
    let state = {
        items: [],
        nextCursor: null,
        loading: true,
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
    };

    function active() {
        return !destroyed && isCurrent() && !signal?.aborted;
    }

    function beforeUnload(event) {
        event.preventDefault();
        event.returnValue = "";
    }

    function editorDirty() {
        return state.editor !== null
            && state.editor.contentHtml !== state.editor.baselineHtml;
    }

    function syncBeforeUnload() {
        const shouldRegister = editorDirty();

        if (shouldRegister && !beforeUnloadRegistered) {
            eventTarget.addEventListener("beforeunload", beforeUnload);
            beforeUnloadRegistered = true;
        } else if (!shouldRegister && beforeUnloadRegistered) {
            eventTarget.removeEventListener("beforeunload", beforeUnload);
            beforeUnloadRegistered = false;
        }
    }

    const actions = {
        add(opener) {
            return switchEditor(() => openEditor("create", null, opener), opener);
        },
        edit(note, opener) {
            return switchEditor(() => openEditor("edit", note, opener), opener);
        },
        remove(note, opener) {
            return switchEditor(() => view.confirmDelete({
                note,
                opener,
                submit: deleteNote,
                refresh: () => refresh({ discardEditor: true }),
                reload,
                loginUrl,
            }), opener);
        },
        changed(editorRoot) {
            if (state.editor === null || state.mutationPending) {
                return false;
            }

            let dirty = true;

            try {
                state.editor.contentHtml = serializePrivateNoteEditor(editorRoot);
                state.editor.error = null;
                dirty = editorDirty();
            } catch {
                state.editor.error = "Deze opmaak kan niet veilig worden opgeslagen.";
            }

            state.editor.dirty = dirty;
            syncBeforeUnload();

            return state.editor.dirty;
        },
        format(command) {
            return view.execFormat(command);
        },
        paste(event) {
            view.pastePlainText(event);
        },
        save(editorRoot) {
            return save(editorRoot);
        },
        cancel(opener) {
            if (!editorDirty()) {
                closeEditor({ restoreFocus: true });
                render();
                return;
            }

            view.confirmDiscard({
                opener,
                discard() {
                    closeEditor({ restoreFocus: true });
                    render();
                },
                retain() {
                    state.focusEditor = true;
                    render();
                },
            });
        },
        loadMore,
        refresh: () => refresh(),
        retry: () => loadFirstPage(),
        reload,
        loginUrl,
    };

    function render() {
        if (!active()) {
            return;
        }

        view.render(state, actions);
        state.focusEditor = false;
        state.focusNotice = false;
        state.focusReadTarget = null;
        state.focusPagination = null;
    }

    function editorModel(mode, note) {
        editorSequence += 1;
        const contentHtml = note === null
            ? ""
            : (view.canonicalizeHtml?.(note.content_html) ?? note.content_html);
        const id = `biblio-private-note-editor-${editorSequence}`;

        return {
            mode,
            privateNoteId: note?.private_note_id ?? null,
            version: note?.version ?? null,
            baselineHtml: contentHtml,
            contentHtml,
            pending: false,
            dirty: false,
            error: null,
            recovery: null,
            labelId: `${id}-label`,
            helpId: `${id}-help`,
            dirtyId: `${id}-dirty`,
            errorId: `${id}-error`,
        };
    }

    function openEditor(mode, note) {
        state.editor = editorModel(mode, note);
        state.focusEditor = true;
        state.focusReadTarget = null;
        state.focusPagination = null;
        state.notice = null;
        syncBeforeUnload();
        render();
    }

    function closeEditor({ restoreFocus = false } = {}) {
        const returnTarget = state.editor === null
            ? null
            : state.editor.mode === "create"
                ? { kind: "add" }
                : {
                    kind: "edit",
                    privateNoteId: state.editor.privateNoteId,
                };
        state.editor = null;
        state.mutationPending = false;

        if (restoreFocus) {
            state.focusReadTarget = returnTarget;
        }

        syncBeforeUnload();
    }

    function switchEditor(action, opener) {
        if (!editorDirty()) {
            return action();
        }

        return view.confirmDiscard({
            opener,
            discard() {
                closeEditor();
                action();
            },
            retain() {
                state.focusEditor = true;
                render();
            },
        });
    }

    function ready(page) {
        state.items = [...page.items];
        state.nextCursor = page.nextCursor;
        state.loading = false;
        state.error = null;
        state.paginationLoading = false;
        state.paginationError = null;
    }

    function validatePage(page) {
        for (const note of page.items) {
            view.validateHtml?.(note.content_html);
        }

        return page;
    }

    async function loadFirstPage({ preserve = false, discardEditor = false } = {}) {
        const requestRevision = revision + 1;
        revision = requestRevision;

        if (!preserve) {
            state.loading = true;
            state.error = null;
        }

        state.paginationLoading = false;
        state.paginationError = null;

        if (!preserve) {
            render();
        }

        try {
            const payload = await api.get(privateNotesPath(workId), { signal });

            if (!active() || requestRevision !== revision) {
                return false;
            }

            ready(validatePage(readPrivateNotesPage(payload)));
            state.refreshWarning = false;

            if (discardEditor) {
                closeEditor();
                state.focusReadTarget = { kind: "heading" };
            } else if (preserve && state.notice !== null) {
                state.focusNotice = true;
            }

            render();
            return true;
        } catch (error) {
            if (!active() || requestRevision !== revision || isAborted(error)) {
                return false;
            }

            if (preserve) {
                state.loading = false;
                state.refreshWarning = true;

                if (state.notice !== null) {
                    state.focusNotice = true;
                } else if (state.editor !== null) {
                    state.focusEditor = true;
                }
            } else {
                state.loading = false;
                state.error = errorRecovery(error);
            }

            render();
            return false;
        }
    }

    async function loadMore() {
        if (
            paginationPending
            || state.nextCursor === null
            || state.loading
            || !active()
        ) {
            return false;
        }

        const requestRevision = revision;
        const cursor = state.nextCursor;
        paginationPending = true;
        state.paginationLoading = true;
        state.paginationError = null;
        state.focusPagination = "more";
        render();

        try {
            const payload = await api.get(privateNotesPath(workId, cursor), { signal });

            if (!active() || requestRevision !== revision) {
                return false;
            }

            const page = validatePage(readPrivateNotesPage(payload));
            const existing = new Set(state.items.map((item) => item.private_note_id));
            state.items.push(...page.items.filter(
                (item) => !existing.has(item.private_note_id)
            ));
            state.nextCursor = page.nextCursor;
            state.paginationLoading = false;
            state.focusPagination = page.nextCursor === null ? "heading" : "more";
            render();
            return true;
        } catch (error) {
            if (!active() || requestRevision !== revision || isAborted(error)) {
                return false;
            }

            state.paginationLoading = false;
            state.paginationError = errorRecovery(error);
            state.focusPagination = "error";
            render();
            return false;
        } finally {
            paginationPending = false;
        }
    }

    function mutationRecovery(error) {
        if (isHttp(error, 401, "biblio_authentication_required")) {
            return { kind: "authentication", message: "Je sessie is verlopen." };
        }

        if (isHttp(error, 403, "rest_cookie_invalid_nonce")) {
            return { kind: "session", message: "Je sessie moet worden vernieuwd." };
        }

        return null;
    }

    async function save(editorRoot) {
        if (state.editor === null || state.mutationPending || !active()) {
            return false;
        }

        let content;

        try {
            content = serializePrivateNoteEditor(editorRoot);
        } catch {
            state.editor.error = "Deze opmaak kan niet veilig worden opgeslagen.";
            state.focusEditor = true;
            render();
            return false;
        }

        state.editor.contentHtml = content;
        syncBeforeUnload();

        if (content === "") {
            state.editor.error = "Schrijf eerst een notitie.";
            state.focusEditor = true;
            render();
            return false;
        }

        const intent = { ...state.editor };
        state.mutationPending = true;
        state.editor.pending = true;
        state.editor.error = null;
        state.editor.recovery = null;
        render();

        try {
            const payload = intent.mode === "create"
                ? await api.post(
                    privateNotesPath(workId).split("?")[0],
                    { content },
                    { signal }
                )
                : await api.patch(
                    privateNotePath(intent.privateNoteId),
                    { content, expected_version: intent.version },
                    { signal }
                );

            if (!active()) {
                return false;
            }

            const note = readPrivateNote(payload);
            view.validateHtml?.(note.content_html);
            state.items = [
                note,
                ...state.items.filter(
                    (item) => item.private_note_id !== note.private_note_id
                ),
            ];
            closeEditor();
            state.notice = intent.mode === "create"
                ? "Privénotitie opgeslagen."
                : "Privénotitie bijgewerkt.";
            state.focusNotice = true;
            render();
            await loadFirstPage({ preserve: true });
            return true;
        } catch (error) {
            if (!active() || isAborted(error)) {
                return false;
            }

            state.mutationPending = false;
            state.editor.pending = false;
            const recovery = mutationRecovery(error);

            if (recovery !== null) {
                state.editor.recovery = { ...recovery, action: () => {} };
            } else if (isHttp(error, 409)) {
                state.editor.recovery = {
                    kind: "refresh",
                    message: "Deze notitie is intussen gewijzigd. Vernieuw de notities voordat je opnieuw bewerkt.",
                    action: () => loadFirstPage({ preserve: true, discardEditor: true }),
                };
            } else if (isHttp(error, 404)) {
                state.editor.recovery = {
                    kind: "refresh",
                    message: "Deze notitie is niet meer beschikbaar. Vernieuw de notities.",
                    action: () => loadFirstPage({ preserve: true, discardEditor: true }),
                };
            } else if (isHttp(error, 400) || isHttp(error, 422)) {
                state.editor.error = "De notitie kon niet worden opgeslagen. Controleer de inhoud.";
            } else if (isHttp(error, 503)) {
                state.editor.error = "Biblio is tijdelijk niet beschikbaar. Probeer het later opnieuw.";
            } else {
                state.editor.recovery = {
                    kind: "refresh",
                    message: "De uitkomst is onzeker. Vernieuw alleen de notitielijst voordat je opnieuw opslaat.",
                    action: () => loadFirstPage({ preserve: true, discardEditor: true }),
                };
            }

            state.focusEditor = true;
            render();
            return false;
        }
    }

    async function deleteNote(note) {
        if (state.mutationPending || !active()) {
            return { state: "pending" };
        }

        state.mutationPending = true;
        render();

        try {
            await api.delete(
                privateNotePath(note.private_note_id),
                { expected_version: note.version },
                { signal }
            );

            if (!active()) {
                return { state: "uncertain" };
            }

            state.items = state.items.filter(
                (item) => item.private_note_id !== note.private_note_id
            );
            state.mutationPending = false;
            state.notice = "Privénotitie verwijderd.";
            state.focusNotice = true;
            render();
            await loadFirstPage({ preserve: true });
            return { state: "deleted" };
        } catch (error) {
            if (!active() || isAborted(error)) {
                return { state: "uncertain" };
            }

            state.mutationPending = false;
            render();

            if (isHttp(error, 401, "biblio_authentication_required")) {
                return { state: "authentication" };
            }

            if (isHttp(error, 403, "rest_cookie_invalid_nonce")) {
                return { state: "session" };
            }

            if (isHttp(error, 409)) {
                return { state: "stale" };
            }

            if (isHttp(error, 404)) {
                return { state: "unavailable" };
            }

            if (
                error !== null
                && (
                    error?.kind === "network"
                    || error?.kind === "invalid_response"
                    || isHttp(error, 500)
                )
            ) {
                return { state: "uncertain" };
            }

            return { state: "error" };
        }
    }

    function guardNavigation(action, opener = null) {
        if (!editorDirty()) {
            return Promise.resolve(action());
        }

        return new Promise((resolve) => {
            view.confirmDiscard({
                opener,
                discard() {
                    closeEditor();
                    resolve(action());
                },
                retain() {
                    state.focusEditor = true;
                    render();
                    resolve(false);
                },
            });
        });
    }

    function refresh(options = {}) {
        return loadFirstPage({ preserve: true, ...options });
    }

    function destroy() {
        destroyed = true;
        revision += 1;
        view.destroy();

        if (beforeUnloadRegistered) {
            eventTarget.removeEventListener("beforeunload", beforeUnload);
            beforeUnloadRegistered = false;
        }
    }

    return Object.freeze({
        load: loadFirstPage,
        render,
        refresh,
        guardNavigation,
        isDirty: editorDirty,
        destroy,
    });
}
