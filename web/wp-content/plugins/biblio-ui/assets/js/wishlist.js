import { BiblioApiError, createBiblioApi } from "./api.js";
import { createLibraryShell } from "./ui-shell.js";
import { readWorkPage } from "./work-discovery.js";

const LIST_FIELDS = ["entries"];
const ENTRY_FIELDS = [
    "wishlist_entry_id",
    "target_type",
    "work_id",
    "edition_id",
    "display_title",
    "authors",
    "created_at",
    "updated_at",
];
const AUTHOR_FIELDS = ["author_id", "display_name"];
const TARGET_TYPES = new Set(["work_only", "edition_specific"]);
const UTC_MICROSECOND = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/;

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

function timestamp(value) {
    return typeof value === "string"
        && UTC_MICROSECOND.test(value)
        && !Number.isNaN(Date.parse(value));
}

export function readWishlistEntry(value) {
    if (
        !exact(value, ENTRY_FIELDS)
        || !text(value.wishlist_entry_id)
        || !TARGET_TYPES.has(value.target_type)
        || !text(value.work_id)
        || !text(value.display_title)
        || !Array.isArray(value.authors)
        || !timestamp(value.created_at)
        || !timestamp(value.updated_at)
        || (value.target_type === "work_only" && value.edition_id !== null)
        || (value.target_type === "edition_specific" && !text(value.edition_id))
    ) {
        throw new TypeError("The Biblio Wishlist entry is invalid.");
    }

    const authors = value.authors.map((author) => {
        if (
            !exact(author, AUTHOR_FIELDS)
            || !text(author.author_id)
            || !text(author.display_name)
        ) {
            throw new TypeError("The Biblio Wishlist Author is invalid.");
        }
        return Object.freeze({ ...author });
    });

    return Object.freeze({
        ...value,
        authors: Object.freeze(authors),
    });
}

export function readWishlistList(value) {
    if (!exact(value, LIST_FIELDS) || !Array.isArray(value.entries)) {
        throw new TypeError("The Biblio Wishlist response is invalid.");
    }

    return Object.freeze({
        entries: Object.freeze(value.entries.map(readWishlistEntry)),
    });
}

export function wishlistErrorMessage(error) {
    if (error instanceof BiblioApiError && error.status === 401) {
        return "Je sessie is verlopen. Log opnieuw in.";
    }
    if (
        error instanceof BiblioApiError
        && error.status === 409
        && error.code === "biblio_wishlist_intent_conflict"
    ) {
        return "Er staan al specifieke uitgaven van dit boek op je verlanglijst. Biblio voegt daarom geen algemene boekwens toe en verwijdert niets.";
    }
    if (error instanceof BiblioApiError && error.status === 404) {
        return "Deze wens of uitgave is niet meer beschikbaar.";
    }
    return "Dat lukte niet. Probeer het opnieuw.";
}

function el(documentImpl, tagName, {
    className,
    textContent,
    attrs = {},
} = {}) {
    const node = documentImpl.createElement(tagName);
    if (className !== undefined) node.className = className;
    if (textContent !== undefined) node.textContent = textContent;
    for (const [name, value] of Object.entries(attrs)) node.setAttribute(name, value);
    return node;
}

function button(documentImpl, label, action, modifier = "secondary") {
    return el(documentImpl, "button", {
        className: `biblio-ui__control biblio-ui__control--${modifier}`,
        textContent: label,
        attrs: { type: "button", "data-action": action },
    });
}

function authorLabel(authors) {
    return authors.map((author) => author.display_name).join(", ");
}

function addedLabel(value) {
    return new Intl.DateTimeFormat("nl-NL", {
        day: "numeric",
        month: "long",
        year: "numeric",
        timeZone: "UTC",
    }).format(new Date(value));
}

function focusEntry(content, entryId) {
    queueMicrotask(() => {
        const row = [...content.querySelectorAll("[data-entry-id]")]
            .find((candidate) => candidate.dataset.entryId === entryId);
        (row?.querySelector('[data-action="remove"]') ?? row)?.focus();
    });
}

function dialogShell(documentImpl, host, label, opener) {
    const dialog = el(documentImpl, "dialog", {
        className: "biblio-ui__reading-dialog biblio-ui__wishlist-dialog",
        attrs: { "aria-label": label },
    });
    dialog.addEventListener("close", () => {
        dialog.remove();
        opener?.focus();
    });
    host.append(dialog);
    return dialog;
}

export function createWishlistApp({
    root,
    api,
    documentImpl = globalThis.document,
    eventTarget = globalThis,
    shellFactory = createLibraryShell,
    abortControllerFactory = () => new AbortController(),
} = {}) {
    if (typeof root?.replaceChildren !== "function") {
        throw new TypeError("A Biblio Wishlist mount is required.");
    }

    const shell = shellFactory(root, {
        documentImpl,
        eventTarget,
        overviewUrl: root.dataset.overviewUrl,
        wishlistUrl: root.dataset.wishlistUrl,
        nextReadingUrl: root.dataset.nextReadingUrl,
        activeDestination: "wishlist",
    });
    const host = shell.contentRoot;
    const view = el(documentImpl, "section", {
        className: "biblio-ui__view biblio-ui__wishlist",
        attrs: { "aria-labelledby": "biblio-wishlist-title" },
    });
    const headingGroup = el(documentImpl, "header", {
        className: "biblio-ui__wishlist-page-header",
    });
    headingGroup.append(
        el(documentImpl, "p", {
            className: "biblio-ui__eyebrow",
            textContent: "Mijn Biblio",
        }),
        el(documentImpl, "h1", {
            className: "biblio-ui__page-title",
            textContent: "Verlanglijst",
            attrs: { id: "biblio-wishlist-title", tabindex: "-1" },
        }),
        el(documentImpl, "p", {
            className: "biblio-ui__wishlist-intro",
            textContent: "Bewaar boeken en concrete uitgaven die je later wilt vinden.",
        })
    );
    const live = el(documentImpl, "div", {
        className: "biblio-ui__visually-hidden",
        attrs: { role: "status", "aria-live": "polite", "aria-atomic": "true" },
    });
    const content = el(documentImpl, "div", {
        className: "biblio-ui__wishlist-content",
    });
    view.append(headingGroup, live, content);
    host.replaceChildren(view);

    let state = Object.freeze({ name: "loading", entries: Object.freeze([]) });
    let pending = false;
    let searchRevision = 0;
    let searchController = null;
    let destroyed = false;

    function announce(message) {
        live.textContent = "";
        queueMicrotask(() => { live.textContent = message; });
    }

    function setPending(value) {
        pending = value;
        view.setAttribute("aria-busy", value ? "true" : "false");
        for (const control of view.querySelectorAll("button")) {
            control.disabled = value;
        }
    }

    function addButton() {
        const add = button(documentImpl, "Boek toevoegen aan verlanglijst", "add", "primary");
        add.addEventListener("click", () => openAddDialog(add));
        return add;
    }

    function renderEntry(entry, index) {
        const row = el(documentImpl, "li", {
            className: "biblio-ui__wishlist-row",
            attrs: { "data-entry-id": entry.wishlist_entry_id, tabindex: "-1" },
        });
        const identity = el(documentImpl, "div", {
            className: "biblio-ui__wishlist-identity",
        });
        identity.append(el(documentImpl, "h2", { textContent: entry.display_title }));
        const authors = authorLabel(entry.authors);
        if (authors.length > 0) {
            identity.append(el(documentImpl, "p", {
                className: "biblio-ui__authors",
                textContent: authors,
            }));
        }
        const targetLabel = entry.target_type === "work_only"
            ? "Uitgave maakt niet uit"
            : "Specifieke uitgave";
        identity.append(
            el(documentImpl, "p", {
                className: "biblio-ui__wishlist-target",
                textContent: targetLabel,
            }),
            el(documentImpl, "p", {
                className: "biblio-ui__wishlist-added",
                textContent: `Toegevoegd op ${addedLabel(entry.created_at)}`,
            })
        );
        const remove = button(documentImpl, `“${entry.display_title}” verwijderen`, "remove");
        remove.className += " biblio-ui__wishlist-remove";
        remove.addEventListener("click", () => removeEntry(entry, index));
        row.append(identity, remove);
        if (state.mutationErrorEntryId === entry.wishlist_entry_id) {
            row.append(el(documentImpl, "p", {
                className: "biblio-ui__inline-error",
                textContent: "Verwijderen lukte niet. De wens staat nog op je lijst.",
                attrs: { role: "alert" },
            }));
        }
        return row;
    }

    function render() {
        if (state.name === "loading") {
            content.replaceChildren(el(documentImpl, "p", {
                className: "biblio-ui__loading",
                textContent: "Verlanglijst laden…",
                attrs: { role: "status" },
            }));
            return;
        }

        if (state.name === "error") {
            const error = el(documentImpl, "div", {
                className: "biblio-ui__inline-error",
                attrs: { role: "alert" },
            });
            error.append(el(documentImpl, "p", {
                textContent: wishlistErrorMessage(state.error),
            }));
            if (
                state.error instanceof BiblioApiError
                && state.error.status === 401
                && text(root.dataset.loginUrl)
            ) {
                error.append(el(documentImpl, "a", {
                    className: "biblio-ui__control biblio-ui__control--secondary",
                    textContent: "Opnieuw inloggen",
                    attrs: { href: root.dataset.loginUrl },
                }));
            } else {
                const retry = button(documentImpl, "Opnieuw proberen", "retry");
                retry.addEventListener("click", () => load());
                error.append(retry);
            }
            content.replaceChildren(error);
            return;
        }

        if (state.entries.length === 0) {
            const empty = el(documentImpl, "div", {
                className: "biblio-ui__wishlist-empty",
            });
            empty.append(
                el(documentImpl, "h2", { textContent: "Je verlanglijst is nog leeg" }),
                el(documentImpl, "p", {
                    textContent: "Bewaar hier een boek dat je later wilt lezen of aanschaffen.",
                }),
                addButton()
            );
            content.replaceChildren(empty);
            return;
        }

        const toolbar = el(documentImpl, "div", {
            className: "biblio-ui__wishlist-toolbar",
        });
        toolbar.append(
            el(documentImpl, "p", {
                textContent: `${state.entries.length} ${state.entries.length === 1 ? "wens" : "wensen"}`,
            }),
            addButton()
        );
        const list = el(documentImpl, "ul", {
            className: "biblio-ui__wishlist-list",
        });
        state.entries.forEach((entry, index) => list.append(renderEntry(entry, index)));
        content.replaceChildren(toolbar, list);
    }

    async function load({ focusHeading = false } = {}) {
        if (destroyed) return;
        state = Object.freeze({ name: "loading", entries: state.entries });
        render();
        try {
            const list = readWishlistList(await api.get("me/wishlist"));
            if (destroyed) return;
            state = Object.freeze({ name: "ready", entries: list.entries });
            render();
            if (focusHeading) {
                queueMicrotask(() => view.querySelector("h1")?.focus());
            }
        } catch (error) {
            if (destroyed || error?.kind === "aborted") return;
            state = Object.freeze({ name: "error", entries: state.entries, error });
            render();
        }
    }

    async function removeEntry(entry, index) {
        if (pending) return;
        setPending(true);
        try {
            await api.delete(`me/wishlist/${encodeURIComponent(entry.wishlist_entry_id)}`);
            const entries = state.entries.filter(
                (candidate) => candidate.wishlist_entry_id !== entry.wishlist_entry_id
            );
            state = Object.freeze({ name: "ready", entries: Object.freeze(entries) });
            render();
            announce(`“${entry.display_title}” is van je verlanglijst verwijderd.`);
            const next = entries[Math.min(index, entries.length - 1)];
            if (next !== undefined) focusEntry(content, next.wishlist_entry_id);
            else queueMicrotask(() => content.querySelector('[data-action="add"]')?.focus());
        } catch (error) {
            state = Object.freeze({
                name: "ready",
                entries: state.entries,
                mutationErrorEntryId: entry.wishlist_entry_id,
            });
            render();
            announce(wishlistErrorMessage(error));
            focusEntry(content, entry.wishlist_entry_id);
        } finally {
            setPending(false);
        }
    }

    function conflictContent(dialog, work) {
        const heading = el(documentImpl, "h2", {
            textContent: "Algemene wens niet toegevoegd",
            attrs: { tabindex: "-1" },
        });
        const explanation = el(documentImpl, "p", {
            textContent: "Er staan al specifieke uitgaven van dit boek op je verlanglijst. Biblio verwijdert of voegt die niet automatisch samen.",
        });
        const existing = state.entries.filter(
            (entry) => entry.work_id === work.work_id
                && entry.target_type === "edition_specific"
        );
        const list = el(documentImpl, "ul", {
            className: "biblio-ui__wishlist-conflict-list",
        });
        for (const entry of existing) {
            list.append(el(documentImpl, "li", {
                textContent: `${entry.display_title} — specifieke uitgave`,
            }));
        }
        const close = button(documentImpl, "Sluiten", "close-conflict");
        close.addEventListener("click", () => dialog.close());
        dialog.replaceChildren(heading, explanation, list, close);
        heading.focus();
    }

    function openAddDialog(opener) {
        const dialog = dialogShell(documentImpl, host, "Boek toevoegen aan verlanglijst", opener);
        const heading = el(documentImpl, "h2", { textContent: "Boek toevoegen" });
        const form = el(documentImpl, "form", { className: "biblio-ui__work-search" });
        const inputId = "biblio-wishlist-work-search";
        const label = el(documentImpl, "label", {
            textContent: "Zoek op titel of auteur",
            attrs: { for: inputId },
        });
        const input = el(documentImpl, "input", {
            attrs: {
                id: inputId,
                name: "q",
                type: "search",
                maxlength: "100",
                required: "",
                autocomplete: "off",
            },
        });
        const search = button(documentImpl, "Zoeken", "search", "primary");
        search.setAttribute("type", "submit");
        const searchStatus = el(documentImpl, "p", {
            attrs: { role: "status", "aria-live": "polite" },
        });
        const results = el(documentImpl, "ul", {
            className: "biblio-ui__work-results biblio-ui__wishlist-work-results",
        });
        const cancel = button(documentImpl, "Annuleren", "cancel");
        cancel.addEventListener("click", () => dialog.close());
        form.append(label, input, search, searchStatus, results, cancel);
        dialog.append(heading, form);

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            const query = input.value.trim();
            if (query.length === 0) return;
            const revision = ++searchRevision;
            searchController?.abort();
            searchController = abortControllerFactory();
            search.disabled = true;
            searchStatus.textContent = "Zoeken…";
            results.replaceChildren();
            try {
                const page = readWorkPage(await api.get(
                    `me/works?q=${encodeURIComponent(query)}&limit=10`,
                    { signal: searchController.signal }
                ));
                if (revision !== searchRevision || !dialog.open) return;
                for (const work of page.items) {
                    const item = el(documentImpl, "li");
                    const select = button(documentImpl, work.title, "select-work");
                    const authors = authorLabel(work.authors);
                    if (authors.length > 0) {
                        select.setAttribute("aria-label", `${work.title}, ${authors}`);
                    }
                    select.addEventListener("click", () => addWorkOnly(work, dialog));
                    item.append(select);
                    if (authors.length > 0) item.append(el(documentImpl, "p", { textContent: authors }));
                    if (work.series.length > 0) {
                        item.append(el(documentImpl, "p", {
                            className: "biblio-ui__context",
                            textContent: work.series.map((series) => series.display_name).join(", "),
                        }));
                    }
                    results.append(item);
                }
                searchStatus.textContent = page.items.length === 0
                    ? "Geen boeken gevonden."
                    : `${page.items.length} ${page.items.length === 1 ? "boek" : "boeken"} gevonden.`;
                results.querySelector("button")?.focus();
            } catch (error) {
                if (error?.kind !== "aborted" && revision === searchRevision) {
                    searchStatus.textContent = wishlistErrorMessage(error);
                }
            } finally {
                if (revision === searchRevision) search.disabled = false;
            }
        });
        dialog.addEventListener("close", () => searchController?.abort(), { once: true });
        dialog.showModal();
        input.focus();
    }

    async function addWorkOnly(work, dialog) {
        if (pending) return;
        setPending(true);
        dialog.setAttribute("aria-busy", "true");
        const before = state.entries.find(
            (entry) => entry.work_id === work.work_id
                && entry.target_type === "work_only"
        );
        try {
            const added = readWishlistEntry(await api.post("me/wishlist", {
                target: { type: "work_only", work_id: work.work_id },
            }));
            const list = readWishlistList(await api.get("me/wishlist"));
            state = Object.freeze({ name: "ready", entries: list.entries });
            render();
            dialog.close();
            announce(before === undefined
                ? `“${added.display_title}” is aan je verlanglijst toegevoegd.`
                : `“${added.display_title}” staat al op je verlanglijst.`);
            focusEntry(content, added.wishlist_entry_id);
        } catch (error) {
            if (
                error instanceof BiblioApiError
                && error.status === 409
                && error.code === "biblio_wishlist_intent_conflict"
            ) {
                conflictContent(dialog, work);
                announce(wishlistErrorMessage(error));
            } else {
                const errorBox = el(documentImpl, "p", {
                    className: "biblio-ui__inline-error",
                    textContent: wishlistErrorMessage(error),
                    attrs: { role: "alert" },
                });
                dialog.append(errorBox);
                errorBox.focus?.();
            }
        } finally {
            dialog.setAttribute("aria-busy", "false");
            setPending(false);
        }
    }

    render();

    return Object.freeze({
        load,
        snapshot() { return state; },
        destroy() {
            destroyed = true;
            searchController?.abort();
            shell.destroy();
            root.replaceChildren();
        },
    });
}

export function createWishlistDetailController({
    root,
    api,
    documentImpl = globalThis.document,
} = {}) {
    let activeDialog = null;
    let live = null;
    let liveMounted = false;

    function ensureLiveRegion() {
        if (live === null) {
            live = el(documentImpl, "div", {
                className: "biblio-ui__visually-hidden",
                attrs: { role: "status", "aria-live": "polite", "aria-atomic": "true" },
            });
        }
        if (!liveMounted && typeof root?.append === "function") {
            root.append(live);
            liveMounted = true;
        }
    }

    function announce(message) {
        ensureLiveRegion();
        live.textContent = "";
        queueMicrotask(() => { live.textContent = message; });
    }

    function closeButton(dialog, label = "Sluiten") {
        const close = button(documentImpl, label, "close");
        close.addEventListener("click", () => dialog.close());
        return close;
    }

    function showError(dialog, error) {
        const heading = el(documentImpl, "h2", {
            textContent: "Verlanglijst niet bijgewerkt",
            attrs: { tabindex: "-1" },
        });
        dialog.replaceChildren(
            heading,
            el(documentImpl, "p", {
                textContent: wishlistErrorMessage(error),
                attrs: { role: "alert" },
            }),
            closeButton(dialog)
        );
        heading.focus();
    }

    async function addEdition(dialog, context, opener) {
        try {
            const added = readWishlistEntry(await api.post("me/wishlist", {
                target: {
                    type: "edition_specific",
                    work_id: context.workId,
                    edition_id: context.editionId,
                },
            }));
            dialog.close();
            opener.textContent = "Staat op verlanglijst";
            announce(`Deze uitgave van “${added.display_title}” staat op je verlanglijst.`);
        } catch (error) {
            showError(dialog, error);
        }
    }

    async function open(context) {
        const { opener, workId, editionId, title } = context;
        if (activeDialog !== null) return;
        ensureLiveRegion();
        const dialog = dialogShell(documentImpl, root, "Uitgave op verlanglijst zetten", opener);
        activeDialog = dialog;
        dialog.addEventListener("close", () => { activeDialog = null; }, { once: true });
        dialog.append(
            el(documentImpl, "h2", { textContent: "Verlanglijst controleren" }),
            el(documentImpl, "p", {
                textContent: "Je wensen worden geladen…",
                attrs: { role: "status" },
            })
        );
        dialog.showModal();

        try {
            const list = readWishlistList(await api.get("me/wishlist"));
            const exactEdition = list.entries.find(
                (entry) => entry.edition_id === editionId
            );
            if (exactEdition !== undefined) {
                const heading = el(documentImpl, "h2", {
                    textContent: "Staat al op je verlanglijst",
                    attrs: { tabindex: "-1" },
                });
                dialog.replaceChildren(
                    heading,
                    el(documentImpl, "p", {
                        textContent: "Deze specifieke uitgave is al bewaard.",
                    }),
                    closeButton(dialog)
                );
                opener.textContent = "Staat op verlanglijst";
                announce(`Deze uitgave van “${title}” staat al op je verlanglijst.`);
                heading.focus();
                return;
            }

            const workOnly = list.entries.find(
                (entry) => entry.work_id === workId
                    && entry.target_type === "work_only"
            );
            if (workOnly !== undefined) {
                const heading = el(documentImpl, "h2", {
                    textContent: "Algemene wens verfijnen?",
                    attrs: { tabindex: "-1" },
                });
                const confirm = button(documentImpl, "Deze uitgave kiezen", "refine", "primary");
                const cancel = closeButton(dialog, "Algemene wens behouden");
                confirm.addEventListener("click", async () => {
                    confirm.disabled = true;
                    cancel.disabled = true;
                    try {
                        const refined = readWishlistEntry(await api.patch(
                            `me/wishlist/${encodeURIComponent(workOnly.wishlist_entry_id)}`,
                            { target: { type: "edition_specific", edition_id: editionId } }
                        ));
                        dialog.close();
                        opener.textContent = "Staat op verlanglijst";
                        announce(`Je algemene wens voor “${refined.display_title}” is verfijnd naar deze uitgave.`);
                    } catch (error) {
                        showError(dialog, error);
                    }
                });
                dialog.replaceChildren(
                    heading,
                    el(documentImpl, "p", {
                        textContent: "Dit boek staat al algemeen op je verlanglijst. Kies alleen deze concrete uitgave als je de algemene wens wilt vervangen; de wens behoudt dezelfde identiteit.",
                    }),
                    el(documentImpl, "div", { className: "biblio-ui__dialog-actions" })
                );
                dialog.lastElementChild.append(confirm, cancel);
                heading.focus();
                return;
            }

            await addEdition(dialog, context, opener);
        } catch (error) {
            showError(dialog, error);
        }
    }

    return Object.freeze({
        open,
        destroy() {
            if (activeDialog?.open) activeDialog.close();
            activeDialog?.remove();
            activeDialog = null;
            live?.remove?.();
            live = null;
            liveMounted = false;
        },
    });
}

function bootstrap() {
    for (const root of document.querySelectorAll("[data-biblio-wishlist-root]")) {
        const api = createBiblioApi({
            restRoot: root.dataset.restRoot,
            restNonce: root.dataset.restNonce,
        });
        createWishlistApp({ root, api }).load({ focusHeading: true });
    }
}

if (typeof document !== "undefined") {
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", bootstrap, { once: true });
    } else {
        bootstrap();
    }
}
