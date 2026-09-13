import { BiblioApiError, createBiblioApi } from "./api.js";
import { createLibraryShell } from "./ui-shell.js";

const RESPONSE_FIELDS = ["query", "authors", "works"];
const GROUP_FIELDS = ["items", "next_cursor", "provider_attempts"];
const AUTHOR_FIELDS = [
    "result_id",
    "result_kind",
    "author_id",
    "display_name",
    "author_selector",
];
const WORK_FIELDS = [
    "result_id",
    "result_kind",
    "work_id",
    "work_selector",
    "title",
    "authors",
    "series",
];
const AUTHOR_WORK_FIELDS = [
    "result_id",
    "result_kind",
    "work_id",
    "work_selector",
    "provider_identity",
    "title",
    "authors",
    "series",
];
const PROVIDER_IDENTITY_FIELDS = ["provider_key", "record_id"];
const EDITION_FIELDS = [
    "result_id",
    "result_kind",
    "edition_id",
    "provider_identity",
    "provider_work_identity",
    "parent_work_result_id",
    "title",
    "subtitle",
    "contributors",
    "languages",
    "publishers",
    "publication_date",
    "isbn_10",
    "isbn_13",
    "format",
    "page_count",
    "presentation_order",
    "requires_materialization",
    "can_add_work_only",
    "can_add_edition_specific",
];
const WORK_AUTHOR_FIELDS = ["author_id", "display_name"];
const SERIES_FIELDS = ["series_id", "display_name", "position"];
const ATTEMPT_FIELDS = ["provider_key", "status", "failure_reason"];
const RESULT_KINDS = new Set(["local_canonical", "external_candidate"]);
const PROVIDER_STATUSES = new Set([
    "candidates",
    "miss",
    "unavailable",
    "rate_limited",
    "configuration_error",
    "invalid_response",
]);
const FAILURE_REASONS = new Set([
    "timeout",
    "network",
    "rate_limited",
    "configuration",
    "http_error",
    "http_5xx",
    "malformed",
    "isbn_mismatch",
]);
const FAILED_STATUSES = new Set([
    "unavailable",
    "rate_limited",
    "configuration_error",
    "invalid_response",
]);
const GROUPS = new Set(["authors", "works"]);
const SEARCH_TABS = ["all", "books", "authors"];
const SEARCH_VIEWS = new Set(["results", "authorWorks", "workEditions"]);
const ALL_WORK_PREVIEW_LIMIT = 5;
const ALL_AUTHOR_PREVIEW_LIMIT = 4;
const MAX_QUERY_LENGTH = 100;

function record(value) {
    return value !== null && typeof value === "object" && !Array.isArray(value);
}

function exact(value, fields) {
    return record(value)
        && Object.keys(value).length === fields.length
        && fields.every((field) => Object.hasOwn(value, field));
}

function text(value, maximum = 4096) {
    return typeof value === "string"
        && value.trim().length > 0
        && Array.from(value).length <= maximum;
}

function nullableText(value, maximum = 4096) {
    return value === null || text(value, maximum);
}

function boundedTextList(value, maximumValues, maximumLength) {
    return Array.isArray(value)
        && value.length <= maximumValues
        && value.every((item) => text(item, maximumLength));
}

function isbn13(value) {
    if (!/^97[89][0-9]{10}$/.test(value)) return false;
    const sum = [...value].slice(0, 12).reduce(
        (total, digit, index) => total + Number(digit) * (index % 2 === 0 ? 1 : 3),
        0
    );
    return (10 - (sum % 10)) % 10 === Number(value[12]);
}

function isbn10(value) {
    if (!/^[0-9]{9}[0-9X]$/.test(value)) return false;
    const sum = [...value].reduce((total, digit, index) => (
        total + (digit === "X" ? 10 : Number(digit)) * (10 - index)
    ), 0);
    return sum % 11 === 0;
}

function isbn10As13(value) {
    const payload = `978${value.slice(0, 9)}`;
    const sum = [...payload].reduce(
        (total, digit, index) => total + Number(digit) * (index % 2 === 0 ? 1 : 3),
        0
    );
    return `${payload}${(10 - (sum % 10)) % 10}`;
}

function readProviderIdentity(value) {
    if (value === null) return null;
    if (
        !exact(value, PROVIDER_IDENTITY_FIELDS)
        || !/^[a-z][a-z0-9_]{0,63}$/.test(value.provider_key)
        || !text(value.record_id, 191)
    ) {
        throw new TypeError("The bibliographic provider identity is invalid.");
    }
    return Object.freeze({ ...value });
}

function readAttempt(value) {
    if (
        !exact(value, ATTEMPT_FIELDS)
        || !/^[a-z][a-z0-9_]{0,63}$/.test(value.provider_key)
        || !PROVIDER_STATUSES.has(value.status)
    ) {
        throw new TypeError("The bibliographic provider attempt is invalid.");
    }

    const failed = FAILED_STATUSES.has(value.status);
    if (
        (failed && !FAILURE_REASONS.has(value.failure_reason))
        || (!failed && value.failure_reason !== null)
    ) {
        throw new TypeError("The bibliographic provider attempt is inconsistent.");
    }

    return Object.freeze({ ...value });
}

function readAuthor(value) {
    if (
        !exact(value, AUTHOR_FIELDS)
        || !/^search-author-[0-9a-f]{64}$/.test(value.result_id)
        || !RESULT_KINDS.has(value.result_kind)
        || !nullableText(value.author_id, 191)
        || !text(value.display_name, 512)
        || !text(value.author_selector, 4096)
        || (value.result_kind === "local_canonical" && value.author_id === null)
        || (value.result_kind === "external_candidate" && value.author_id !== null)
    ) {
        throw new TypeError("The bibliographic Author result is invalid.");
    }

    return Object.freeze({ ...value });
}

function readWorkAuthor(value) {
    if (
        !exact(value, WORK_AUTHOR_FIELDS)
        || !nullableText(value.author_id, 191)
        || !text(value.display_name, 512)
    ) {
        throw new TypeError("The bibliographic Work Author is invalid.");
    }

    return Object.freeze({ ...value });
}

function readSeries(value) {
    if (
        !exact(value, SERIES_FIELDS)
        || !nullableText(value.series_id, 191)
        || !text(value.display_name, 512)
        || !(value.position === null || /^(0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?$/.test(value.position))
    ) {
        throw new TypeError("The bibliographic Series context is invalid.");
    }

    return Object.freeze({ ...value });
}

function readWork(value) {
    if (
        !exact(value, WORK_FIELDS)
        || !/^search-work-[0-9a-f]{64}$/.test(value.result_id)
        || !RESULT_KINDS.has(value.result_kind)
        || !nullableText(value.work_id, 191)
        || !text(value.work_selector, 4096)
        || !text(value.title, 512)
        || !Array.isArray(value.authors)
        || !Array.isArray(value.series)
        || (value.result_kind === "local_canonical" && value.work_id === null)
        || (value.result_kind === "external_candidate" && value.work_id !== null)
    ) {
        throw new TypeError("The bibliographic Work result is invalid.");
    }

    return Object.freeze({
        ...value,
        authors: Object.freeze(value.authors.map(readWorkAuthor)),
        series: Object.freeze(value.series.map(readSeries)),
    });
}

function readAuthorWork(value) {
    if (!exact(value, AUTHOR_WORK_FIELDS)) {
        throw new TypeError("The selected Author Work result is invalid.");
    }
    const work = readWork(Object.fromEntries(
        WORK_FIELDS.map((field) => [field, value[field]])
    ));
    const providerIdentity = readProviderIdentity(value.provider_identity);
    if (
        (value.result_kind === "external_candidate" && providerIdentity === null)
        || (providerIdentity !== null && providerIdentity.provider_key !== "open_library")
    ) {
        throw new TypeError("The selected Author Work identity is invalid.");
    }
    return Object.freeze({ ...work, provider_identity: providerIdentity });
}

function readEdition(value) {
    if (
        !exact(value, EDITION_FIELDS)
        || !/^search-edition-[0-9a-f]{64}$/.test(value.result_id)
        || !RESULT_KINDS.has(value.result_kind)
        || !nullableText(value.edition_id, 191)
        || !/^search-work-[0-9a-f]{64}$/.test(value.parent_work_result_id)
        || !text(value.title, 512)
        || !nullableText(value.subtitle, 512)
        || !boundedTextList(value.contributors, 32, 255)
        || !boundedTextList(value.languages, 16, 16)
        || !boundedTextList(value.publishers, 16, 255)
        || !nullableText(value.publication_date, 64)
        || !(value.isbn_10 === null || isbn10(value.isbn_10))
        || !(value.isbn_13 === null || isbn13(value.isbn_13))
        || (value.isbn_10 !== null && value.isbn_13 === null)
        || (value.isbn_10 !== null && isbn10As13(value.isbn_10) !== value.isbn_13)
        || !nullableText(value.format, 128)
        || !(value.page_count === null || (
            Number.isInteger(value.page_count)
            && value.page_count > 0
            && value.page_count <= 100000
        ))
        || !Number.isInteger(value.presentation_order)
        || value.presentation_order < 0
        || value.presentation_order > 1000000
        || typeof value.requires_materialization !== "boolean"
        || typeof value.can_add_work_only !== "boolean"
        || typeof value.can_add_edition_specific !== "boolean"
        || value.requires_materialization !== (value.result_kind === "external_candidate")
        || value.can_add_work_only !== true
        || value.can_add_edition_specific !== true
        || (value.result_kind === "local_canonical" && value.edition_id === null)
        || (value.result_kind === "external_candidate" && value.edition_id !== null)
    ) {
        throw new TypeError("The bibliographic Edition result is invalid.");
    }

    const providerIdentity = readProviderIdentity(value.provider_identity);
    const providerWorkIdentity = readProviderIdentity(value.provider_work_identity);
    if (
        (value.result_kind === "external_candidate" && providerIdentity === null)
        || (value.result_kind === "external_candidate" && providerWorkIdentity === null)
        || (providerIdentity !== null && providerIdentity.provider_key !== "open_library")
        || (providerWorkIdentity !== null && providerWorkIdentity.provider_key !== "open_library")
        || (providerIdentity !== null
            && providerWorkIdentity !== null
            && providerIdentity.provider_key !== providerWorkIdentity.provider_key)
    ) {
        throw new TypeError("The bibliographic Edition identity is invalid.");
    }

    return Object.freeze({
        ...value,
        provider_identity: providerIdentity,
        provider_work_identity: providerWorkIdentity,
        contributors: Object.freeze([...value.contributors]),
        languages: Object.freeze([...value.languages]),
        publishers: Object.freeze([...value.publishers]),
    });
}

function readGroup(value, readItem) {
    if (
        !exact(value, GROUP_FIELDS)
        || !Array.isArray(value.items)
        || !nullableText(value.next_cursor, 4096)
        || !Array.isArray(value.provider_attempts)
    ) {
        throw new TypeError("The bibliographic result group is invalid.");
    }

    const items = value.items.map(readItem);
    const ids = new Set(items.map((item) => item.result_id));
    if (ids.size !== items.length) {
        throw new TypeError("The bibliographic result group contains duplicates.");
    }

    return Object.freeze({
        items: Object.freeze(items),
        next_cursor: value.next_cursor,
        provider_attempts: Object.freeze(value.provider_attempts.map(readAttempt)),
    });
}

export function readBibliographicAuthorWorks(value) {
    return readGroup(value, readAuthorWork);
}

export function readBibliographicWorkEditions(value) {
    return readGroup(value, readEdition);
}

function freezeDrilldownPage(page) {
    return Object.freeze({
        items: Object.freeze([...page.items]),
        cursor: page.cursor,
        attempts: Object.freeze([...page.attempts]),
        loading: page.loading,
        loadingMore: page.loadingMore,
        error: page.error,
    });
}

export function initialBibliographicDrilldownPage() {
    return freezeDrilldownPage({
        items: [],
        cursor: null,
        attempts: [],
        loading: false,
        loadingMore: false,
        error: null,
    });
}

export function applyBibliographicDrilldownPage(state, response, { append = false } = {}) {
    const items = append ? [...state.items, ...response.items] : response.items;
    const ids = new Set(items.map((item) => item.result_id));
    if (ids.size !== items.length) {
        throw new TypeError("The bibliographic drill-down contains duplicates.");
    }
    return freezeDrilldownPage({
        items,
        cursor: response.next_cursor,
        attempts: response.provider_attempts,
        loading: false,
        loadingMore: false,
        error: null,
    });
}

export function normalizeBibliographicSearchQuery(value) {
    if (typeof value !== "string") {
        throw new TypeError("Vul een titel of auteur in.");
    }

    const normalized = value.trim().replace(/\s+/gu, " ");
    if (normalized.length === 0) {
        throw new TypeError("Vul een titel of auteur in.");
    }
    if (Array.from(normalized).length > MAX_QUERY_LENGTH) {
        throw new TypeError("Gebruik maximaal 100 tekens.");
    }

    return normalized;
}

export function readBibliographicSearch(value) {
    if (
        !exact(value, RESPONSE_FIELDS)
        || !text(value.query, MAX_QUERY_LENGTH)
        || normalizeBibliographicSearchQuery(value.query) !== value.query
    ) {
        throw new TypeError("The bibliographic search response is invalid.");
    }

    return Object.freeze({
        query: value.query,
        authors: readGroup(value.authors, readAuthor),
        works: readGroup(value.works, readWork),
    });
}

function freezeState(state) {
    return Object.freeze({
        query: state.query,
        authors: Object.freeze([...state.authors]),
        authorCursor: state.authorCursor,
        authorAttempts: Object.freeze([...state.authorAttempts]),
        works: Object.freeze([...state.works]),
        workCursor: state.workCursor,
        workAttempts: Object.freeze([...state.workAttempts]),
    });
}

export function initialBibliographicSearchState() {
    return freezeState({
        query: "",
        authors: [],
        authorCursor: null,
        authorAttempts: [],
        works: [],
        workCursor: null,
        workAttempts: [],
    });
}

export function applyBibliographicSearchPage(state, response, group = "all") {
    if (group !== "all" && !GROUPS.has(group)) {
        throw new TypeError("The bibliographic pagination group is invalid.");
    }
    const decoded = readBibliographicSearch(response);

    if (group !== "all" && decoded.query !== state.query) {
        throw new TypeError("The bibliographic continuation query changed.");
    }

    if (group === "authors") {
        return freezeState({
            ...state,
            authors: [...state.authors, ...decoded.authors.items],
            authorCursor: decoded.authors.next_cursor,
            authorAttempts: decoded.authors.provider_attempts,
        });
    }
    if (group === "works") {
        return freezeState({
            ...state,
            works: [...state.works, ...decoded.works.items],
            workCursor: decoded.works.next_cursor,
            workAttempts: decoded.works.provider_attempts,
        });
    }

    return freezeState({
        query: decoded.query,
        authors: decoded.authors.items,
        authorCursor: decoded.authors.next_cursor,
        authorAttempts: decoded.authors.provider_attempts,
        works: decoded.works.items,
        workCursor: decoded.works.next_cursor,
        workAttempts: decoded.works.provider_attempts,
    });
}

function isApiError(error) {
    return error instanceof BiblioApiError || (
        error instanceof Error
        && error.name === "BiblioApiError"
        && ["aborted", "http", "invalid_response", "network"].includes(error.kind)
        && typeof error.code === "string"
        && (error.status === null || Number.isInteger(error.status))
    );
}

function isSessionRefreshError(error) {
    return isApiError(error)
        && error.kind === "http"
        && error.status === 403
        && error.code === "rest_cookie_invalid_nonce";
}

function isAuthenticationError(error) {
    return isApiError(error) && error.status === 401;
}

export function bibliographicSearchErrorMessage(error) {
    if (isSessionRefreshError(error)) {
        return "Je sessie moet worden vernieuwd. Laad de pagina opnieuw.";
    }
    if (isAuthenticationError(error)) {
        return "Je sessie is verlopen. Log opnieuw in.";
    }
    if (isApiError(error) && error.status === 400) {
        return "Zoek op een titel of auteur en probeer opnieuw.";
    }
    if (error instanceof TypeError || (isApiError(error) && error.kind === "invalid_response")) {
        return "Biblio kon de zoekresultaten niet veilig lezen. Probeer het opnieuw.";
    }
    return "Zoeken lukt tijdelijk niet. Probeer het opnieuw.";
}

export function bibliographicDrilldownErrorMessage(error) {
    if (isSessionRefreshError(error) || isAuthenticationError(error)) {
        return bibliographicSearchErrorMessage(error);
    }
    if (isApiError(error) && error.status === 400) {
        return "Dit resultaat is niet meer actueel. Zoek opnieuw om de nieuwste gegevens te laden.";
    }
    if (error instanceof TypeError || (isApiError(error) && error.kind === "invalid_response")) {
        return "Biblio kon deze bibliografische gegevens niet veilig lezen. Probeer het opnieuw.";
    }
    return "Deze bibliografische gegevens kunnen tijdelijk niet worden geladen. Probeer het opnieuw.";
}

function attemptsFailed(attempts) {
    return attempts.some((attempt) => FAILED_STATUSES.has(attempt.status));
}

function el(documentImpl, tagName, { className, textContent, attrs = {} } = {}) {
    const node = documentImpl.createElement(tagName);
    if (className !== undefined) node.className = className;
    if (textContent !== undefined) node.textContent = textContent;
    for (const [name, value] of Object.entries(attrs)) node.setAttribute(name, value);
    return node;
}

function control(documentImpl, label, action, modifier = "secondary") {
    return el(documentImpl, "button", {
        className: `biblio-ui__control biblio-ui__control--${modifier}`,
        textContent: label,
        attrs: { type: "button", "data-search-action": action },
    });
}

function authorNames(authors) {
    return authors.length === 0
        ? "Auteur onbekend"
        : authors.map((author) => author.display_name).join(", ");
}

function seriesLabel(series) {
    return series.map((entry) => (
        entry.position === null
            ? entry.display_name
            : `${entry.display_name} · deel ${entry.position}`
    )).join(", ");
}

export function createBibliographicSearchApp({
    root,
    api,
    documentImpl = globalThis.document,
    eventTarget = globalThis,
    shellFactory = createLibraryShell,
    abortControllerFactory = () => new AbortController(),
    queueMicrotaskImpl = queueMicrotask,
} = {}) {
    if (typeof root?.replaceChildren !== "function") {
        throw new TypeError("A Biblio Search mount is required.");
    }

    const shell = shellFactory(root, {
        documentImpl,
        eventTarget,
        overviewUrl: root.dataset.overviewUrl,
        searchUrl: root.dataset.searchUrl,
        wishlistUrl: root.dataset.wishlistUrl,
        nextReadingUrl: root.dataset.nextReadingUrl,
        activeDestination: "search",
    });
    const host = shell.contentRoot;
    const view = el(documentImpl, "section", {
        className: "biblio-ui__view biblio-ui__bibliographic-search",
        attrs: { "aria-labelledby": "biblio-search-title" },
    });
    const header = el(documentImpl, "header", {
        className: "biblio-ui__search-page-header",
    });
    const title = el(documentImpl, "h1", {
        className: "biblio-ui__page-title",
        textContent: "Zoeken",
        attrs: { id: "biblio-search-title", tabindex: "-1" },
    });
    const eyebrow = el(documentImpl, "p", {
        className: "biblio-ui__eyebrow",
        textContent: "Bibliografisch zoeken",
    });
    const intro = el(documentImpl, "p", {
        className: "biblio-ui__search-intro",
        textContent: "Vind auteurs en boeken in de Biblio-catalogus en in aangesloten bibliografische bronnen.",
    });
    header.append(eyebrow, title, intro);

    const form = el(documentImpl, "form", {
        className: "biblio-ui__bibliographic-search-form",
        attrs: { role: "search", novalidate: "" },
    });
    const label = el(documentImpl, "label", {
        textContent: "Zoek op titel of auteur",
        attrs: { for: "biblio-bibliographic-query" },
    });
    const fieldRow = el(documentImpl, "div", {
        className: "biblio-ui__bibliographic-search-field",
    });
    const input = el(documentImpl, "input", {
        attrs: {
            id: "biblio-bibliographic-query",
            name: "query",
            type: "search",
            maxlength: String(MAX_QUERY_LENGTH),
            autocomplete: "off",
            placeholder: "Bijvoorbeeld een boektitel of auteursnaam",
            "aria-describedby": "biblio-search-help biblio-search-field-error",
        },
    });
    const submit = el(documentImpl, "button", {
        className: "biblio-ui__control biblio-ui__control--primary",
        textContent: "Zoeken",
        attrs: { type: "submit" },
    });
    fieldRow.append(input, submit);
    const help = el(documentImpl, "p", {
        className: "biblio-ui__search-help",
        textContent: "Zoeken op ISBN wordt in een volgende stap aangesloten.",
        attrs: { id: "biblio-search-help" },
    });
    const fieldError = el(documentImpl, "p", {
        className: "biblio-ui__field-error",
        attrs: { id: "biblio-search-field-error" },
    });
    form.append(label, fieldRow, help, fieldError);

    const navigation = el(documentImpl, "nav", {
        className: "biblio-ui__search-tabs",
        attrs: { "aria-label": "Zoekweergave", hidden: "" },
    });
    const tabList = el(documentImpl, "div", {
        className: "biblio-ui__search-tab-list",
        attrs: { role: "tablist", "aria-label": "Resultaatcategorie" },
    });
    const tabLabels = { all: "Alles", books: "Boeken", authors: "Auteurs" };
    const tabButtons = new Map(SEARCH_TABS.map((tab) => {
        const button = el(documentImpl, "button", {
            className: "biblio-ui__search-tab",
            textContent: tabLabels[tab],
            attrs: {
                id: `biblio-search-tab-${tab}`,
                type: "button",
                role: "tab",
                "aria-controls": "biblio-search-panel",
                "aria-selected": tab === "all" ? "true" : "false",
                tabindex: tab === "all" ? "0" : "-1",
                "data-search-tab": tab,
            },
        });
        tabList.append(button);
        return [tab, button];
    }));
    navigation.append(tabList);
    const live = el(documentImpl, "div", {
        className: "biblio-ui__visually-hidden",
        attrs: { role: "status", "aria-live": "polite", "aria-atomic": "true" },
    });
    const results = el(documentImpl, "div", {
        className: "biblio-ui__search-results",
        attrs: { id: "biblio-search-panel", "aria-busy": "false" },
    });
    view.append(header, form, navigation, live, results);
    host.replaceChildren(view);

    let state = initialBibliographicSearchState();
    let activeTab = "all";
    let submittedQuery = "";
    let phase = "idle";
    let requestError = null;
    let paginationError = null;
    let loadingGroup = null;
    let controller = null;
    let revision = 0;
    let destroyed = false;
    let currentView = "results";
    let selectedAuthor = null;
    let selectedWork = null;
    let authorWorks = initialBibliographicDrilldownPage();
    let workEditions = initialBibliographicDrilldownPage();
    let authorReturnContext = null;
    let workReturnContext = null;

    function announce(message) {
        live.textContent = "";
        queueMicrotaskImpl(() => { live.textContent = message; });
    }

    function recoveryControl(error) {
        if (isAuthenticationError(error)) {
            return el(documentImpl, "a", {
                className: "biblio-ui__control biblio-ui__control--secondary",
                textContent: "Opnieuw inloggen",
                attrs: { href: root.dataset.loginUrl },
            });
        }
        if (isSessionRefreshError(error)) {
            return el(documentImpl, "a", {
                className: "biblio-ui__control biblio-ui__control--secondary",
                textContent: "Sessie vernieuwen",
                attrs: { href: root.dataset.searchUrl },
            });
        }
        return null;
    }

    function appendPagination(section, group, cursor) {
        if (cursor === null) return;
        const labelText = group === "authors" ? "Meer auteurs" : "Meer boeken";
        const button = control(documentImpl, labelText, `more-${group}`);
        if (loadingGroup !== null) {
            button.disabled = true;
            if (loadingGroup === group) {
                button.textContent = group === "authors" ? "Auteurs laden…" : "Boeken laden…";
            }
        }
        button.addEventListener("click", () => { void loadMore(group); });
        section.append(button);

        if (paginationError?.group === group) {
            const error = el(documentImpl, "div", {
                className: "biblio-ui__inline-error biblio-ui__search-pagination-error",
                attrs: { role: "alert" },
            });
            error.append(el(documentImpl, "p", {
                textContent: bibliographicSearchErrorMessage(paginationError.error),
            }));
            const recovery = recoveryControl(paginationError.error);
            if (recovery !== null) error.append(recovery);
            section.append(error);
        }
    }

    function sectionHeading(section, titleText, titleId, viewAllTab = null) {
        const heading = el(documentImpl, "div", {
            className: "biblio-ui__search-section-heading",
        });
        heading.append(el(documentImpl, "h2", {
            textContent: titleText,
            attrs: { id: titleId, tabindex: "-1" },
        }));
        if (viewAllTab !== null) {
            const viewAll = control(documentImpl, `Bekijk alle ${titleText.toLowerCase()}`, `view-${viewAllTab}`, "quiet");
            viewAll.className += " biblio-ui__search-view-all";
            viewAll.addEventListener("click", () => switchTab(viewAllTab, true));
            heading.append(viewAll);
        }
        section.append(heading);
    }

    function authorSection({ preview = false } = {}) {
        const section = el(documentImpl, "section", {
            className: `biblio-ui__search-result-group biblio-ui__search-authors${preview ? " biblio-ui__search-result-group--preview" : ""}`,
            attrs: { "aria-labelledby": "biblio-search-authors-title" },
        });
        sectionHeading(section, "Auteurs", "biblio-search-authors-title", preview ? "authors" : null);
        const list = el(documentImpl, "ul", { className: "biblio-ui__author-results" });
        const authors = preview ? state.authors.slice(0, ALL_AUTHOR_PREVIEW_LIMIT) : state.authors;
        authors.forEach((author, index) => {
            const item = el(documentImpl, "li", {
                className: "biblio-ui__author-result",
                attrs: {
                    tabindex: "-1",
                    "data-search-result-group": "authors",
                    "data-search-result-index": String(index),
                },
            });
            const identity = el(documentImpl, "div", {
                className: "biblio-ui__author-result-identity",
            });
            identity.append(
                el(documentImpl, "h3", {
                    className: "biblio-ui__author-result-name",
                    textContent: author.display_name,
                }),
                el(documentImpl, "p", {
                    className: "biblio-ui__author-result-context",
                    textContent: author.result_kind === "local_canonical"
                        ? "Biblio-catalogus"
                        : "Externe bron",
                })
            );
            const action = control(documentImpl, "Bekijk werken", "open-author-works", "quiet");
            action.setAttribute("aria-label", `Bekijk werken van ${author.display_name}`);
            action.setAttribute("data-drilldown-group", "authors");
            action.setAttribute("data-drilldown-index", String(index));
            action.addEventListener("click", () => { void openAuthorWorks(author, index); });
            item.append(identity, action);
            list.append(item);
        });
        section.append(list);
        if (!preview) appendPagination(section, "authors", state.authorCursor);
        return section;
    }

    function workSection({ preview = false } = {}) {
        const section = el(documentImpl, "section", {
            className: `biblio-ui__search-result-group biblio-ui__search-works${preview ? " biblio-ui__search-result-group--preview" : ""}`,
            attrs: { "aria-labelledby": "biblio-search-works-title" },
        });
        sectionHeading(section, "Boeken", "biblio-search-works-title", preview ? "books" : null);
        const list = el(documentImpl, "ul", {
            className: `biblio-ui__work-results${preview ? " biblio-ui__work-results--preview" : " biblio-ui__work-results--full"}`,
        });
        const works = preview ? state.works.slice(0, ALL_WORK_PREVIEW_LIMIT) : state.works;
        if (preview && works.length <= 2) {
            list.className = `${list.className} biblio-ui__work-results--sparse`;
        }
        works.forEach((work, index) => {
            list.append(workResultItem(work, index, {
                group: "works",
                returnView: "results",
                withCover: true,
            }));
        });
        section.append(list);
        if (!preview) appendPagination(section, "works", state.workCursor);
        return section;
    }

    function workResultItem(work, index, { group, returnView, withCover }) {
        const item = el(documentImpl, "li", {
            className: `biblio-ui__work-result${withCover ? "" : " biblio-ui__work-result--compact"}`,
            attrs: {
                tabindex: "-1",
                "data-search-result-group": group === "works" ? "works" : "author-works",
                "data-search-result-index": String(index),
            },
        });
        if (withCover) {
            const cover = el(documentImpl, "div", {
                className: "biblio-ui__cover biblio-ui__search-cover biblio-ui__cover--placeholder biblio-ui__search-cover--empty",
                attrs: { "aria-hidden": "true" },
            });
            cover.append(el(documentImpl, "span", {
                className: "biblio-ui__cover-label",
                textContent: "Geen omslag",
            }));
            item.append(cover);
        }
        const identity = el(documentImpl, "div", {
            className: "biblio-ui__work-result-identity",
        });
        identity.append(
            el(documentImpl, "h3", {
                className: "biblio-ui__work-result-title",
                textContent: work.title,
            }),
            el(documentImpl, "p", {
                className: "biblio-ui__authors",
                textContent: authorNames(work.authors),
            })
        );
        if (work.series.length > 0) {
            identity.append(el(documentImpl, "p", {
                className: "biblio-ui__context",
                textContent: seriesLabel(work.series),
            }));
        }
        const action = control(documentImpl, "Bekijk uitgaven", "open-work-editions", "quiet");
        action.className += " biblio-ui__drilldown-action";
        action.setAttribute("aria-label", `Bekijk uitgaven van ${work.title}`);
        action.setAttribute("data-drilldown-group", group);
        action.setAttribute("data-drilldown-index", String(index));
        action.addEventListener("click", () => { void openWorkEditions(work, returnView, group, index); });
        identity.append(action);
        item.append(identity);
        return item;
    }

    function searchTipCard(tipView = "results") {
        const copy = tipView === "authorWorks"
            ? ["Kies een werk om beschikbare uitgaven te bekijken.", "Terug brengt je naar de geladen zoekresultaten."]
            : tipView === "workEditions"
                ? ["Uitgaven kunnen verschillen in taal, uitgever en verschijningsjaar.", "Een uitgave kiezen of toevoegen volgt in een latere stap."]
                : ["Zoek op titel of auteur.", "ISBN zoeken wordt later aangesloten."];
        const card = el(documentImpl, "section", {
            className: "biblio-ui__search-rail-card biblio-ui__search-tip",
            attrs: { "aria-labelledby": "biblio-search-tip-title" },
        });
        card.append(
            el(documentImpl, "p", {
                className: "biblio-ui__search-rail-kicker",
                textContent: "Zoekhulp",
            }),
            el(documentImpl, "h2", {
                textContent: "Zoektip",
                attrs: { id: "biblio-search-tip-title" },
            }),
            el(documentImpl, "p", { textContent: copy[0] }),
            el(documentImpl, "p", {
                className: "biblio-ui__search-rail-note",
                textContent: copy[1],
            })
        );
        return card;
    }

    function searchScopeCard() {
        const card = el(documentImpl, "section", {
            className: "biblio-ui__search-rail-card biblio-ui__search-scope",
            attrs: { "aria-labelledby": "biblio-search-scope-title" },
        });
        card.append(
            el(documentImpl, "p", {
                className: "biblio-ui__search-rail-kicker",
                textContent: "Zoeken in",
            }),
            el(documentImpl, "h2", {
                textContent: "Biblio-catalogus",
                attrs: { id: "biblio-search-scope-title" },
            }),
            el(documentImpl, "p", {
                className: "biblio-ui__search-rail-note",
                textContent: "Inclusief aangesloten bibliografische bronnen.",
            })
        );
        return card;
    }

    function partialStatusCard(hasResults, noun = "resultaten", retry = null) {
        const card = el(documentImpl, "section", {
            className: "biblio-ui__search-rail-card biblio-ui__search-partial",
            attrs: { role: "status", "aria-labelledby": "biblio-search-partial-title" },
        });
        card.append(
            el(documentImpl, "p", {
                className: "biblio-ui__search-rail-kicker",
                textContent: "Zoekstatus",
            }),
            el(documentImpl, "h2", {
                textContent: "Resultaten",
                attrs: { id: "biblio-search-partial-title" },
            }),
            el(documentImpl, "p", {
                textContent: `Externe ${noun} konden niet volledig worden geladen.`,
            }),
            el(documentImpl, "p", {
                className: "biblio-ui__search-rail-note",
                textContent: hasResults
                    ? "Beschikbare resultaten blijven zichtbaar."
                    : "Niet alle bronnen konden worden bereikt.",
            })
        );
        if (!hasResults) {
            if (retry === null) {
                card.append(retryControl());
            } else {
                const retryButton = control(documentImpl, "Opnieuw proberen", "retry-drilldown");
                retryButton.addEventListener("click", () => { void retry(); });
                card.append(retryButton);
            }
        }
        return card;
    }

    function resultLayout(main, {
        partial = false,
        hasResults = false,
        tipView = "results",
        partialNoun = "resultaten",
        partialRetry = null,
    } = {}) {
        const layout = el(documentImpl, "div", { className: "biblio-ui__search-layout" });
        const rail = el(documentImpl, "aside", {
            className: "biblio-ui__search-rail",
            attrs: { "aria-label": "Zoekscope, zoekstatus en zoektip" },
        });
        rail.append(searchScopeCard());
        if (partial) rail.append(partialStatusCard(hasResults, partialNoun, partialRetry));
        rail.append(searchTipCard(tipView));
        layout.append(main, rail);
        return layout;
    }

    function focusedHeader({ kicker, heading, context, backLabel = "Terug" }) {
        const headerNode = el(documentImpl, "header", {
            className: "biblio-ui__drilldown-header",
        });
        const back = control(documentImpl, `\u2190 ${backLabel}`, "drilldown-back", "quiet");
        back.className += " biblio-ui__drilldown-back";
        back.addEventListener("click", backFromDrilldown);
        const headingNode = el(documentImpl, "h2", {
            className: "biblio-ui__drilldown-title",
            textContent: heading,
            attrs: { id: "biblio-search-drilldown-title", tabindex: "-1" },
        });
        headerNode.append(
            back,
            el(documentImpl, "p", {
                className: "biblio-ui__eyebrow",
                textContent: kicker,
            }),
            headingNode
        );
        if (context !== "") {
            headerNode.append(el(documentImpl, "p", {
                className: "biblio-ui__drilldown-context",
                textContent: context,
            }));
        }
        return { headerNode, headingNode };
    }

    function drilldownError(error, retry) {
        const panel = el(documentImpl, "section", {
            className: "biblio-ui__status-panel biblio-ui__status-panel--danger biblio-ui__drilldown-error",
            attrs: { role: "alert", "aria-labelledby": "biblio-search-drilldown-error-title" },
        });
        panel.append(
            el(documentImpl, "h3", {
                textContent: "Laden is niet gelukt",
                attrs: { id: "biblio-search-drilldown-error-title" },
            }),
            el(documentImpl, "p", { textContent: bibliographicDrilldownErrorMessage(error) })
        );
        const recovery = recoveryControl(error);
        if (recovery !== null) {
            panel.append(recovery);
        } else if (!(isApiError(error) && error.status === 400)) {
            const retryButton = control(documentImpl, "Opnieuw proberen", "retry-drilldown");
            retryButton.addEventListener("click", () => { void retry(); });
            panel.append(retryButton);
        }
        return panel;
    }

    function drilldownPagination(section, page, kind, loadMoreAction) {
        if (page.cursor !== null) {
            const labels = kind === "works"
                ? ["Meer werken", "Werken laden…"]
                : ["Meer uitgaven", "Uitgaven laden…"];
            const button = control(documentImpl, page.loadingMore ? labels[1] : labels[0], `more-${kind}`);
            button.disabled = page.loadingMore;
            button.addEventListener("click", () => { void loadMoreAction(); });
            section.append(button);
        }
        if (page.error !== null && page.items.length > 0) {
            section.append(drilldownError(page.error, loadMoreAction));
        }
    }

    function languageLabel(code) {
        try {
            return new Intl.DisplayNames(["nl"], { type: "language" }).of(code) ?? code.toUpperCase();
        } catch {
            return code.toUpperCase();
        }
    }

    function editionResultItem(edition, index) {
        const item = el(documentImpl, "li", {
            className: "biblio-ui__edition-result",
            attrs: {
                tabindex: "-1",
                "data-search-result-group": "editions",
                "data-search-result-index": String(index),
            },
        });
        const identity = el(documentImpl, "div", { className: "biblio-ui__edition-identity" });
        identity.append(el(documentImpl, "h3", {
            className: "biblio-ui__edition-title",
            textContent: edition.title,
        }));
        if (edition.subtitle !== null) {
            identity.append(el(documentImpl, "p", {
                className: "biblio-ui__edition-subtitle",
                textContent: edition.subtitle,
            }));
        }
        const distinguishing = [
            ...edition.languages.map(languageLabel),
            edition.publication_date,
            ...edition.publishers,
            edition.format,
        ].filter((value) => value !== null && value !== "");
        if (distinguishing.length > 0) {
            identity.append(el(documentImpl, "p", {
                className: "biblio-ui__edition-distinguishing",
                textContent: distinguishing.join(" \u00b7 "),
            }));
        }
        if (edition.contributors.length > 0) {
            identity.append(el(documentImpl, "p", {
                className: "biblio-ui__edition-contributors",
                textContent: `Bijdragen: ${edition.contributors.join(", ")}`,
            }));
        }
        const facts = [];
        const isbn = edition.isbn_13 ?? edition.isbn_10;
        if (isbn !== null) facts.push(["ISBN", isbn]);
        if (edition.page_count !== null) facts.push(["Omvang", `${edition.page_count} pagina's`]);
        if (facts.length > 0) {
            const metadata = el(documentImpl, "dl", { className: "biblio-ui__edition-metadata" });
            for (const [term, value] of facts) {
                metadata.append(
                    el(documentImpl, "dt", { textContent: term }),
                    el(documentImpl, "dd", { textContent: value })
                );
            }
            identity.append(metadata);
        }
        item.append(identity);
        return item;
    }

    function renderAuthorWorksView() {
        const partial = attemptsFailed(authorWorks.attempts);
        const main = el(documentImpl, "div", { className: "biblio-ui__search-main" });
        const focused = focusedHeader({
            kicker: "Auteur",
            heading: selectedAuthor.display_name,
            context: selectedAuthor.result_kind === "local_canonical" ? "Biblio-catalogus" : "Aangesloten bibliografische bron",
            backLabel: "Terug naar zoekresultaten",
        });
        main.append(focused.headerNode);

        const section = el(documentImpl, "section", {
            className: "biblio-ui__drilldown-section",
            attrs: { "aria-labelledby": "biblio-author-works-title" },
        });
        section.append(el(documentImpl, "h2", {
            textContent: "Werken",
            attrs: { id: "biblio-author-works-title" },
        }));
        if (authorWorks.loading) {
            section.append(el(documentImpl, "p", {
                className: "biblio-ui__query-loading",
                textContent: "Werken ophalen…",
                attrs: { role: "status" },
            }));
        } else if (authorWorks.error !== null && authorWorks.items.length === 0) {
            section.append(drilldownError(authorWorks.error, () => loadAuthorWorks(false)));
        } else if (authorWorks.items.length === 0) {
            section.append(categoryEmpty(
                partial ? "Werken konden niet volledig worden geladen" : "Geen werken gevonden",
                partial ? "Probeer het opnieuw om de beschikbare werken op te halen." : "Voor deze auteur zijn geen werken beschikbaar."
            ));
        } else {
            const list = el(documentImpl, "ul", { className: "biblio-ui__author-work-results" });
            authorWorks.items.forEach((work, index) => {
                list.append(workResultItem(work, index, {
                    group: "author-works",
                    returnView: "authorWorks",
                    withCover: false,
                }));
            });
            section.append(list);
            drilldownPagination(section, authorWorks, "works", () => loadAuthorWorks(true));
        }
        main.append(section);
        results.append(resultLayout(main, {
            partial,
            hasResults: authorWorks.items.length > 0,
            tipView: "authorWorks",
            partialNoun: "werken",
            partialRetry: () => loadAuthorWorks(false),
        }));
        return focused.headingNode;
    }

    function renderWorkEditionsView() {
        const partial = attemptsFailed(workEditions.attempts);
        const main = el(documentImpl, "div", { className: "biblio-ui__search-main" });
        const focused = focusedHeader({
            kicker: "Boek / Werk",
            heading: selectedWork.title,
            context: authorNames(selectedWork.authors),
        });
        main.append(focused.headerNode);
        const section = el(documentImpl, "section", {
            className: "biblio-ui__drilldown-section",
            attrs: { "aria-labelledby": "biblio-work-editions-title" },
        });
        section.append(el(documentImpl, "h2", {
            textContent: "Uitgaven",
            attrs: { id: "biblio-work-editions-title" },
        }));
        if (workEditions.loading) {
            section.append(el(documentImpl, "p", {
                className: "biblio-ui__query-loading",
                textContent: "Uitgaven ophalen…",
                attrs: { role: "status" },
            }));
        } else if (workEditions.error !== null && workEditions.items.length === 0) {
            section.append(drilldownError(workEditions.error, () => loadWorkEditions(false)));
        } else if (workEditions.items.length === 0) {
            section.append(categoryEmpty(
                partial ? "Uitgaven konden niet volledig worden geladen" : "Geen uitgaven gevonden",
                partial ? "Probeer het opnieuw om de beschikbare uitgaven op te halen." : "Voor dit werk zijn geen uitgaven beschikbaar."
            ));
        } else {
            const list = el(documentImpl, "ul", { className: "biblio-ui__edition-results" });
            workEditions.items.forEach((edition, index) => list.append(editionResultItem(edition, index)));
            section.append(list);
            drilldownPagination(section, workEditions, "editions", () => loadWorkEditions(true));
        }
        main.append(section);
        results.append(resultLayout(main, {
            partial,
            hasResults: workEditions.items.length > 0,
            tipView: "workEditions",
            partialNoun: "uitgaven",
            partialRetry: () => loadWorkEditions(false),
        }));
        return focused.headingNode;
    }

    function updateTabs() {
        for (const [tab, button] of tabButtons) {
            const selected = tab === activeTab;
            button.className = `biblio-ui__search-tab${selected ? " biblio-ui__search-tab--active" : ""}`;
            button.setAttribute("aria-selected", selected ? "true" : "false");
            button.setAttribute("tabindex", selected ? "0" : "-1");
        }
        results.setAttribute("aria-labelledby", `biblio-search-tab-${activeTab}`);
    }

    function switchTab(tab, focusTab = false) {
        if (!SEARCH_TABS.includes(tab) || phase !== "results") return;
        activeTab = tab;
        updateTabs();
        render();
        announce(`${tabLabels[tab]} weergegeven.`);
        if (focusTab) queueMicrotaskImpl(() => tabButtons.get(tab)?.focus());
    }

    for (const [tab, button] of tabButtons) {
        button.addEventListener("click", () => switchTab(tab));
        button.addEventListener("keydown", (event) => {
            const current = SEARCH_TABS.indexOf(tab);
            let next = null;
            if (event.key === "ArrowRight") next = (current + 1) % SEARCH_TABS.length;
            if (event.key === "ArrowLeft") next = (current - 1 + SEARCH_TABS.length) % SEARCH_TABS.length;
            if (event.key === "Home") next = 0;
            if (event.key === "End") next = SEARCH_TABS.length - 1;
            if (next === null) return;
            event.preventDefault();
            switchTab(SEARCH_TABS[next], true);
        });
    }

    function retryControl() {
        const retry = control(documentImpl, "Opnieuw proberen", "retry");
        retry.addEventListener("click", () => { void submitQuery(submittedQuery); });
        return retry;
    }

    function render() {
        const hasSubmittedQuery = submittedQuery !== "";
        view.className = `biblio-ui__view biblio-ui__bibliographic-search${hasSubmittedQuery ? " biblio-ui__bibliographic-search--results" : ""}`;
        eyebrow.textContent = hasSubmittedQuery ? "Zoeken" : "Bibliografisch zoeken";
        title.textContent = hasSubmittedQuery ? "Zoekresultaten" : "Zoeken";
        intro.textContent = hasSubmittedQuery
            ? `Resultaten voor “${submittedQuery}”`
            : "Vind auteurs en boeken in de Biblio-catalogus en in aangesloten bibliografische bronnen.";
        submit.disabled = phase === "loading";
        const drilldownLoading = currentView === "authorWorks"
            ? authorWorks.loading || authorWorks.loadingMore
            : currentView === "workEditions"
                ? workEditions.loading || workEditions.loadingMore
                : false;
        results.setAttribute("aria-busy", phase === "loading" || drilldownLoading ? "true" : "false");
        navigation.hidden = phase !== "results" || currentView !== "results";
        if (phase === "results" && currentView === "results") {
            results.setAttribute("role", "tabpanel");
            updateTabs();
        } else {
            results.removeAttribute("role");
            results.removeAttribute("aria-labelledby");
        }
        results.replaceChildren();

        if (phase === "results" && currentView === "authorWorks") {
            renderAuthorWorksView();
            return;
        }
        if (phase === "results" && currentView === "workEditions") {
            renderWorkEditionsView();
            return;
        }

        if (phase === "idle") {
            const empty = el(documentImpl, "section", {
                className: "biblio-ui__search-welcome",
                attrs: { "aria-labelledby": "biblio-search-welcome-title" },
            });
            empty.append(
                el(documentImpl, "h2", {
                    textContent: "Waar wil je naar zoeken?",
                    attrs: { id: "biblio-search-welcome-title" },
                }),
                el(documentImpl, "p", {
                    textContent: "Auteurs en boeken verschijnen hier in afzonderlijke groepen.",
                })
            );
            results.append(empty);
            return;
        }

        if (phase === "loading") {
            results.append(el(documentImpl, "p", {
                className: "biblio-ui__query-loading",
                textContent: "Auteurs en boeken zoeken…",
                attrs: { role: "status" },
            }));
            return;
        }

        if (phase === "error") {
            const error = el(documentImpl, "section", {
                className: "biblio-ui__status-panel biblio-ui__status-panel--danger biblio-ui__search-error",
                attrs: { role: "alert", "aria-labelledby": "biblio-search-error-title" },
            });
            error.append(
                el(documentImpl, "h2", {
                    textContent: "Zoeken is niet gelukt",
                    attrs: { id: "biblio-search-error-title" },
                }),
                el(documentImpl, "p", {
                    textContent: bibliographicSearchErrorMessage(requestError),
                })
            );
            const recovery = recoveryControl(requestError);
            if (recovery !== null) {
                error.append(recovery);
            } else {
                error.append(retryControl());
            }
            results.append(error);
            return;
        }

        const hasAuthors = state.authors.length > 0;
        const hasWorks = state.works.length > 0;
        const failed = attemptsFailed([...state.authorAttempts, ...state.workAttempts]);

        const hasResults = hasAuthors || hasWorks;
        const main = el(documentImpl, "div", { className: "biblio-ui__search-main" });

        if (!hasResults) {
            const empty = el(documentImpl, "section", {
                className: "biblio-ui__empty-state biblio-ui__search-empty",
                attrs: { "aria-labelledby": "biblio-search-empty-title" },
            });
            empty.append(
                el(documentImpl, "h2", {
                    textContent: failed ? "Geen lokale resultaten beschikbaar" : "Geen auteurs of boeken gevonden",
                    attrs: { id: "biblio-search-empty-title" },
                }),
                el(documentImpl, "p", {
                    textContent: failed
                        ? "De externe zoekstatus staat hiernaast."
                        : "Controleer de spelling of probeer een andere titel of auteursnaam.",
                })
            );
            main.append(empty);
            results.append(resultLayout(main, { partial: failed, hasResults: false }));
            return;
        }

        const groups = el(documentImpl, "div", { className: "biblio-ui__search-result-groups" });
        if (activeTab === "all") {
            if (hasWorks) groups.append(workSection({ preview: true }));
            if (hasAuthors) groups.append(authorSection({ preview: true }));
        } else if (activeTab === "books") {
            if (hasWorks) {
                groups.append(workSection());
            } else {
                groups.append(categoryEmpty("Boeken", "Geen boeken gevonden voor deze zoekopdracht."));
            }
        } else if (hasAuthors) {
            groups.append(authorSection());
        } else {
            groups.append(categoryEmpty("Auteurs", "Geen auteurs gevonden voor deze zoekopdracht."));
        }
        main.append(groups);
        results.append(resultLayout(main, { partial: failed, hasResults: true }));
    }

    function categoryEmpty(titleText, message) {
        const empty = el(documentImpl, "section", {
            className: "biblio-ui__empty-state biblio-ui__search-empty",
        });
        empty.append(
            el(documentImpl, "h2", { textContent: titleText }),
            el(documentImpl, "p", { textContent: message })
        );
        return empty;
    }

    async function submitQuery(rawQuery = input.value) {
        let query;
        try {
            query = normalizeBibliographicSearchQuery(rawQuery);
        } catch (error) {
            fieldError.textContent = error.message;
            input.setAttribute("aria-invalid", "true");
            input.focus();
            return;
        }

        fieldError.textContent = "";
        input.removeAttribute("aria-invalid");
        input.value = query;
        submittedQuery = query;
        activeTab = "all";
        currentView = "results";
        selectedAuthor = null;
        selectedWork = null;
        authorWorks = initialBibliographicDrilldownPage();
        workEditions = initialBibliographicDrilldownPage();
        authorReturnContext = null;
        workReturnContext = null;
        controller?.abort();
        controller = abortControllerFactory();
        const requestRevision = ++revision;
        phase = "loading";
        requestError = null;
        paginationError = null;
        loadingGroup = null;
        render();
        announce(`Zoeken naar “${query}”.`);

        try {
            const response = await api.post("me/bibliographic-searches", {
                query,
                author_cursor: null,
                work_cursor: null,
            }, { signal: controller.signal });
            if (destroyed || requestRevision !== revision) return;
            const decoded = readBibliographicSearch(response);
            if (decoded.query !== query) {
                throw new TypeError("The bibliographic response query changed.");
            }
            state = applyBibliographicSearchPage(state, decoded);
            input.value = state.query;
            phase = "results";
            render();
            const total = state.authors.length + state.works.length;
            const loaded = total === 0
                ? "De zoekopdracht is afgerond."
                : `${state.authors.length} ${state.authors.length === 1 ? "auteur" : "auteurs"} en ${state.works.length} ${state.works.length === 1 ? "boek" : "boeken"} geladen.`;
            announce(attemptsFailed([...state.authorAttempts, ...state.workAttempts])
                ? `${loaded} Externe resultaten konden niet volledig worden geladen.`
                : loaded);
        } catch (error) {
            if (destroyed || requestRevision !== revision || error?.kind === "aborted") return;
            requestError = error;
            phase = "error";
            render();
            announce(bibliographicSearchErrorMessage(error));
        }
    }

    async function loadMore(group) {
        if (!GROUPS.has(group) || loadingGroup !== null || phase !== "results") return;
        const cursor = group === "authors" ? state.authorCursor : state.workCursor;
        if (cursor === null) return;

        const startIndex = group === "authors" ? state.authors.length : state.works.length;
        controller?.abort();
        controller = abortControllerFactory();
        const requestRevision = ++revision;
        loadingGroup = group;
        paginationError = null;
        render();
        announce(group === "authors" ? "Meer auteurs laden." : "Meer boeken laden.");

        try {
            const response = await api.post("me/bibliographic-searches", {
                query: state.query,
                author_cursor: group === "authors" ? cursor : null,
                work_cursor: group === "works" ? cursor : null,
            }, { signal: controller.signal });
            if (destroyed || requestRevision !== revision) return;
            state = applyBibliographicSearchPage(state, response, group);
            loadingGroup = null;
            render();
            const added = (group === "authors" ? state.authors.length : state.works.length) - startIndex;
            const label = group === "authors" ? (added === 1 ? "auteur" : "auteurs") : (added === 1 ? "boek" : "boeken");
            const partial = attemptsFailed(group === "authors" ? state.authorAttempts : state.workAttempts)
                ? " Externe resultaten konden niet volledig worden geladen."
                : "";
            announce(added === 0
                ? `Geen nieuwe ${label} geladen.${partial}`
                : `${added} ${label} toegevoegd.${partial}`);
            queueMicrotaskImpl(() => {
                const target = added > 0
                    ? results.querySelector(
                        `[data-search-result-group="${group}"][data-search-result-index="${startIndex}"]`
                    )
                    : results.querySelector(`#biblio-search-${group}-title`);
                target?.focus();
            });
        } catch (error) {
            if (destroyed || requestRevision !== revision || error?.kind === "aborted") return;
            loadingGroup = null;
            paginationError = { group, error };
            render();
            announce(bibliographicSearchErrorMessage(error));
            queueMicrotaskImpl(() => {
                results.querySelector(`[data-search-action="more-${group}"]`)?.focus();
            });
        }
    }

    function scrollPosition() {
        return Number.isFinite(eventTarget?.scrollY) ? eventTarget.scrollY : 0;
    }

    function focusDrilldownHeading() {
        queueMicrotaskImpl(() => {
            results.querySelector("#biblio-search-drilldown-title")?.focus();
        });
    }

    function restoreContext(context) {
        queueMicrotaskImpl(() => {
            results.querySelector(
                `[data-drilldown-group="${context.group}"][data-drilldown-index="${context.index}"]`
            )?.focus();
            if (typeof eventTarget?.scrollTo === "function") {
                eventTarget.scrollTo({ top: context.scrollY, behavior: "auto" });
            }
        });
    }

    function startDrilldownRequest(page, append) {
        return freezeDrilldownPage({
            ...page,
            loading: !append,
            loadingMore: append,
            error: null,
        });
    }

    function failDrilldownRequest(page, error) {
        return freezeDrilldownPage({
            ...page,
            loading: false,
            loadingMore: false,
            error,
        });
    }

    async function openAuthorWorks(author, index) {
        if (phase !== "results" || currentView !== "results") return;
        authorReturnContext = Object.freeze({
            view: "results",
            group: "authors",
            index,
            scrollY: scrollPosition(),
        });
        selectedAuthor = Object.freeze({
            display_name: author.display_name,
            result_kind: author.result_kind,
            author_selector: author.author_selector,
        });
        selectedWork = null;
        workReturnContext = null;
        currentView = "authorWorks";
        authorWorks = startDrilldownRequest(initialBibliographicDrilldownPage(), false);
        render();
        focusDrilldownHeading();
        announce(`Werken van ${author.display_name} laden.`);
        await loadAuthorWorks(false);
    }

    async function loadAuthorWorks(append) {
        if (selectedAuthor === null || currentView !== "authorWorks") return;
        const cursor = append ? authorWorks.cursor : null;
        if (append && cursor === null) return;
        controller?.abort();
        controller = abortControllerFactory();
        const requestRevision = ++revision;
        const startIndex = authorWorks.items.length;
        authorWorks = startDrilldownRequest(authorWorks, append);
        render();
        announce(append ? "Meer werken laden." : `Werken van ${selectedAuthor.display_name} laden.`);

        try {
            const response = await api.post("me/bibliographic-author-works", {
                author_selector: selectedAuthor.author_selector,
                cursor,
            }, { signal: controller.signal });
            if (destroyed || requestRevision !== revision || currentView !== "authorWorks") return;
            const decoded = readBibliographicAuthorWorks(response);
            authorWorks = applyBibliographicDrilldownPage(authorWorks, decoded, { append });
            render();
            if (!append) focusDrilldownHeading();
            const added = authorWorks.items.length - startIndex;
            const partial = attemptsFailed(authorWorks.attempts)
                ? " Externe werken konden niet volledig worden geladen."
                : "";
            announce(append
                ? `${added} ${added === 1 ? "werk" : "werken"} toegevoegd.${partial}`
                : `${authorWorks.items.length} ${authorWorks.items.length === 1 ? "werk" : "werken"} geladen.${partial}`);
            if (append) {
                queueMicrotaskImpl(() => {
                    const target = added > 0
                        ? results.querySelector(`[data-search-result-group="author-works"][data-search-result-index="${startIndex}"]`)
                        : results.querySelector("#biblio-author-works-title");
                    target?.focus();
                });
            }
        } catch (error) {
            if (destroyed || requestRevision !== revision || error?.kind === "aborted") return;
            authorWorks = failDrilldownRequest(authorWorks, error);
            render();
            if (!append) focusDrilldownHeading();
            announce(bibliographicDrilldownErrorMessage(error));
            if (append) {
                queueMicrotaskImpl(() => {
                    results.querySelector('[data-search-action="retry-drilldown"]')?.focus();
                });
            }
        }
    }

    async function openWorkEditions(work, returnView, group, index) {
        if (phase !== "results" || !SEARCH_VIEWS.has(returnView) || returnView === "workEditions") return;
        if (currentView !== returnView) return;
        workReturnContext = Object.freeze({
            view: returnView,
            group,
            index,
            scrollY: scrollPosition(),
        });
        selectedWork = Object.freeze({
            result_id: work.result_id,
            title: work.title,
            authors: Object.freeze([...work.authors]),
            series: Object.freeze([...work.series]),
            work_selector: work.work_selector,
        });
        currentView = "workEditions";
        workEditions = startDrilldownRequest(initialBibliographicDrilldownPage(), false);
        render();
        focusDrilldownHeading();
        announce(`Uitgaven van ${work.title} laden.`);
        await loadWorkEditions(false);
    }

    async function loadWorkEditions(append) {
        if (selectedWork === null || currentView !== "workEditions") return;
        const cursor = append ? workEditions.cursor : null;
        if (append && cursor === null) return;
        controller?.abort();
        controller = abortControllerFactory();
        const requestRevision = ++revision;
        const startIndex = workEditions.items.length;
        workEditions = startDrilldownRequest(workEditions, append);
        render();
        announce(append ? "Meer uitgaven laden." : `Uitgaven van ${selectedWork.title} laden.`);

        try {
            const response = await api.post("me/bibliographic-work-editions", {
                work_selector: selectedWork.work_selector,
                cursor,
            }, { signal: controller.signal });
            if (destroyed || requestRevision !== revision || currentView !== "workEditions") return;
            const decoded = readBibliographicWorkEditions(response);
            if (decoded.items.some((edition) => edition.parent_work_result_id !== selectedWork.result_id)) {
                throw new TypeError("The Edition result belongs to another Work.");
            }
            workEditions = applyBibliographicDrilldownPage(workEditions, decoded, { append });
            render();
            if (!append) focusDrilldownHeading();
            const added = workEditions.items.length - startIndex;
            const partial = attemptsFailed(workEditions.attempts)
                ? " Externe uitgaven konden niet volledig worden geladen."
                : "";
            announce(append
                ? `${added} ${added === 1 ? "uitgave" : "uitgaven"} toegevoegd.${partial}`
                : `${workEditions.items.length} ${workEditions.items.length === 1 ? "uitgave" : "uitgaven"} geladen.${partial}`);
            if (append) {
                queueMicrotaskImpl(() => {
                    const target = added > 0
                        ? results.querySelector(`[data-search-result-group="editions"][data-search-result-index="${startIndex}"]`)
                        : results.querySelector("#biblio-work-editions-title");
                    target?.focus();
                });
            }
        } catch (error) {
            if (destroyed || requestRevision !== revision || error?.kind === "aborted") return;
            workEditions = failDrilldownRequest(workEditions, error);
            render();
            if (!append) focusDrilldownHeading();
            announce(bibliographicDrilldownErrorMessage(error));
            if (append) {
                queueMicrotaskImpl(() => {
                    results.querySelector('[data-search-action="retry-drilldown"]')?.focus();
                });
            }
        }
    }

    function backFromDrilldown() {
        if (currentView === "results") return;
        controller?.abort();
        revision += 1;
        const context = currentView === "workEditions" ? workReturnContext : authorReturnContext;
        if (context === null) return;
        currentView = context.view;
        render();
        announce(currentView === "results" ? "Zoekresultaten weergegeven." : `Werken van ${selectedAuthor.display_name} weergegeven.`);
        restoreContext(context);
    }

    form.addEventListener("submit", (event) => {
        event.preventDefault();
        if (phase === "loading") return;
        void submitQuery();
    });
    render();
    title.focus();

    return Object.freeze({
        submitQuery,
        loadMore,
        openAuthorWorks,
        openWorkEditions,
        backFromDrilldown,
        snapshot() { return state; },
        drilldownSnapshot() {
            return Object.freeze({
                view: currentView,
                selectedAuthor,
                authorWorks,
                selectedWork,
                workEditions,
                returnView: workReturnContext?.view ?? null,
            });
        },
        destroy() {
            destroyed = true;
            revision += 1;
            controller?.abort();
            shell.destroy();
            root.replaceChildren();
        },
    });
}

function bootstrap() {
    for (const root of document.querySelectorAll("[data-biblio-search-root]")) {
        const api = createBiblioApi({
            restRoot: root.dataset.restRoot,
            restNonce: root.dataset.restNonce,
        });
        createBibliographicSearchApp({ root, api });
    }
}

if (typeof document !== "undefined") {
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", bootstrap, { once: true });
    } else {
        bootstrap();
    }
}
