import { BiblioApiError, createBiblioApi } from "./api.js";
import {
    readBibliographicDiscovery,
    readBibliographicMaterialization,
} from "./bibliographic-discovery.js";
import { createLibraryShell } from "./ui-shell.js";

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

function isBiblioApiError(error) {
    return error instanceof BiblioApiError || (
        error instanceof Error
        && error.name === "BiblioApiError"
        && ["aborted", "http", "invalid_response", "network"].includes(error.kind)
        && text(error.code)
        && (error.status === null || Number.isInteger(error.status))
    );
}

export function wishlistErrorMessage(error) {
    if (isBiblioApiError(error) && error.status === 401) {
        return "Je sessie is verlopen. Log opnieuw in.";
    }
    if (
        isBiblioApiError(error)
        && error.status === 409
        && error.code === "biblio_wishlist_intent_conflict"
    ) {
        return "Er staan al specifieke uitgaven van dit boek op je verlanglijst. Biblio voegt daarom geen algemene boekwens toe en verwijdert niets.";
    }
    if (isBiblioApiError(error) && error.status === 404) {
        return "Deze wens of uitgave is niet meer beschikbaar.";
    }
    return "Dat lukte niet. Probeer het opnieuw.";
}

function isWishlistIntentConflict(error) {
    return isBiblioApiError(error)
        && error.status === 409
        && error.code === "biblio_wishlist_intent_conflict";
}

export function discoveryErrorMessage(error) {
    if (
        isBiblioApiError(error)
        && error.status === 409
        && error.code === "biblio_metadata_lookup_snapshot_unavailable"
    ) {
        return "Deze zoekresultaten zijn verlopen of niet meer beschikbaar. Zoek opnieuw.";
    }
    if (error instanceof TypeError || error?.kind === "invalid_response") {
        return "Biblio kon de zoekresultaten niet veilig lezen. Probeer het opnieuw.";
    }
    return wishlistErrorMessage(error);
}

export function discoveryStatusCopy(discovery) {
    if (discovery.status === "no_results") {
        return "Geen boeken gevonden.";
    }
    if (discovery.status === "provider_failure") {
        return "Zoeken buiten Biblio lukt tijdelijk niet. Probeer het opnieuw.";
    }
    if (discovery.status === "configuration_failure") {
        return "Zoeken buiten Biblio is tijdelijk niet beschikbaar. Probeer het later opnieuw.";
    }
    if (discovery.status === "invalid_provider_response") {
        return "Externe zoekresultaten konden niet veilig worden gelezen. Probeer het opnieuw.";
    }
    const count = `${discovery.results.length} ${discovery.results.length === 1 ? "resultaat" : "resultaten"} gevonden.`;
    const externalExpansionFailed = discovery.query.type === "text"
        && discovery.results.some((candidate) => candidate.type.startsWith("local_"))
        && !discovery.results.some((candidate) => candidate.type.startsWith("external_"))
        && discovery.provider_attempts.some((attempt) => (
            attempt.status === "unavailable"
            || attempt.status === "rate_limited"
            || attempt.status === "configuration_error"
            || attempt.status === "invalid_response"
        ));
    return externalExpansionFailed
        ? `${count} Externe uitbreiding is tijdelijk niet volledig beschikbaar.`
        : count;
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
    const searchRestorers = new WeakMap();

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

    function setDialogPending(dialog, value) {
        dialog.setAttribute("aria-busy", value ? "true" : "false");
        for (const control of dialog.querySelectorAll("button, input")) {
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
                isBiblioApiError(state.error)
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

    function conflictContent(dialog, target) {
        const heading = el(documentImpl, "h2", {
            textContent: "Algemene wens niet toegevoegd",
            attrs: { tabindex: "-1" },
        });
        const explanation = el(documentImpl, "p", {
            textContent: "Er staan al specifieke uitgaven van dit boek op je verlanglijst. Biblio verwijdert of voegt die niet automatisch samen.",
        });
        const existing = state.entries.filter(
            (entry) => entry.work_id === target.workId
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

    function candidateMetadata(candidate) {
        const details = [];
        if (candidate.publishers.length > 0) {
            details.push(["Uitgever", candidate.publishers.join(", ")]);
        }
        if (candidate.publication_date !== null) {
            details.push(["Jaar / datum", candidate.publication_date]);
        }
        if (candidate.languages.length > 0) {
            details.push(["Taal", candidate.languages.join(", ")]);
        }
        if (candidate.isbn_13 !== null || candidate.isbn_10 !== null) {
            details.push(["ISBN", candidate.isbn_13 ?? candidate.isbn_10]);
        }
        if (candidate.format !== null) {
            details.push(["Binding", candidate.format]);
        }
        if (candidate.page_count !== null) {
            details.push(["Omvang", `${candidate.page_count} pagina’s`]);
        }
        return details;
    }

    function resultTypeLabel(candidate) {
        return candidate.type === "local_work"
            || candidate.type === "external_work_candidate"
            ? "Boek"
            : "Specifieke uitgave";
    }

    function finishWishlistMutation(dialog, added, wasPresent, action) {
        const existingIndex = state.entries.findIndex(
            (entry) => entry.wishlist_entry_id === added.wishlist_entry_id
        );
        const entries = [...state.entries];
        if (existingIndex === -1) entries.unshift(added);
        else entries[existingIndex] = added;
        state = Object.freeze({
            name: "ready",
            entries: Object.freeze(entries),
        });
        render();
        dialog.close();
        if (action === "refined") {
            announce(`Je algemene wens voor “${added.display_title}” is verfijnd naar deze uitgave.`);
        } else if (added.target_type === "edition_specific") {
            announce(wasPresent
                ? `Deze uitgave van “${added.display_title}” staat al op je verlanglijst.`
                : `Deze uitgave van “${added.display_title}” is aan je verlanglijst toegevoegd.`);
        } else {
            announce(wasPresent
                ? `“${added.display_title}” staat al op je verlanglijst.`
                : `“${added.display_title}” is aan je verlanglijst toegevoegd.`);
        }
        focusEntry(content, added.wishlist_entry_id);
    }

    function wishlistRetryContent(dialog, target, error) {
        const heading = el(documentImpl, "h2", {
            textContent: "Wens nog niet toegevoegd",
            attrs: { tabindex: "-1" },
        });
        const retry = button(documentImpl, "Opnieuw proberen", "retry-wishlist", "primary");
        const close = button(documentImpl, "Sluiten", "close-wishlist");
        retry.addEventListener("click", () => writeWishlistTarget(target, dialog));
        close.addEventListener("click", () => dialog.close());
        dialog.replaceChildren(
            heading,
            el(documentImpl, "p", {
                className: "biblio-ui__inline-error",
                textContent: wishlistErrorMessage(error),
                attrs: { role: "alert" },
            }),
            el(documentImpl, "p", {
                textContent: "Het boek is veilig herkend. Probeer alleen het bewaren op je verlanglijst opnieuw.",
            }),
            el(documentImpl, "div", { className: "biblio-ui__dialog-actions" })
        );
        dialog.lastElementChild.append(retry, close);
        heading.focus();
    }

    function refinementContent(dialog, target, workOnly) {
        const heading = el(documentImpl, "h2", {
            textContent: "Algemene wens verfijnen?",
            attrs: { tabindex: "-1" },
        });
        const confirm = button(documentImpl, "Deze uitgave kiezen", "refine", "primary");
        const cancel = button(documentImpl, "Algemene wens behouden", "keep-general");
        async function refine() {
            if (pending) return;
            setPending(true);
            setDialogPending(dialog, true);
            try {
                const refined = readWishlistEntry(await api.patch(
                    `me/wishlist/${encodeURIComponent(workOnly.wishlist_entry_id)}`,
                    { target: { type: "edition_specific", edition_id: target.editionId } }
                ));
                await finishWishlistMutation(dialog, refined, false, "refined");
            } catch (error) {
                if (isWishlistIntentConflict(error)) {
                    await handleIntentConflict(target, dialog, "refined");
                    return;
                }
                const retryHeading = el(documentImpl, "h2", {
                    textContent: "Wens nog niet verfijnd",
                    attrs: { tabindex: "-1" },
                });
                const retry = button(documentImpl, "Opnieuw proberen", "retry-refinement", "primary");
                const close = button(documentImpl, "Algemene wens behouden", "keep-general");
                retry.addEventListener("click", refine);
                close.addEventListener("click", () => dialog.close());
                dialog.replaceChildren(
                    retryHeading,
                    el(documentImpl, "p", {
                        className: "biblio-ui__inline-error",
                        textContent: wishlistErrorMessage(error),
                        attrs: { role: "alert" },
                    }),
                    el(documentImpl, "p", {
                        textContent: "Je algemene wens is niet gewijzigd.",
                    }),
                    el(documentImpl, "div", { className: "biblio-ui__dialog-actions" })
                );
                dialog.lastElementChild.append(retry, close);
                retryHeading.focus();
            } finally {
                setPending(false);
                if (dialog.open) setDialogPending(dialog, false);
            }
        }
        confirm.addEventListener("click", refine);
        cancel.addEventListener("click", () => dialog.close());
        dialog.replaceChildren(
            heading,
            el(documentImpl, "p", {
                textContent: "Dit boek staat al algemeen op je verlanglijst. Kies deze concrete uitgave alleen als je de algemene wens wilt vervangen; de wens behoudt dezelfde identiteit.",
            }),
            el(documentImpl, "div", { className: "biblio-ui__dialog-actions" })
        );
        dialog.lastElementChild.append(confirm, cancel);
        heading.focus();
    }

    function staleConflictContent(dialog, refreshFailed) {
        const heading = el(documentImpl, "h2", {
            textContent: "Controleer je verlanglijst",
            attrs: { tabindex: "-1" },
        });
        const review = button(
            documentImpl,
            refreshFailed ? "Verlanglijst vernieuwen" : "Bijgewerkte lijst bekijken",
            "review-stale-wishlist",
            "primary"
        );
        review.addEventListener("click", () => {
            dialog.close();
            if (refreshFailed) void load({ focusHeading: true });
        });
        dialog.replaceChildren(
            heading,
            el(documentImpl, "p", {
                className: refreshFailed ? "biblio-ui__inline-error" : undefined,
                textContent: refreshFailed
                    ? "Je verlanglijst is elders gewijzigd, maar kon niet opnieuw worden geladen. Vernieuw de lijst en kies daarna opnieuw."
                    : "Je verlanglijst is elders gewijzigd. Bekijk de bijgewerkte lijst en kies daarna opnieuw.",
                attrs: refreshFailed ? { role: "alert" } : {},
            }),
            review
        );
        heading.focus();
        announce("Je verlanglijst is elders gewijzigd. Controleer de actuele lijst.");
    }

    async function handleIntentConflict(target, dialog, completedAction = "added") {
        try {
            const list = readWishlistList(await api.get("me/wishlist"));
            state = Object.freeze({ name: "ready", entries: list.entries });
            render();
            if (target.intent === "work_only") {
                const editions = list.entries.filter((entry) => (
                    entry.work_id === target.workId && entry.target_type === "edition_specific"
                ));
                if (editions.length > 0) {
                    conflictContent(dialog, target);
                    announce("Er staan al specifieke uitgaven van dit boek op je verlanglijst. Biblio voegt daarom geen algemene boekwens toe en verwijdert niets.");
                    return;
                }
                staleConflictContent(dialog, false);
                return;
            }

            const exactEdition = list.entries.find((entry) => (
                entry.edition_id === target.editionId
            ));
            if (exactEdition !== undefined) {
                finishWishlistMutation(dialog, exactEdition, true, completedAction);
                return;
            }
            const workOnly = list.entries.find((entry) => (
                entry.work_id === target.workId && entry.target_type === "work_only"
            ));
            if (workOnly !== undefined) {
                refinementContent(dialog, target, workOnly);
                return;
            }
            staleConflictContent(dialog, false);
        } catch {
            staleConflictContent(dialog, true);
        }
    }

    async function writeWishlistTarget(target, dialog) {
        if (pending || !dialog.open) return;
        const exactEntry = state.entries.find((entry) => (
            target.intent === "work_only"
                ? entry.work_id === target.workId && entry.target_type === "work_only"
                : entry.edition_id === target.editionId
        ));
        const workOnly = target.intent === "work_and_edition"
            ? state.entries.find((entry) => (
                entry.work_id === target.workId && entry.target_type === "work_only"
            ))
            : undefined;
        if (workOnly !== undefined) {
            refinementContent(dialog, target, workOnly);
            return;
        }

        setPending(true);
        setDialogPending(dialog, true);
        try {
            const payload = target.intent === "work_only"
                ? { target: { type: "work_only", work_id: target.workId } }
                : {
                    target: {
                        type: "edition_specific",
                        work_id: target.workId,
                        edition_id: target.editionId,
                    },
                };
            const added = readWishlistEntry(await api.post("me/wishlist", payload));
            await finishWishlistMutation(dialog, added, exactEntry !== undefined, "added");
        } catch (error) {
            if (isWishlistIntentConflict(error)) {
                await handleIntentConflict(target, dialog);
            } else {
                wishlistRetryContent(dialog, target, error);
            }
        } finally {
            setPending(false);
            if (dialog.open) setDialogPending(dialog, false);
        }
    }

    function materializationErrorContent(dialog, candidate, discovery, intent, revision, error) {
        const unavailable = isBiblioApiError(error)
            && error.status === 409
            && error.code === "biblio_metadata_lookup_snapshot_unavailable";
        const heading = el(documentImpl, "h2", {
            textContent: unavailable ? "Zoek opnieuw" : "Boek nog niet voorbereid",
            attrs: { tabindex: "-1" },
        });
        const retry = button(
            documentImpl,
            unavailable ? "Nieuwe zoekopdracht" : "Opnieuw proberen",
            unavailable ? "new-search" : "retry-materialization",
            "primary"
        );
        const close = button(documentImpl, "Sluiten", "close-materialization");
        if (unavailable) {
            retry.addEventListener("click", () => {
                searchRestorers.get(dialog)?.();
            });
        } else {
            retry.addEventListener("click", () => chooseCandidate(
                candidate, discovery, intent, dialog, revision
            ));
        }
        close.addEventListener("click", () => dialog.close());
        dialog.replaceChildren(
            heading,
            el(documentImpl, "p", {
                className: "biblio-ui__inline-error",
                textContent: discoveryErrorMessage(error),
                attrs: { role: "alert" },
            }),
            el(documentImpl, "div", { className: "biblio-ui__dialog-actions" })
        );
        dialog.lastElementChild.append(retry, close);
        heading.focus();
    }

    async function chooseCandidate(candidate, discovery, intent, dialog, revision) {
        if (pending || revision !== searchRevision || !dialog.open) return;
        const external = candidate.type.startsWith("external_");
        setPending(true);
        setDialogPending(dialog, true);
        try {
            let workId = candidate.work_id;
            let editionId = candidate.edition_id;
            if (external) {
                const materialized = readBibliographicMaterialization(await api.post(
                    `me/bibliographic-discoveries/${encodeURIComponent(discovery.discovery_id)}/materializations`,
                    { candidate_id: candidate.candidate_id, intent }
                ), intent);
                workId = materialized.work_id;
                editionId = materialized.edition_id;
            }
            if (revision !== searchRevision || !dialog.open) return;
            if (!text(workId) || (intent === "work_and_edition" && !text(editionId))) {
                throw new TypeError("The selected bibliographic identity is invalid.");
            }
            const target = Object.freeze({
                workId,
                editionId,
                intent,
                title: candidate.title,
            });
            setPending(false);
            setDialogPending(dialog, false);
            await writeWishlistTarget(target, dialog);
        } catch (error) {
            materializationErrorContent(
                dialog, candidate, discovery, intent, revision, error
            );
        } finally {
            setPending(false);
            if (dialog.open) setDialogPending(dialog, false);
        }
    }

    function renderCandidate(candidate, discovery, dialog, revision) {
        const item = el(documentImpl, "li", {
            className: "biblio-ui__wishlist-discovery-result",
            attrs: { "data-result-type": candidate.type },
        });
        const identity = el(documentImpl, "div", {
            className: "biblio-ui__wishlist-result-identity",
        });
        identity.append(
            el(documentImpl, "p", {
                className: "biblio-ui__wishlist-result-kind",
                textContent: resultTypeLabel(candidate),
            }),
            el(documentImpl, "h3", { textContent: candidate.title })
        );
        if (candidate.subtitle !== null) {
            identity.append(el(documentImpl, "p", {
                className: "biblio-ui__context",
                textContent: candidate.subtitle,
            }));
        }
        if (candidate.contributors.length > 0) {
            identity.append(el(documentImpl, "p", {
                className: "biblio-ui__authors",
                textContent: candidate.contributors.join(", "),
            }));
        }
        const metadata = candidateMetadata(candidate);
        if (metadata.length > 0) {
            const list = el(documentImpl, "dl", {
                className: "biblio-ui__wishlist-result-metadata",
            });
            for (const [term, value] of metadata) {
                list.append(
                    el(documentImpl, "dt", { textContent: term }),
                    el(documentImpl, "dd", { textContent: value })
                );
            }
            identity.append(list);
        }
        const actions = el(documentImpl, "div", {
            className: "biblio-ui__wishlist-result-actions",
            attrs: { "aria-label": `Kies hoe je “${candidate.title}” wilt bewaren` },
        });
        if (candidate.capabilities.can_add_work_only) {
            const workOnly = button(
                documentImpl, "Uitgave maakt niet uit", "choose-work", "secondary"
            );
            workOnly.addEventListener("click", () => chooseCandidate(
                candidate, discovery, "work_only", dialog, revision
            ));
            actions.append(workOnly);
        }
        if (candidate.capabilities.can_add_edition_specific) {
            const edition = button(
                documentImpl, "Deze specifieke uitgave", "choose-edition", "primary"
            );
            edition.addEventListener("click", () => chooseCandidate(
                candidate, discovery, "work_and_edition", dialog, revision
            ));
            actions.append(edition);
        }
        item.append(identity, actions);
        return item;
    }

    function openAddDialog(opener) {
        const dialog = dialogShell(documentImpl, host, "Boek toevoegen aan verlanglijst", opener);
        const heading = el(documentImpl, "h2", { textContent: "Zoek een boek" });
        const intro = el(documentImpl, "p", {
            className: "biblio-ui__context",
            textContent: "Kies daarna of iedere uitgave goed is, of juist één concrete uitgave.",
        });
        const form = el(documentImpl, "form", {
            className: "biblio-ui__work-search biblio-ui__wishlist-search",
        });
        const inputId = "biblio-wishlist-work-search";
        const label = el(documentImpl, "label", {
            textContent: "Zoek op titel, auteur of ISBN",
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
            className: "biblio-ui__wishlist-search-status",
            attrs: { role: "status", "aria-live": "polite" },
        });
        const results = el(documentImpl, "ul", {
            className: "biblio-ui__wishlist-discovery-results",
            attrs: { "aria-label": "Zoekresultaten" },
        });
        const cancel = button(documentImpl, "Annuleren", "cancel");
        cancel.addEventListener("click", () => dialog.close());
        form.append(label, input, search, searchStatus, results, cancel);
        dialog.append(heading, intro, form);
        searchRestorers.set(dialog, () => {
            results.replaceChildren();
            searchStatus.textContent = "Deze zoekresultaten zijn verlopen of niet meer beschikbaar. Pas je zoekopdracht aan of zoek opnieuw.";
            dialog.replaceChildren(heading, intro, form);
            setDialogPending(dialog, false);
            input.focus();
        });

        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            const query = input.value.trim();
            if (query.length === 0) return;
            const revision = ++searchRevision;
            searchController?.abort();
            searchController = abortControllerFactory();
            search.disabled = true;
            input.setAttribute("aria-busy", "true");
            searchStatus.textContent = "Zoeken…";
            results.replaceChildren();
            try {
                const discovery = readBibliographicDiscovery(await api.post(
                    "me/bibliographic-discoveries",
                    { query },
                    { signal: searchController.signal }
                ));
                if (revision !== searchRevision || !dialog.open) return;
                for (const candidate of discovery.results) {
                    results.append(renderCandidate(candidate, discovery, dialog, revision));
                }
                searchStatus.textContent = discoveryStatusCopy(discovery);
            } catch (error) {
                if (error?.kind !== "aborted" && revision === searchRevision) {
                    searchStatus.textContent = discoveryErrorMessage(error);
                }
            } finally {
                if (revision === searchRevision) {
                    search.disabled = false;
                    input.removeAttribute("aria-busy");
                }
            }
        });
        dialog.addEventListener("close", () => {
            searchRevision += 1;
            searchController?.abort();
            searchRestorers.delete(dialog);
        }, { once: true });
        dialog.showModal();
        input.focus();
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

    function finishEdition(dialog, opener, added, refined = false) {
        dialog.close();
        opener.textContent = "Staat op verlanglijst";
        announce(refined
            ? `Je algemene wens voor “${added.display_title}” is verfijnd naar deze uitgave.`
            : `Deze uitgave van “${added.display_title}” staat op je verlanglijst.`);
    }

    function showStaleConflict(dialog, context, opener, refreshFailed = false) {
        const heading = el(documentImpl, "h2", {
            textContent: "Controleer je verlanglijst",
            attrs: { tabindex: "-1" },
        });
        const review = button(
            documentImpl,
            refreshFailed ? "Opnieuw controleren" : "Sluiten en opnieuw bekijken",
            "review-stale-wishlist",
            "primary"
        );
        if (refreshFailed) {
            review.addEventListener("click", () => reconcileIntentConflict(
                dialog, context, opener
            ));
        } else {
            review.addEventListener("click", () => dialog.close());
        }
        dialog.replaceChildren(
            heading,
            el(documentImpl, "p", {
                className: refreshFailed ? "biblio-ui__inline-error" : undefined,
                textContent: refreshFailed
                    ? "Je verlanglijst is elders gewijzigd, maar kon niet opnieuw worden geladen. Controleer opnieuw voordat je een keuze maakt."
                    : "Je verlanglijst is elders gewijzigd. Sluit dit venster en bekijk de actuele wens voordat je opnieuw kiest.",
                attrs: refreshFailed ? { role: "alert" } : {},
            }),
            review
        );
        heading.focus();
        announce("Je verlanglijst is elders gewijzigd. Controleer de actuele wens.");
    }

    function showRefinement(dialog, context, opener, workOnly) {
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
                    { target: { type: "edition_specific", edition_id: context.editionId } }
                ));
                finishEdition(dialog, opener, refined, true);
            } catch (error) {
                if (isWishlistIntentConflict(error)) {
                    await reconcileIntentConflict(dialog, context, opener, true);
                } else {
                    showError(dialog, error);
                }
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
    }

    async function reconcileIntentConflict(dialog, context, opener, refined = false) {
        try {
            const list = readWishlistList(await api.get("me/wishlist"));
            const exactEdition = list.entries.find(
                (entry) => entry.edition_id === context.editionId
            );
            if (exactEdition !== undefined) {
                finishEdition(dialog, opener, exactEdition, refined);
                return;
            }
            const workOnly = list.entries.find(
                (entry) => entry.work_id === context.workId
                    && entry.target_type === "work_only"
            );
            if (workOnly !== undefined) {
                showRefinement(dialog, context, opener, workOnly);
                return;
            }
            showStaleConflict(dialog, context, opener);
        } catch {
            showStaleConflict(dialog, context, opener, true);
        }
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
            finishEdition(dialog, opener, added);
        } catch (error) {
            if (isWishlistIntentConflict(error)) {
                await reconcileIntentConflict(dialog, context, opener);
            } else {
                showError(dialog, error);
            }
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
                showRefinement(dialog, context, opener, workOnly);
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
