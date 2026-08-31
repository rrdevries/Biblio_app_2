const OUTCOMES = new Set(["completed", "stopped"]);
const SOURCE_TYPES = new Set(["library_item", "external_loan", "unknown"]);
const ENTRY_FIELDS = [
    "outcome",
    "started_on",
    "finished_on",
    "source_type",
    "historical_registration",
];

function isRecord(value) {
    return value !== null && typeof value === "object" && !Array.isArray(value);
}

function hasExactFields(value, fields) {
    const keys = Object.keys(value);

    return keys.length === fields.length
        && fields.every((field) => Object.hasOwn(value, field));
}

function isReadingDate(value) {
    if (
        !isRecord(value)
        || !hasExactFields(value, ["year", "month", "day"])
        || !Number.isInteger(value.year)
        || value.year < 1000
        || value.year > 9999
        || !(value.month === null || (
            Number.isInteger(value.month)
            && value.month >= 1
            && value.month <= 12
        ))
        || !(value.day === null || (
            Number.isInteger(value.day)
            && value.day >= 1
        ))
        || (value.month === null && value.day !== null)
    ) {
        return false;
    }

    return value.day === null
        || value.day <= new Date(Date.UTC(
            value.year,
            value.month,
            0
        )).getUTCDate();
}

function assertEntry(entry) {
    if (
        !isRecord(entry)
        || !hasExactFields(entry, ENTRY_FIELDS)
        || !OUTCOMES.has(entry.outcome)
        || !(entry.started_on === null || isReadingDate(entry.started_on))
        || !isReadingDate(entry.finished_on)
        || !SOURCE_TYPES.has(entry.source_type)
        || typeof entry.historical_registration !== "boolean"
    ) {
        throw new TypeError("The Biblio Reading history entry is invalid.");
    }
}

export function readReadingHistoryPage(payload) {
    if (
        !isRecord(payload)
        || !hasExactFields(payload, ["items", "next_cursor"])
        || !Array.isArray(payload.items)
        || !(
            payload.next_cursor === null
            || (
                typeof payload.next_cursor === "string"
                && payload.next_cursor.length > 0
            )
        )
    ) {
        throw new TypeError("The Biblio Reading history response is invalid.");
    }

    payload.items.forEach(assertEntry);

    return Object.freeze({
        items: Object.freeze(payload.items.map((entry) => Object.freeze({
            ...entry,
            started_on: entry.started_on === null
                ? null
                : Object.freeze({ ...entry.started_on }),
            finished_on: Object.freeze({ ...entry.finished_on }),
        }))),
        nextCursor: payload.next_cursor,
    });
}

export function readingHistoryPath(workId, cursor = null) {
    if (typeof workId !== "string" || workId.length === 0) {
        throw new TypeError("A validated Work ID is required for Reading history.");
    }

    if (!(
        cursor === null
        || (typeof cursor === "string" && cursor.length > 0)
    )) {
        throw new TypeError("A validated Reading history cursor is required.");
    }

    const path = `me/works/${encodeURIComponent(workId)}`
        + "/reading-history?limit=10";

    return cursor === null
        ? path
        : `${path}&cursor=${encodeURIComponent(cursor)}`;
}

export function formatReadingDate(date, locale = "nl-NL") {
    if (!isReadingDate(date)) {
        throw new TypeError("A precision-aware Reading date is required.");
    }

    if (date.month === null) {
        return String(date.year);
    }

    const value = new Date(Date.UTC(
        date.year,
        date.month - 1,
        date.day ?? 1
    ));
    const options = date.day === null
        ? { month: "long", year: "numeric", timeZone: "UTC" }
        : { day: "numeric", month: "long", year: "numeric", timeZone: "UTC" };

    return new Intl.DateTimeFormat(locale, options).format(value);
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

function outcomeLabel(outcome) {
    return outcome === "completed" ? "Uitgelezen" : "Gestopt";
}

function periodLabel(entry, locale) {
    const finished = formatReadingDate(entry.finished_on, locale);

    return entry.started_on === null
        ? `Afgerond ${finished}`
        : `${formatReadingDate(entry.started_on, locale)} – ${finished}`;
}

function sourceLabel(entry) {
    if (entry.historical_registration) {
        return "Historische registratie";
    }

    return entry.source_type === "external_loan" ? "Externe lening" : null;
}

function recoveryControl(documentImpl, recovery, actions) {
    if (recovery === "authentication") {
        return element(documentImpl, "a", {
            className: "biblio-ui__control biblio-ui__control--secondary",
            text: "Opnieuw inloggen",
            attributes: { href: actions.loginUrl },
        });
    }

    const label = recovery === "session"
        ? "Sessie vernieuwen"
        : "Opnieuw proberen";
    const control = element(documentImpl, "button", {
        className: "biblio-ui__control biblio-ui__control--secondary",
        text: label,
        attributes: { type: "button" },
    });
    control.addEventListener(
        "click",
        recovery === "session" ? actions.reload : actions.retry
    );

    return control;
}

function renderError(documentImpl, model, actions) {
    const error = element(documentImpl, "div", {
        className: "biblio-ui__inline-error biblio-ui__history-error",
        attributes: { role: "status" },
    });
    error.append(
        element(documentImpl, "p", { text: model.message }),
        recoveryControl(documentImpl, model.recovery, actions)
    );

    return error;
}

function renderReady(documentImpl, model, actions, locale) {
    const section = element(documentImpl, "section", {
        className: "biblio-ui__section biblio-ui__reading-history",
        attributes: {
            "aria-busy": model.refreshing || model.loadingMore
                ? "true"
                : "false",
        },
    });
    const heading = element(documentImpl, "h2", { text: "Leesgeschiedenis" });
    const list = element(documentImpl, "ul", {
        className: "biblio-ui__history-list",
    });

    for (const entry of model.items) {
        const item = element(documentImpl, "li", {
            className: "biblio-ui__history-entry",
        });
        item.append(
            element(documentImpl, "p", {
                className: "biblio-ui__history-outcome",
                text: outcomeLabel(entry.outcome),
            }),
            element(documentImpl, "p", {
                className: "biblio-ui__history-period",
                text: periodLabel(entry, locale),
            })
        );
        const source = sourceLabel(entry);

        if (source !== null) {
            item.append(element(documentImpl, "p", {
                className: "biblio-ui__context",
                text: source,
            }));
        }

        list.append(item);
    }

    section.append(heading, list);

    if (model.refreshing) {
        section.append(element(documentImpl, "p", {
            text: "Leesgeschiedenis vernieuwen…",
            attributes: { "aria-live": "polite", role: "status" },
        }));
    }

    if (model.refreshError) {
        section.append(renderError(documentImpl, {
            message: "Leesgeschiedenis kon niet worden vernieuwd.",
            recovery: model.refreshRecovery,
        }, actions));
    }

    let paginationTarget = null;

    if (model.nextCursor !== null) {
        const button = element(documentImpl, "button", {
            className: "biblio-ui__control biblio-ui__control--secondary biblio-ui__history-load-more",
            text: "Meer laden",
            attributes: { type: "button" },
        });
        button.disabled = model.loadingMore;
        button.addEventListener("click", actions.loadMore);
        section.append(button);
        paginationTarget = button;
    }

    if (model.loadingMore) {
        section.append(element(documentImpl, "p", {
            text: "Meer leesgeschiedenis laden…",
            attributes: { "aria-live": "polite", role: "status" },
        }));
    }

    if (model.loadMoreError) {
        const error = renderError(documentImpl, {
            message: "Meer leesgeschiedenis kon niet worden geladen.",
            recovery: model.paginationRecovery,
        }, {
            ...actions,
            retry: actions.retryLoadMore,
        });
        section.append(error);
    }

    if (model.addedCount > 0) {
        section.append(element(documentImpl, "p", {
            text: `${model.addedCount} leesrondes toegevoegd.`,
            attributes: { "aria-live": "polite", role: "status" },
        }));
    }

    if (model.focusAfterPagination) {
        const target = paginationTarget ?? heading;
        target.setAttribute("tabindex", "-1");
        target.focus();
    }

    return section;
}

export function createReadingHistoryView(root, {
    documentImpl = globalThis.document,
    locale = "nl-NL",
} = {}) {
    if (
        typeof root?.querySelector !== "function"
        || typeof documentImpl?.createElement !== "function"
    ) {
        throw new TypeError("A Biblio UI mount and Document are required.");
    }

    function render(model, actions = {}) {
        const region = root.querySelector("[data-biblio-reading-history]");

        if (region === null) {
            return null;
        }

        region.setAttribute("aria-busy", "false");

        if (model.state === "empty") {
            region.replaceChildren();
            return region;
        }

        if (model.state === "loading") {
            region.setAttribute("aria-busy", "true");
            region.replaceChildren(element(documentImpl, "p", {
                className: "biblio-ui__history-loading",
                text: "Leesgeschiedenis laden…",
                attributes: { "aria-live": "polite", role: "status" },
            }));
            return region;
        }

        if (model.state === "error") {
            region.replaceChildren(renderError(
                documentImpl,
                model,
                actions
            ));
            return region;
        }

        if (model.state !== "ready" || model.items.length === 0) {
            throw new TypeError("The Reading history view state is invalid.");
        }

        region.replaceChildren(renderReady(
            documentImpl,
            model,
            actions,
            locale
        ));

        return region;
    }

    return Object.freeze({ render });
}
