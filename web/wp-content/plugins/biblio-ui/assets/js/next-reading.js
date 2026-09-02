import { BiblioApiError, createBiblioApi } from "./api.js";

const LIST_FIELDS = ["list_version", "entries"];
const ENTRY_FIELDS = ["entry_id", "position", "work", "preferred_source"];
const WORK_FIELDS = ["work_id", "title"];
const PAGE_FIELDS = ["items", "next_cursor"];
const SOURCE_STATES = new Set(["none", "available", "unavailable"]);

function record(value) {
    return value !== null && typeof value === "object" && !Array.isArray(value);
}

function exact(value, fields) {
    return record(value)
        && Object.keys(value).length === fields.length
        && fields.every((field) => Object.hasOwn(value, field));
}

function text(value) {
    return typeof value === "string" && value.length > 0;
}

export function readNextReadingWork(value) {
    if (!exact(value, WORK_FIELDS) || !text(value.work_id) || !text(value.title)) {
        throw new TypeError("The Biblio Work response is invalid.");
    }

    return Object.freeze({ work_id: value.work_id, title: value.title });
}

export function readPreferredSource(value) {
    if (!record(value) || !SOURCE_STATES.has(value.state) || !text(value.label)) {
        throw new TypeError("The Biblio preferred-source response is invalid.");
    }

    const fields = value.state === "available"
        ? ["state", "type", "label"]
        : ["state", "label"];
    if (
        !exact(value, fields)
        || (value.state === "available"
            && !["library_item", "external_loan"].includes(value.type))
    ) {
        throw new TypeError("The Biblio preferred-source response is invalid.");
    }

    return Object.freeze({ ...value });
}

export function readNextReadingList(value) {
    if (
        !exact(value, LIST_FIELDS)
        || !Number.isInteger(value.list_version)
        || value.list_version < 1
        || !Array.isArray(value.entries)
    ) {
        throw new TypeError("The Biblio Next Reading response is invalid.");
    }

    const entries = value.entries.map((entry, index) => {
        if (
            !exact(entry, ENTRY_FIELDS)
            || !text(entry.entry_id)
            || entry.position !== index + 1
            || !exact(entry.work, WORK_FIELDS)
        ) {
            throw new TypeError("The Biblio Next Reading entry is invalid.");
        }

        return Object.freeze({
            entry_id: entry.entry_id,
            position: entry.position,
            work: readNextReadingWork(entry.work),
            preferred_source: readPreferredSource(entry.preferred_source),
        });
    });

    return Object.freeze({
        list_version: value.list_version,
        entries: Object.freeze(entries),
    });
}

export function readWorkPage(value) {
    if (
        !exact(value, PAGE_FIELDS)
        || !Array.isArray(value.items)
        || !(value.next_cursor === null || text(value.next_cursor))
    ) {
        throw new TypeError("The Biblio Work discovery response is invalid.");
    }

    return Object.freeze({
        items: Object.freeze(value.items.map(readNextReadingWork)),
        next_cursor: value.next_cursor,
    });
}

export function readSourceOptions(value) {
    if (!exact(value, ["items"]) || !Array.isArray(value.items)) {
        throw new TypeError("The Biblio source-options response is invalid.");
    }

    return Object.freeze(value.items.map((option) => {
        if (!record(option) || !text(option.label)) {
            throw new TypeError("The Biblio source option is invalid.");
        }
        if (
            option.type === "library_item"
            && exact(option, ["type", "library_id", "item_id", "label"])
            && text(option.library_id)
            && text(option.item_id)
        ) {
            return Object.freeze({ ...option });
        }
        if (
            option.type === "external_loan"
            && exact(option, ["type", "external_loan_id", "label"])
            && text(option.external_loan_id)
        ) {
            return Object.freeze({ ...option });
        }
        throw new TypeError("The Biblio source option is invalid.");
    }));
}

export function sourceRequest(option) {
    if (option === null) {
        return null;
    }
    const validated = readSourceOptions({ items: [option] })[0];

    return validated.type === "library_item"
        ? { type: validated.type, library_id: validated.library_id, item_id: validated.item_id }
        : { type: validated.type, external_loan_id: validated.external_loan_id };
}

export function movedEntryIds(entries, entryId, direction) {
    const ids = entries.map((entry) => entry.entry_id);
    const index = ids.indexOf(entryId);
    const target = direction === "up" ? index - 1 : index + 1;

    if (index < 0 || target < 0 || target >= ids.length) {
        return ids;
    }
    [ids[index], ids[target]] = [ids[target], ids[index]];
    return ids;
}

function el(documentImpl, name, { className, textContent, attrs = {} } = {}) {
    const node = documentImpl.createElement(name);
    if (className) node.className = className;
    if (textContent !== undefined) node.textContent = textContent;
    for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, value);
    return node;
}

function button(documentImpl, label, action, primary = false) {
    return el(documentImpl, "button", {
        className: `biblio-ui__control biblio-ui__control--${primary ? "primary" : "secondary"}`,
        textContent: label,
        attrs: { type: "button", "data-action": action },
    });
}

export function nextReadingErrorMessage(error) {
    if (error instanceof BiblioApiError && error.status === 401) {
        return "Je sessie is verlopen. Log opnieuw in.";
    }
    if (error instanceof BiblioApiError && error.status === 409) {
        if (error.code === "biblio_next_reading_undo_unavailable") {
            return "Ongedaan maken is niet meer beschikbaar.";
        }
        return "De lijst was intussen gewijzigd en is opnieuw geladen.";
    }
    return "Dat lukte niet. Probeer het opnieuw.";
}

export function createNextReadingApp({ root, api, documentImpl = document }) {
    let list = null;
    let busy = false;
    let undo = null;
    let searchRevision = 0;
    let searchController = null;

    const view = el(documentImpl, "section", {
        className: "biblio-ui__view biblio-ui__next-reading",
        attrs: { "aria-labelledby": "biblio-next-reading-title" },
    });
    const title = el(documentImpl, "h1", {
        className: "biblio-ui__page-title",
        textContent: "Hierna lezen",
        attrs: { id: "biblio-next-reading-title", tabindex: "-1" },
    });
    const status = el(documentImpl, "div", {
        className: "biblio-ui__mutation-status biblio-ui__visually-hidden",
        attrs: { role: "status", "aria-live": "polite", "aria-atomic": "true" },
    });
    const content = el(documentImpl, "div", { className: "biblio-ui__next-reading-content" });
    view.append(title, status, content);
    root.replaceChildren(view);

    function announce(message) {
        status.textContent = "";
        queueMicrotask(() => { status.textContent = message; });
    }

    function focusEntry(entryId, action = null) {
        queueMicrotask(() => {
            const selector = action === null
                ? `[data-entry-id="${CSS.escape(entryId)}"]`
                : `[data-entry-id="${CSS.escape(entryId)}"] [data-action="${action}"]`;
            (content.querySelector(selector) ?? title).focus();
        });
    }

    function setBusy(value) {
        busy = value;
        for (const control of view.querySelectorAll("button")) control.disabled = value;
    }

    function renderError(error) {
        const box = el(documentImpl, "div", {
            className: "biblio-ui__inline-error",
            attrs: { role: "alert" },
        });
        box.append(el(documentImpl, "p", {
            textContent: nextReadingErrorMessage(error),
        }));
        if (
            error instanceof BiblioApiError
            && error.status === 401
            && text(root.dataset.loginUrl)
        ) {
            box.append(el(documentImpl, "a", {
                className: "biblio-ui__control biblio-ui__control--secondary",
                textContent: "Opnieuw inloggen",
                attrs: { href: root.dataset.loginUrl },
            }));
            content.replaceChildren(box);
            return;
        }
        const retry = button(documentImpl, "Opnieuw proberen", "retry");
        retry.addEventListener("click", load);
        box.append(retry);
        content.replaceChildren(box);
    }

    function render() {
        if (list === null) return;
        const fragment = documentImpl.createDocumentFragment();
        const add = button(documentImpl, "Boek toevoegen", "add", true);
        add.addEventListener("click", () => openAddDialog(add));

        if (list.entries.length === 0) {
            const empty = el(documentImpl, "div", { className: "biblio-ui__next-reading-empty" });
            empty.append(
                el(documentImpl, "p", { textContent: "Nog niets gepland om hierna te lezen." }),
                add
            );
            fragment.append(empty);
        } else {
            const header = el(documentImpl, "div", { className: "biblio-ui__next-reading-header" });
            header.append(add);
            const rows = el(documentImpl, "ol", { className: "biblio-ui__next-reading-list" });
            list.entries.forEach((entry, index) => rows.append(renderEntry(entry, index)));
            fragment.append(header, rows);
        }

        if (undo !== null) fragment.append(renderUndo());
        content.replaceChildren(fragment);
    }

    function renderEntry(entry, index) {
        const row = el(documentImpl, "li", {
            className: "biblio-ui__next-reading-row",
            attrs: { "data-entry-id": entry.entry_id, tabindex: "-1" },
        });
        const heading = el(documentImpl, "h2", { textContent: entry.work.title });
        const source = el(documentImpl, "p", {
            className: "biblio-ui__next-reading-source",
            textContent: entry.preferred_source.label,
        });
        const actions = el(documentImpl, "div", { className: "biblio-ui__next-reading-actions" });
        const chooseLabel = entry.preferred_source.state === "none"
            ? "Voorkeursbron kiezen"
            : entry.preferred_source.state === "available"
                ? "Voorkeursbron wijzigen"
                : "Andere bron kiezen";
        const choose = button(documentImpl, chooseLabel, "source");
        choose.addEventListener("click", () => openSourceDialog(entry, choose));
        actions.append(choose);

        if (entry.preferred_source.state !== "none") {
            const clear = button(documentImpl, "Voorkeur verwijderen", "clear-source");
            clear.addEventListener("click", () => clearSource(entry));
            actions.append(clear);
        }
        const up = button(documentImpl, "Omhoog", "up");
        up.disabled = index === 0;
        up.addEventListener("click", () => reorder(entry, "up"));
        const down = button(documentImpl, "Omlaag", "down");
        down.disabled = index === list.entries.length - 1;
        down.addEventListener("click", () => reorder(entry, "down"));
        const remove = button(documentImpl, "Verwijderen", "remove");
        remove.addEventListener("click", () => removeEntry(entry));
        actions.append(up, down, remove);
        row.append(heading, source, actions);
        return row;
    }

    function renderUndo() {
        const box = el(documentImpl, "div", {
            className: "biblio-ui__mutation-status biblio-ui__next-reading-undo",
            attrs: { role: "status" },
        });
        const action = button(documentImpl, "Ongedaan maken", "undo");
        action.addEventListener("click", undoRemoval);
        box.append(el(documentImpl, "p", { textContent: "Boek verwijderd." }), action);
        return box;
    }

    async function load({ announceResult = false } = {}) {
        content.replaceChildren(el(documentImpl, "p", {
            className: "biblio-ui__loading",
            textContent: "Hierna-lezen-lijst laden…",
            attrs: { role: "status" },
        }));
        try {
            list = readNextReadingList(await api.get("me/next-reading"));
            render();
            if (announceResult) announce("De actuele lijst is geladen.");
        } catch (error) {
            renderError(error);
        }
    }

    async function mutation(operation, success, focus) {
        if (busy) return;
        setBusy(true);
        try {
            list = readNextReadingList(await operation());
            render();
            announce(success);
            if (focus) focus();
        } catch (error) {
            if (error instanceof BiblioApiError && error.status === 409) {
                await load();
            }
            announce(nextReadingErrorMessage(error));
        } finally {
            setBusy(false);
        }
    }

    function reorder(entry, direction) {
        const ids = movedEntryIds(list.entries, entry.entry_id, direction);
        mutation(
            () => api.post("me/next-reading/reorder", {
                ordered_entry_ids: ids,
                expected_version: list.list_version,
            }),
            `“${entry.work.title}” is verplaatst.`,
            () => focusEntry(entry.entry_id, direction)
        );
    }

    async function removeEntry(entry) {
        if (busy) return;
        setBusy(true);
        try {
            const result = await api.delete(`me/next-reading/${encodeURIComponent(entry.entry_id)}`, {
                expected_version: list.list_version,
            });
            if (!exact(result, ["list", "undo"]) || !exact(result.undo, ["token", "expires_at"]) || !text(result.undo.token) || !text(result.undo.expires_at)) {
                throw new TypeError("The Biblio removal response is invalid.");
            }
            list = readNextReadingList(result.list);
            undo = { ...result.undo, entry_id: entry.entry_id };
            render();
            announce(`“${entry.work.title}” is verwijderd. Ongedaan maken is beschikbaar.`);
            queueMicrotask(() => content.querySelector('[data-action="undo"]')?.focus());
        } catch (error) {
            if (error instanceof BiblioApiError && error.status === 409) await load();
            announce(nextReadingErrorMessage(error));
        } finally {
            setBusy(false);
        }
    }

    async function undoRemoval() {
        if (undo === null) return;
        const restoredId = undo.entry_id;
        const token = undo.token;
        if (busy) return;
        setBusy(true);
        try {
            list = readNextReadingList(await api.post("me/next-reading/undo", {
                undo_token: token,
            }));
            undo = null;
            render();
            announce("Het verwijderde boek is teruggezet.");
            focusEntry(restoredId);
        } catch (error) {
            if (
                error instanceof BiblioApiError
                && error.code === "biblio_next_reading_undo_unavailable"
            ) {
                undo = null;
                render();
            }
            announce(nextReadingErrorMessage(error));
        } finally {
            setBusy(false);
        }
    }

    function clearSource(entry) {
        mutation(
            () => api.delete(`me/next-reading/${encodeURIComponent(entry.entry_id)}/preferred-source`, {
                expected_version: list.list_version,
            }),
            "De voorkeursbron is verwijderd.",
            () => focusEntry(entry.entry_id, "source")
        );
    }

    function dialogShell(label, opener) {
        const dialog = el(documentImpl, "dialog", {
            className: "biblio-ui__reading-dialog biblio-ui__next-reading-dialog",
            attrs: { "aria-label": label },
        });
        const close = button(documentImpl, "Annuleren", "cancel");
        close.addEventListener("click", () => dialog.close());
        dialog.addEventListener("close", () => {
            dialog.remove();
            opener?.focus();
        });
        root.append(dialog);
        return { dialog, close };
    }

    function sourceChoice(options, selected = "") {
        const fieldset = el(documentImpl, "fieldset", { className: "biblio-ui__source-options" });
        fieldset.append(el(documentImpl, "legend", { textContent: "Voorkeursbron (optioneel)" }));
        const choices = [{ key: "", label: "Geen voorkeursbron", option: null }, ...options.map((option, index) => ({ key: String(index), label: option.label, option }))];
        for (const choice of choices) {
            const label = el(documentImpl, "label", { className: "biblio-ui__radio-option" });
            const input = el(documentImpl, "input", { attrs: { type: "radio", name: "preferred-source", value: choice.key } });
            input.checked = choice.key === selected;
            label.append(input, documentImpl.createTextNode(choice.label));
            fieldset.append(label);
        }
        return { fieldset, choices };
    }

    async function openSourceDialog(entry, opener) {
        const { dialog, close } = dialogShell("Voorkeursbron kiezen", opener);
        dialog.append(el(documentImpl, "h2", { textContent: `Voorkeursbron voor ${entry.work.title}` }), el(documentImpl, "p", { textContent: "Bronnen laden…", attrs: { role: "status" } }), close);
        dialog.showModal();
        try {
            const options = readSourceOptions(await api.get(`me/works/${encodeURIComponent(entry.work.work_id)}/preferred-source-options`));
            const form = el(documentImpl, "form");
            const { fieldset, choices } = sourceChoice(options);
            const save = button(documentImpl, "Opslaan", "save-source", true);
            save.setAttribute("type", "submit");
            form.append(fieldset, el(documentImpl, "div", { className: "biblio-ui__dialog-actions" }));
            form.lastElementChild.append(save, close);
            form.addEventListener("submit", async (event) => {
                event.preventDefault();
                if (busy) return;
                const choice = choices.find(({ key }) => key === new FormData(form).get("preferred-source"));
                if (!choice) return;
                if (choice.option === null) {
                    if (entry.preferred_source.state !== "none") clearSource(entry);
                    dialog.close();
                    return;
                }
                dialog.close();
                mutation(
                    () => api.patch(`me/next-reading/${encodeURIComponent(entry.entry_id)}/preferred-source`, {
                        preferred_source: sourceRequest(choice.option),
                        expected_version: list.list_version,
                    }),
                    "De voorkeursbron is opgeslagen.",
                    () => focusEntry(entry.entry_id, "source")
                );
            });
            dialog.replaceChildren(el(documentImpl, "h2", { textContent: `Voorkeursbron voor ${entry.work.title}` }), form);
            fieldset.querySelector("input")?.focus();
        } catch (error) {
            dialog.replaceChildren(el(documentImpl, "h2", { textContent: "Voorkeursbron kiezen" }), el(documentImpl, "p", { textContent: nextReadingErrorMessage(error), attrs: { role: "alert" } }), close);
        }
    }

    function openAddDialog(opener) {
        const { dialog, close } = dialogShell("Boek toevoegen", opener);
        const heading = el(documentImpl, "h2", { textContent: "Boek toevoegen" });
        const form = el(documentImpl, "form", { className: "biblio-ui__work-search" });
        const label = el(documentImpl, "label", { textContent: "Zoek op titel", attrs: { for: "biblio-work-search" } });
        const input = el(documentImpl, "input", { attrs: { id: "biblio-work-search", name: "q", type: "search", maxlength: "100", required: "" } });
        const search = button(documentImpl, "Zoeken", "search", true);
        search.setAttribute("type", "submit");
        const results = el(documentImpl, "div", { className: "biblio-ui__work-results" });
        const searchStatus = el(documentImpl, "p", { attrs: { role: "status", "aria-live": "polite" } });
        form.append(label, input, search, searchStatus, results, close);
        dialog.append(heading, form);
        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            const query = input.value.trim();
            if (!query) return;
            const revision = ++searchRevision;
            searchController?.abort();
            searchController = new AbortController();
            search.disabled = true;
            searchStatus.textContent = "Zoeken…";
            try {
                const page = readWorkPage(await api.get(`me/works?q=${encodeURIComponent(query)}&limit=10`, { signal: searchController.signal }));
                if (revision !== searchRevision) return;
                results.replaceChildren();
                for (const work of page.items) {
                    const select = button(documentImpl, work.title, "select-work");
                    select.addEventListener("click", () => selectWork(work, dialog, opener));
                    results.append(select);
                }
                searchStatus.textContent = page.items.length === 0 ? "Geen boeken gevonden." : `${page.items.length} boeken gevonden.`;
            } catch (error) {
                if (!(error instanceof BiblioApiError && error.kind === "aborted")) searchStatus.textContent = nextReadingErrorMessage(error);
            } finally {
                if (revision === searchRevision) search.disabled = false;
            }
        });
        dialog.addEventListener("close", () => searchController?.abort(), { once: true });
        dialog.showModal();
        input.focus();
    }

    async function selectWork(work, dialog, opener) {
        dialog.replaceChildren(el(documentImpl, "h2", { textContent: work.title }), el(documentImpl, "p", { textContent: "Bronnen laden…", attrs: { role: "status" } }));
        try {
            const options = readSourceOptions(await api.get(`me/works/${encodeURIComponent(work.work_id)}/preferred-source-options`));
            const form = el(documentImpl, "form");
            const { fieldset, choices } = sourceChoice(options);
            const add = button(documentImpl, "Toevoegen", "submit-add", true);
            add.setAttribute("type", "submit");
            const cancel = button(documentImpl, "Annuleren", "cancel");
            cancel.addEventListener("click", () => dialog.close());
            const actions = el(documentImpl, "div", { className: "biblio-ui__dialog-actions" });
            actions.append(add, cancel);
            form.append(fieldset, actions);
            form.addEventListener("submit", async (event) => {
                event.preventDefault();
                if (busy) return;
                const selected = choices.find(({ key }) => key === new FormData(form).get("preferred-source"));
                if (!selected) return;
                const previousIds = new Set(list.entries.map((entry) => entry.entry_id));
                dialog.close();
                await mutation(
                    () => api.post("me/next-reading", { work_id: work.work_id, preferred_source: sourceRequest(selected.option) }),
                    `“${work.title}” is toegevoegd.`,
                    () => {
                        const added = list.entries.find((entry) => !previousIds.has(entry.entry_id));
                        if (added) focusEntry(added.entry_id);
                        else title.focus();
                    }
                );
            });
            dialog.replaceChildren(el(documentImpl, "h2", { textContent: work.title }), form);
            fieldset.querySelector("input")?.focus();
        } catch (error) {
            dialog.replaceChildren(el(documentImpl, "h2", { textContent: work.title }), el(documentImpl, "p", { textContent: nextReadingErrorMessage(error), attrs: { role: "alert" } }));
        }
    }

    return Object.freeze({ load, destroy() { searchController?.abort(); root.replaceChildren(); } });
}

function bootstrap() {
    for (const root of document.querySelectorAll("[data-biblio-next-reading-root]")) {
        const api = createBiblioApi({ restRoot: root.dataset.restRoot, restNonce: root.dataset.restNonce });
        createNextReadingApp({ root, api }).load();
    }
}

if (typeof document !== "undefined") {
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", bootstrap, { once: true });
    else bootstrap();
}
