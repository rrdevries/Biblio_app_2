import { BiblioApiError, createBiblioApi } from "biblio-ui/api";
import { createDetailView } from "biblio-ui/detail-view";
import { createEndReadingView } from "biblio-ui/end-reading-view";
import { resolveLibraryContext } from "biblio-ui/library-state";
import { createOverviewView } from "biblio-ui/overview-view";
import {
    createReadingHistoryView,
    readingHistoryPath,
    readReadingHistoryPage,
} from "biblio-ui/reading-history";
import { createStartReadingView } from "biblio-ui/start-reading-view";
import {
    buildRouteUrl,
    createRouteController,
} from "biblio-ui/route-state";

export { BiblioApiError, createBiblioApi } from "biblio-ui/api";
export { createDetailView } from "biblio-ui/detail-view";
export { createEndReadingView } from "biblio-ui/end-reading-view";
export { resolveLibraryContext } from "biblio-ui/library-state";
export { createOverviewView } from "biblio-ui/overview-view";
export {
    createReadingHistoryView,
    formatReadingDate,
    readingHistoryPath,
    readReadingHistoryPage,
} from "biblio-ui/reading-history";
export { createStartReadingView } from "biblio-ui/start-reading-view";
export {
    buildRouteUrl,
    createRouteController,
    readRouteState,
} from "biblio-ui/route-state";

const mountedApps = new WeakMap();
const METADATA_STATES = new Set([
    "known",
    "missing",
    "not_applicable",
    "unknown",
]);
const READING_STATUSES = new Set(["not_read", "reading", "read"]);
const READING_ROUND_END_OUTCOMES = new Set(["completed", "stopped"]);

function mountValue(mount, key) {
    const value = mount?.dataset?.[key];

    if (typeof value !== "string" || value.length === 0) {
        throw new TypeError(`The Biblio mount requires data-${key}.`);
    }

    return value;
}

function isRecord(value) {
    return value !== null && typeof value === "object" && !Array.isArray(value);
}

function assertLibraryPresentation(library) {
    if (
        !isRecord(library)
        || typeof library.library_id !== "string"
        || library.library_id.length === 0
        || typeof library.name !== "string"
        || library.name.length === 0
        || !isRecord(library.capabilities)
        || typeof library.capabilities.use_item_directly !== "boolean"
        || typeof library.capabilities.receive_internal_loan !== "boolean"
    ) {
        throw new TypeError("The Biblio Library presentation contract is invalid.");
    }
}

function assertTextValue(value) {
    return isRecord(value)
        && METADATA_STATES.has(value.state)
        && (value.value === null || typeof value.value === "string");
}

function assertTextListValue(value) {
    return isRecord(value)
        && METADATA_STATES.has(value.state)
        && Array.isArray(value.values)
        && value.values.every((entry) => typeof entry === "string");
}

function assertOverviewItem(item) {
    if (
        !isRecord(item)
        || typeof item.item_id !== "string"
        || item.item_id.length === 0
        || typeof item.work_id !== "string"
        || item.work_id.length === 0
        || typeof item.edition_id !== "string"
        || item.edition_id.length === 0
        || typeof item.title !== "string"
        || item.title.length === 0
        || !assertTextListValue(item.authors)
        || !assertTextValue(item.cover_reference)
        || !assertTextValue(item.form)
        || !assertTextValue(item.location_or_source)
        || !READING_STATUSES.has(item.reading_status)
        || typeof item.item_status !== "string"
        || !isRecord(item.capabilities)
        || typeof item.capabilities.view_item !== "boolean"
        || typeof item.capabilities.start_reading !== "boolean"
    ) {
        throw new TypeError("The Biblio Item overview contract is invalid.");
    }
}

function readOverview(payload, selectedLibraryId) {
    if (
        !isRecord(payload)
        || !isRecord(payload.library)
        || payload.library.library_id !== selectedLibraryId
        || !Array.isArray(payload.items)
        || !(
            payload.next_cursor === null
            || (typeof payload.next_cursor === "string"
                && payload.next_cursor.length > 0)
        )
    ) {
        throw new TypeError("The Biblio overview response is invalid.");
    }

    assertLibraryPresentation(payload.library);
    payload.items.forEach(assertOverviewItem);

    return Object.freeze({
        library: payload.library,
        items: Object.freeze([...payload.items]),
        nextCursor: payload.next_cursor,
    });
}

function assertReadingSummary(reading) {
    if (
        !isRecord(reading)
        || !READING_STATUSES.has(reading.status)
        || ![
            "active_rounds",
            "completed_rounds",
            "stopped_rounds",
            "historical_completed_rounds",
        ].every((field) => (
            Number.isInteger(reading[field]) && reading[field] >= 0
        ))
    ) {
        throw new TypeError("The Biblio Reading summary contract is invalid.");
    }
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

function isExactCalendarDate(value) {
    const match = typeof value === "string"
        ? /^(?<year>[0-9]{4})-(?<month>[0-9]{2})-(?<day>[0-9]{2})$/u.exec(value)
        : null;

    return match !== null && isReadingDate({
        year: Number(match.groups.year),
        month: Number(match.groups.month),
        day: Number(match.groups.day),
    });
}

function assertActiveReadingRound(round) {
    if (round === null) {
        return;
    }

    if (
        !isRecord(round)
        || !hasExactFields(round, [
            "reading_round_id",
            "version",
            "started_on",
        ])
        || typeof round.reading_round_id !== "string"
        || round.reading_round_id.length === 0
        || !Number.isInteger(round.version)
        || round.version < 1
        || !(round.started_on === null || isReadingDate(round.started_on))
    ) {
        throw new TypeError("The active ReadingRound contract is invalid.");
    }
}

function readDetail(payload, selectedLibraryId, requestedItemId) {
    const textFields = [
        "cover_reference",
        "isbn",
        "language",
        "publisher",
        "publication_date",
        "series",
        "form",
        "location",
        "condition",
        "acquisition",
        "availability",
    ];

    if (
        !isRecord(payload)
        || !isRecord(payload.library)
        || payload.library.library_id !== selectedLibraryId
        || payload.item_id !== requestedItemId
        || typeof payload.work_id !== "string"
        || payload.work_id.length === 0
        || typeof payload.edition_id !== "string"
        || payload.edition_id.length === 0
        || typeof payload.title !== "string"
        || payload.title.length === 0
        || !assertTextListValue(payload.authors)
        || !textFields.every((field) => assertTextValue(payload[field]))
        || typeof payload.item_status !== "string"
        || !isRecord(payload.capabilities)
        || typeof payload.capabilities.view_item !== "boolean"
        || typeof payload.capabilities.start_reading !== "boolean"
        || typeof payload.capabilities.end_reading !== "boolean"
    ) {
        throw new TypeError("The Biblio Item detail contract is invalid.");
    }

    assertLibraryPresentation(payload.library);
    assertReadingSummary(payload.reading);
    assertActiveReadingRound(payload.active_reading_round);

    if (
        payload.capabilities.end_reading === true
        && payload.active_reading_round === null
    ) {
        throw new TypeError("The Biblio Item detail contract is invalid.");
    }

    return payload;
}

function assertStartedRound(payload, requestedItemId, startedOn) {
    const [year, month, day] = startedOn.split("-").map(Number);

    if (
        !isRecord(payload)
        || typeof payload.reading_round_id !== "string"
        || payload.reading_round_id.length === 0
        || typeof payload.work_id !== "string"
        || payload.work_id.length === 0
        || !isRecord(payload.source)
        || payload.source.type !== "library_item"
        || payload.source.item_id !== requestedItemId
        || payload.lifecycle !== "active"
        || !isRecord(payload.started_on)
        || payload.started_on.year !== year
        || payload.started_on.month !== month
        || payload.started_on.day !== day
        || !Number.isInteger(payload.version)
        || payload.version < 1
    ) {
        throw new TypeError("The started ReadingRound contract is invalid.");
    }
}

function overviewPath(libraryId, cursor = null) {
    const path = `libraries/${encodeURIComponent(libraryId)}/items`;

    return cursor === null
        ? path
        : `${path}?cursor=${encodeURIComponent(cursor)}`;
}

function detailPath(libraryId, itemId) {
    return `libraries/${encodeURIComponent(libraryId)}`
        + `/items/${encodeURIComponent(itemId)}`;
}

function startReadingPath(libraryId, itemId) {
    return `${detailPath(libraryId, itemId)}/reading-rounds`;
}

function endReadingPath(readingRoundId) {
    return `me/reading-rounds/${encodeURIComponent(readingRoundId)}/end`;
}

function readEndReadingIntent(intent) {
    if (
        !isRecord(intent)
        || !hasExactFields(intent, ["outcome", "finishedOn"])
        || !READING_ROUND_END_OUTCOMES.has(intent.outcome)
        || !isExactCalendarDate(intent.finishedOn)
    ) {
        throw new TypeError("The end ReadingRound intent is invalid.");
    }

    return Object.freeze({
        outcome: intent.outcome,
        finishedOn: intent.finishedOn,
    });
}

function assertEndedRound(payload, readingRoundId, intent) {
    const [year, month, day] = intent.finishedOn.split("-").map(Number);

    if (
        !isRecord(payload)
        || !hasExactFields(payload, [
            "reading_round_id",
            "lifecycle",
            "outcome",
            "finished_on",
            "version",
        ])
        || payload.reading_round_id !== readingRoundId
        || payload.lifecycle !== "ended"
        || payload.outcome !== intent.outcome
        || !isReadingDate(payload.finished_on)
        || payload.finished_on.year !== year
        || payload.finished_on.month !== month
        || payload.finished_on.day !== day
        || !Number.isInteger(payload.version)
        || payload.version < 1
    ) {
        throw new TypeError("The ended ReadingRound contract is invalid.");
    }
}

function isAborted(error) {
    return error instanceof BiblioApiError && error.kind === "aborted";
}

function isUnavailable(error) {
    return error instanceof BiblioApiError
        && error.kind === "http"
        && error.status === 404
        && error.code === "biblio_resource_not_available";
}

function isHttpError(error, status, code = null) {
    return error instanceof BiblioApiError
        && error.kind === "http"
        && error.status === status
        && (code === null || error.code === code);
}

function startReadingErrorOutcome(error) {
    if (isAborted(error)) {
        return Object.freeze({ state: "aborted" });
    }

    if (
        isHttpError(error, 400)
        || isHttpError(error, 422, "biblio_validation_failed")
    ) {
        return Object.freeze({ state: "validation-error" });
    }

    if (isHttpError(error, 403, "rest_cookie_invalid_nonce")) {
        return Object.freeze({ state: "session-refresh" });
    }

    if (isHttpError(error, 401, "biblio_authentication_required")) {
        return Object.freeze({ state: "authentication-required" });
    }

    if (isHttpError(error, 404, "biblio_resource_not_available")) {
        return Object.freeze({
            state: "reconcile",
            notice: "Boek is niet meer beschikbaar. Leesstatus bijwerken.",
            refreshedNotice: "Boek is niet meer beschikbaar.",
        });
    }

    if (isHttpError(error, 409)) {
        return Object.freeze({
            state: "reconcile",
            notice: "De leesstatus is gewijzigd. Leesstatus bijwerken.",
            refreshedNotice: "De leesstatus is gewijzigd.",
        });
    }

    return Object.freeze({ state: "retryable" });
}

function endReadingErrorOutcome(error) {
    if (isAborted(error)) {
        return Object.freeze({ state: "outcome-unknown" });
    }

    if (
        isHttpError(error, 400)
        || isHttpError(error, 422, "biblio_validation_failed")
    ) {
        return Object.freeze({ state: "validation-error" });
    }

    if (isHttpError(error, 403, "rest_cookie_invalid_nonce")) {
        return Object.freeze({ state: "session-refresh" });
    }

    if (isHttpError(error, 401, "biblio_authentication_required")) {
        return Object.freeze({ state: "authentication-required" });
    }

    if (isHttpError(error, 404, "biblio_resource_not_available")) {
        return Object.freeze({
            state: "reconcile",
            notice: "Leesstatus bijwerken.",
            refreshedNotice: "De leesstatus is bijgewerkt.",
        });
    }

    if (isHttpError(error, 409, "biblio_reading_round_stale")) {
        return Object.freeze({
            state: "reconcile",
            notice: "De leesstatus is gewijzigd. Leesstatus bijwerken.",
            refreshedNotice: "De leesstatus is gewijzigd.",
        });
    }

    if (isHttpError(error, 503, "biblio_core_unavailable")) {
        return Object.freeze({ state: "service-unavailable" });
    }

    if (isHttpError(error, 500, "biblio_internal_error")) {
        return Object.freeze({ state: "internal-error" });
    }

    return Object.freeze({ state: "outcome-unknown" });
}

function readingHistoryRecovery(error) {
    if (isHttpError(error, 401, "biblio_authentication_required")) {
        return "authentication";
    }

    if (isHttpError(error, 403, "rest_cookie_invalid_nonce")) {
        return "session";
    }

    return "retry";
}

export function readMountConfig(mount) {
    return Object.freeze({
        restRoot: mountValue(mount, "restRoot"),
        restNonce: mountValue(mount, "restNonce"),
        overviewUrl: mountValue(mount, "overviewUrl"),
        loginUrl: mountValue(mount, "loginUrl"),
    });
}

export function createLibraryApp(mount, {
    apiFactory = createBiblioApi,
    fetchImpl,
    historyImpl = globalThis.history,
    locationImpl = globalThis.location,
    eventTarget = globalThis,
    documentImpl = globalThis.document,
    viewFactory = createOverviewView,
    detailViewFactory = createDetailView,
    endReadingViewFactory = createEndReadingView,
    readingHistoryViewFactory = createReadingHistoryView,
    startReadingViewFactory = createStartReadingView,
    reload = () => locationImpl.reload(),
    abortControllerFactory = () => new AbortController(),
} = {}) {
    const config = readMountConfig(mount);
    const apiConfig = {
        restRoot: config.restRoot,
        restNonce: config.restNonce,
    };

    if (fetchImpl !== undefined) {
        apiConfig.fetchImpl = fetchImpl;
    }

    const api = apiFactory(apiConfig);
    const routes = createRouteController({
        overviewUrl: config.overviewUrl,
        historyImpl,
        locationImpl,
        eventTarget,
    });
    let view;
    let detailView;
    let endReadingView;
    let readingHistoryView;
    let startReadingView;
    let currentController = null;
    let mutationController = null;
    let generation = 0;
    let unsubscribePopState = null;
    let started = false;
    let idlePromise = Promise.resolve();

    function currentView() {
        if (view === undefined) {
            view = viewFactory(mount, {
                documentImpl,
                overviewUrl: config.overviewUrl,
                itemUrl(libraryId, itemId) {
                    return buildRouteUrl(config.overviewUrl, {
                        libraryId,
                        itemId,
                    });
                },
            });
        }

        return view;
    }

    function currentDetailView() {
        if (detailView === undefined) {
            detailView = detailViewFactory(mount, { documentImpl });
        }

        return detailView;
    }

    function currentStartReadingView() {
        if (startReadingView === undefined) {
            startReadingView = startReadingViewFactory(mount, {
                documentImpl,
                loginUrl: config.loginUrl,
                reload,
            });
        }

        return startReadingView;
    }

    function currentEndReadingView() {
        if (endReadingView === undefined) {
            endReadingView = endReadingViewFactory(mount, {
                documentImpl,
                loginUrl: config.loginUrl,
                reload,
            });
        }

        return endReadingView;
    }

    function currentReadingHistoryView() {
        if (readingHistoryView === undefined) {
            readingHistoryView = readingHistoryViewFactory(mount, {
                documentImpl,
            });
        }

        return readingHistoryView;
    }

    async function loadLibraryContext({ signal } = {}) {
        const routeState = routes.read();
        let payload;

        try {
            payload = await api.get("me/libraries", { signal });
        } catch (error) {
            if (isAborted(error)) {
                return Object.freeze({ state: "aborted" });
            }

            throw error;
        }

        if (
            payload === null
            || typeof payload !== "object"
            || !Array.isArray(payload.libraries)
        ) {
            throw new TypeError("The Biblio Library response is invalid.");
        }

        const resolution = resolveLibraryContext(payload.libraries, routeState);

        if (resolution.state === "selected" && resolution.canonicalize) {
            routes.replace({
                ...routeState,
                libraryId: resolution.library.library_id,
            });
        }

        return resolution;
    }

    function isCurrent(runGeneration, controller) {
        return generation === runGeneration
            && currentController === controller
            && !controller.signal.aborted;
    }

    function setIdle(promise) {
        idlePromise = promise;
        return promise;
    }

    function beginNavigation() {
        return setIdle(navigate());
    }

    function beginHistoryNavigation() {
        return setIdle(navigate({ focusHeading: true }));
    }

    function openOverview(libraryId) {
        routes.push({ libraryId, itemId: null });
        return beginNavigation();
    }

    function openDetail(libraryId, itemId) {
        routes.push({ libraryId, itemId });
        return beginNavigation();
    }

    function renderResolution(resolution, render, consumeHeadingFocus) {
        if (resolution.state === "empty") {
            render({
                state: "zero-libraries",
                ...consumeHeadingFocus(),
            });
            return true;
        }

        if (resolution.state === "unavailable") {
            render({
                state: "library-unavailable",
                ...consumeHeadingFocus(),
            });
            return true;
        }

        if (resolution.state === "chooser") {
            resolution.libraries.forEach(assertLibraryPresentation);
            render(
                {
                    state: "library-chooser",
                    libraries: resolution.libraries,
                    ...consumeHeadingFocus(),
                },
                {
                    selectLibrary(libraryId) {
                        routes.push({ libraryId, itemId: null });
                        return beginNavigation();
                    },
                }
            );
            return true;
        }

        return false;
    }

    async function navigate({ focusHeading = false } = {}) {
        const runGeneration = generation + 1;
        generation = runGeneration;
        startReadingView?.destroy();
        endReadingView?.destroy();
        mutationController?.abort();
        mutationController = null;
        currentController?.abort();
        const controller = abortControllerFactory();
        currentController = controller;
        const render = currentView().render;
        let operation = "library";
        let detailBackUrl = null;
        let selectedLibraryId = null;
        let headingFocusPending = focusHeading;

        function consumeHeadingFocus() {
            if (!headingFocusPending) {
                return {};
            }

            headingFocusPending = false;
            return { focusHeading: true };
        }

        render({ state: "library-loading" });

        try {
            const resolution = await loadLibraryContext({
                signal: controller.signal,
            });

            if (
                resolution.state === "aborted"
                || !isCurrent(runGeneration, controller)
            ) {
                return;
            }

            if (renderResolution(resolution, render, consumeHeadingFocus)) {
                return;
            }

            assertLibraryPresentation(resolution.library);
            selectedLibraryId = resolution.library.library_id;
            const routeState = routes.read();

            if (routeState.itemId !== null) {
                operation = "detail";
                detailBackUrl = buildRouteUrl(config.overviewUrl, {
                    libraryId: selectedLibraryId,
                    itemId: null,
                });
                const requestedItemId = routeState.itemId;
                const resourcePath = detailPath(
                    selectedLibraryId,
                    requestedItemId
                );
                const renderDetail = currentDetailView().render;
                let currentDetail = null;
                let historyRevision = 0;
                let historyPagePending = false;
                let historyFirstOperation = Promise.resolve();
                let readingHistory = { state: "loading" };

                const readingHistoryActions = {
                    loadMore() {
                        return setIdle(loadMoreHistory());
                    },
                    retryLoadMore() {
                        return setIdle(loadMoreHistory());
                    },
                    retry() {
                        return setIdle(queueHistoryFirstPage({
                            preserveExisting: readingHistory.state === "ready",
                        }));
                    },
                    reload,
                    loginUrl: config.loginUrl,
                };

                function renderReadingHistory() {
                    if (
                        currentDetail === null
                        || !isCurrent(runGeneration, controller)
                    ) {
                        return;
                    }

                    currentReadingHistoryView().render(
                        readingHistory,
                        readingHistoryActions
                    );

                    if (readingHistory.state === "ready") {
                        readingHistory.focusAfterPagination = false;
                        readingHistory.focusAfterPaginationError = false;
                        readingHistory.addedCount = 0;
                    }
                }

                function historyReady(page, overrides = {}) {
                    return {
                        state: "ready",
                        items: [...page.items],
                        nextCursor: page.nextCursor,
                        refreshing: false,
                        refreshError: false,
                        refreshRecovery: "retry",
                        loadingMore: false,
                        loadMoreError: false,
                        paginationRecovery: "retry",
                        focusAfterPagination: false,
                        focusAfterPaginationError: false,
                        addedCount: 0,
                        ...overrides,
                    };
                }

                async function loadHistoryFirstPage({
                    preserveExisting = false,
                } = {}) {
                    const requestRevision = historyRevision + 1;
                    historyRevision = requestRevision;
                    const preserved = preserveExisting
                        && readingHistory.state === "ready"
                        ? readingHistory
                        : null;

                    readingHistory = preserved === null
                        ? { state: "loading" }
                        : {
                            ...preserved,
                            refreshing: true,
                            refreshError: false,
                            loadingMore: false,
                            loadMoreError: false,
                            focusAfterPagination: false,
                            focusAfterPaginationError: false,
                            addedCount: 0,
                        };
                    renderReadingHistory();

                    try {
                        const payload = await api.get(
                            readingHistoryPath(currentDetail.work_id),
                            { signal: controller.signal }
                        );

                        if (
                            requestRevision !== historyRevision
                            || !isCurrent(runGeneration, controller)
                        ) {
                            return false;
                        }

                        const page = readReadingHistoryPage(payload);
                        readingHistory = page.items.length === 0
                            ? { state: "empty" }
                            : historyReady(page);
                        renderReadingHistory();

                        return true;
                    } catch (error) {
                        if (
                            isAborted(error)
                            || requestRevision !== historyRevision
                            || !isCurrent(runGeneration, controller)
                        ) {
                            return false;
                        }

                        const recovery = readingHistoryRecovery(error);
                        readingHistory = preserved === null
                            ? {
                                state: "error",
                                message: "Leesgeschiedenis kon niet worden geladen.",
                                recovery,
                            }
                            : {
                                ...preserved,
                                refreshing: false,
                                refreshError: true,
                                refreshRecovery: recovery,
                                loadingMore: false,
                                loadMoreError: false,
                                focusAfterPagination: false,
                                focusAfterPaginationError: false,
                                addedCount: 0,
                            };
                        renderReadingHistory();

                        return false;
                    }
                }

                function queueHistoryFirstPage(options = {}) {
                    const queued = historyFirstOperation.then(() => (
                        loadHistoryFirstPage(options)
                    ));
                    historyFirstOperation = queued.catch(() => false);

                    return queued;
                }

                async function loadMoreHistory() {
                    if (
                        historyPagePending
                        || readingHistory.state !== "ready"
                        || readingHistory.nextCursor === null
                        || !isCurrent(runGeneration, controller)
                    ) {
                        return false;
                    }

                    const requestRevision = historyRevision;
                    const requestedCursor = readingHistory.nextCursor;
                    historyPagePending = true;
                    readingHistory = {
                        ...readingHistory,
                        loadingMore: true,
                        loadMoreError: false,
                        focusAfterPagination: false,
                        focusAfterPaginationError: false,
                        addedCount: 0,
                    };
                    renderReadingHistory();

                    try {
                        const payload = await api.get(
                            readingHistoryPath(
                                currentDetail.work_id,
                                requestedCursor
                            ),
                            { signal: controller.signal }
                        );

                        if (
                            requestRevision !== historyRevision
                            || !isCurrent(runGeneration, controller)
                        ) {
                            return false;
                        }

                        const page = readReadingHistoryPage(payload);
                        readingHistory = {
                            ...readingHistory,
                            items: [...readingHistory.items, ...page.items],
                            nextCursor: page.nextCursor,
                            loadingMore: false,
                            loadMoreError: false,
                            focusAfterPagination: true,
                            focusAfterPaginationError: false,
                            addedCount: page.items.length,
                        };
                        renderReadingHistory();

                        return true;
                    } catch (error) {
                        if (
                            isAborted(error)
                            || requestRevision !== historyRevision
                            || !isCurrent(runGeneration, controller)
                        ) {
                            return false;
                        }

                        readingHistory = {
                            ...readingHistory,
                            loadingMore: false,
                            loadMoreError: true,
                            paginationRecovery: readingHistoryRecovery(error),
                            focusAfterPagination: false,
                            focusAfterPaginationError: true,
                            addedCount: 0,
                        };
                        renderReadingHistory();

                        return false;
                    } finally {
                        historyPagePending = false;
                    }
                }

                function renderCurrentDetail({
                    notice = null,
                    focusReading = false,
                } = {}) {
                    if (
                        currentDetail === null
                        || !isCurrent(runGeneration, controller)
                    ) {
                        return;
                    }

                    renderDetail(
                        {
                            state: "detail",
                            detail: currentDetail,
                            backUrl: detailBackUrl,
                            notice,
                            focusReading,
                            ...consumeHeadingFocus(),
                        },
                        detailActions
                    );
                    renderReadingHistory();
                }

                async function reconcileDetail({
                    acknowledge = () => {},
                    notice,
                    refreshedNotice,
                    refreshFailure,
                    refreshHistory = false,
                }) {
                    acknowledge(notice);

                    try {
                        const refreshedPayload = await api.get(resourcePath, {
                            signal: controller.signal,
                        });

                        if (!isCurrent(runGeneration, controller)) {
                            return Object.freeze({ state: "aborted" });
                        }

                        currentDetail = readDetail(
                            refreshedPayload,
                            selectedLibraryId,
                            requestedItemId
                        );
                        renderCurrentDetail({
                            notice: refreshedNotice,
                            focusReading: true,
                        });

                        if (refreshHistory) {
                            await queueHistoryFirstPage({
                                preserveExisting: true,
                            });
                        }

                        return Object.freeze({ state: "reconciled" });
                    } catch (error) {
                        if (
                            isAborted(error)
                            || !isCurrent(runGeneration, controller)
                        ) {
                            return Object.freeze({ state: "aborted" });
                        }

                        if (isUnavailable(error)) {
                            renderDetail(
                                {
                                    state: "item-unavailable",
                                    backUrl: detailBackUrl,
                                    focusHeading: true,
                                    ...consumeHeadingFocus(),
                                },
                                detailActions
                            );
                            return Object.freeze({ state: "reconciled" });
                        }

                        return Object.freeze({
                            state: "refresh-failed",
                            message: refreshFailure,
                        });
                    }
                }

                async function startReading(startedOn, lifecycle) {
                    if (
                        mutationController !== null
                        || !isCurrent(runGeneration, controller)
                    ) {
                        return Object.freeze({ state: "aborted" });
                    }

                    const activeMutation = abortControllerFactory();
                    mutationController = activeMutation;

                    try {
                        let acknowledgement;

                        try {
                            acknowledgement = await api.post(
                                startReadingPath(
                                    selectedLibraryId,
                                    requestedItemId
                                ),
                                { started_on: startedOn },
                                { signal: activeMutation.signal }
                            );
                        } catch (error) {
                            if (
                                isAborted(error)
                                || !isCurrent(runGeneration, controller)
                            ) {
                                return Object.freeze({ state: "aborted" });
                            }

                            if (
                                error instanceof BiblioApiError
                                && error.kind === "invalid_response"
                                && Number.isInteger(error.status)
                                && error.status >= 200
                                && error.status < 300
                            ) {
                                return reconcileDetail({
                                    acknowledge: lifecycle.acknowledge,
                                    notice: "Leesstatus bijwerken.",
                                    refreshedNotice: "De leesstatus is bijgewerkt.",
                                    refreshFailure: "De aanvraag is verwerkt, maar de actuele pagina kon niet worden vernieuwd.",
                                });
                            }

                            const outcome = startReadingErrorOutcome(error);

                            if (outcome.state !== "reconcile") {
                                return outcome;
                            }

                            return reconcileDetail({
                                acknowledge: lifecycle.acknowledge,
                                notice: outcome.notice,
                                refreshedNotice: outcome.refreshedNotice,
                                refreshFailure: "De actuele pagina kon niet worden vernieuwd.",
                            });
                        }

                        if (!isCurrent(runGeneration, controller)) {
                            return Object.freeze({ state: "aborted" });
                        }

                        try {
                            assertStartedRound(
                                acknowledgement,
                                requestedItemId,
                                startedOn
                            );
                        } catch {
                            return reconcileDetail({
                                acknowledge: lifecycle.acknowledge,
                                notice: "Leesstatus bijwerken.",
                                refreshedNotice: "De leesstatus is bijgewerkt.",
                                refreshFailure: "De aanvraag is verwerkt, maar de actuele pagina kon niet worden vernieuwd.",
                            });
                        }

                        return reconcileDetail({
                            acknowledge: lifecycle.acknowledge,
                            notice: "Lezen is gestart. Leesstatus bijwerken.",
                            refreshedNotice: "Lezen is gestart.",
                            refreshFailure: "Lezen is gestart, maar de actuele pagina kon niet worden vernieuwd.",
                        });
                    } finally {
                        if (mutationController === activeMutation) {
                            mutationController = null;
                        }
                    }
                }

                async function endReadingMutation(rawIntent) {
                    let intent;

                    try {
                        intent = readEndReadingIntent(rawIntent);
                    } catch {
                        return Object.freeze({ state: "validation-error" });
                    }

                    if (
                        mutationController !== null
                        || !isCurrent(runGeneration, controller)
                    ) {
                        return Object.freeze({ state: "pending" });
                    }

                    const activeRound = currentDetail?.active_reading_round;

                    if (
                        activeRound === null
                        || activeRound === undefined
                        || currentDetail.capabilities.end_reading !== true
                    ) {
                        return Object.freeze({ state: "unavailable" });
                    }

                    const readingRoundId = activeRound.reading_round_id;
                    const expectedVersion = activeRound.version;
                    const activeMutation = abortControllerFactory();
                    mutationController = activeMutation;

                    try {
                        let acknowledgement;

                        try {
                            acknowledgement = await api.post(
                                endReadingPath(readingRoundId),
                                {
                                    outcome: intent.outcome,
                                    finished_on: intent.finishedOn,
                                    expected_version: expectedVersion,
                                },
                                { signal: activeMutation.signal }
                            );
                        } catch (error) {
                            if (
                                isAborted(error)
                                || !isCurrent(runGeneration, controller)
                            ) {
                                return Object.freeze({
                                    state: "outcome-unknown",
                                });
                            }

                            if (
                                error instanceof BiblioApiError
                                && error.kind === "invalid_response"
                                && Number.isInteger(error.status)
                                && error.status >= 200
                                && error.status < 300
                            ) {
                                return reconcileDetail({
                                    notice: "Leesstatus bijwerken.",
                                    refreshedNotice: "De leesstatus is bijgewerkt.",
                                    refreshFailure: "De aanvraag is verwerkt, maar de actuele pagina kon niet worden vernieuwd.",
                                    refreshHistory: true,
                                });
                            }

                            const outcome = endReadingErrorOutcome(error);

                            if (outcome.state !== "reconcile") {
                                return outcome;
                            }

                            return reconcileDetail({
                                notice: outcome.notice,
                                refreshedNotice: outcome.refreshedNotice,
                                refreshFailure: "De actuele pagina kon niet worden vernieuwd.",
                                refreshHistory: true,
                            });
                        }

                        if (!isCurrent(runGeneration, controller)) {
                            return Object.freeze({ state: "outcome-unknown" });
                        }

                        try {
                            assertEndedRound(
                                acknowledgement,
                                readingRoundId,
                                intent
                            );
                        } catch {
                            return reconcileDetail({
                                notice: "Leesstatus bijwerken.",
                                refreshedNotice: "De leesstatus is bijgewerkt.",
                                refreshFailure: "De aanvraag is verwerkt, maar de actuele pagina kon niet worden vernieuwd.",
                                refreshHistory: true,
                            });
                        }

                        return reconcileDetail({
                            notice: "Leesstatus bijwerken.",
                            refreshedNotice: "De leesstatus is bijgewerkt.",
                            refreshFailure: "De aanvraag is verwerkt, maar de actuele pagina kon niet worden vernieuwd.",
                            refreshHistory: true,
                        });
                    } finally {
                        if (mutationController === activeMutation) {
                            mutationController = null;
                        }
                    }
                }

                const detailActions = {
                    backToOverview() {
                        return openOverview(selectedLibraryId);
                    },
                    startReading(opener) {
                        return currentStartReadingView().open({
                            opener,
                            submit(startedOn, lifecycle) {
                                return setIdle(startReading(
                                    startedOn,
                                    lifecycle
                                ));
                            },
                        });
                    },
                    endReading(opener) {
                        return currentEndReadingView().open({
                            opener,
                            submit(intent) {
                                return setIdle(endReadingMutation(intent));
                            },
                        });
                    },
                };

                if (requestedItemId.length === 0) {
                    renderDetail(
                        {
                            state: "item-unavailable",
                            backUrl: detailBackUrl,
                            ...consumeHeadingFocus(),
                        },
                        detailActions
                    );
                    return;
                }

                renderDetail({ state: "detail-loading" });
                const payload = await api.get(
                    resourcePath,
                    { signal: controller.signal }
                );

                if (!isCurrent(runGeneration, controller)) {
                    return;
                }

                currentDetail = readDetail(
                    payload,
                    selectedLibraryId,
                    requestedItemId
                );
                renderCurrentDetail();
                await queueHistoryFirstPage();
                return;
            }

            operation = "overview";
            render({
                state: "overview-loading",
                library: resolution.library,
            });

            const payload = await api.get(
                overviewPath(resolution.library.library_id),
                { signal: controller.signal }
            );

            if (!isCurrent(runGeneration, controller)) {
                return;
            }

            const firstPage = readOverview(
                payload,
                resolution.library.library_id
            );
            const overview = {
                library: firstPage.library,
                items: [...firstPage.items],
                nextCursor: firstPage.nextCursor,
                loadingMore: false,
                loadMoreError: false,
                canRetryCursor: false,
            };

            function renderOverview() {
                if (!isCurrent(runGeneration, controller)) {
                    return;
                }

                render(
                    {
                        state: "overview",
                        ...overview,
                        ...consumeHeadingFocus(),
                    },
                    {
                        loadMore() {
                            return setIdle(loadMore(false));
                        },
                        retryLoadMore() {
                            return setIdle(loadMore(true));
                        },
                        restart: beginNavigation,
                        openItem(itemId) {
                            return openDetail(
                                resolution.library.library_id,
                                itemId
                            );
                        },
                    }
                );
            }

            async function loadMore(retrying) {
                if (
                    overview.loadingMore
                    || overview.nextCursor === null
                    || !isCurrent(runGeneration, controller)
                ) {
                    return;
                }

                const requestedCursor = overview.nextCursor;
                overview.loadingMore = true;
                overview.loadMoreError = false;
                overview.canRetryCursor = false;
                renderOverview();

                try {
                    const nextPayload = await api.get(
                        overviewPath(
                            resolution.library.library_id,
                            requestedCursor
                        ),
                        { signal: controller.signal }
                    );

                    if (!isCurrent(runGeneration, controller)) {
                        return;
                    }

                    const nextPage = readOverview(
                        nextPayload,
                        resolution.library.library_id
                    );
                    overview.library = nextPage.library;
                    overview.items.push(...nextPage.items);
                    overview.nextCursor = nextPage.nextCursor;
                    overview.loadingMore = false;
                    renderOverview();
                } catch (error) {
                    if (isAborted(error) || !isCurrent(runGeneration, controller)) {
                        return;
                    }

                    overview.loadingMore = false;
                    overview.loadMoreError = true;
                    overview.canRetryCursor = retrying === false;
                    renderOverview();
                }
            }

            renderOverview();
        } catch (error) {
            if (isAborted(error) || !isCurrent(runGeneration, controller)) {
                return;
            }

            if (isUnavailable(error)) {
                if (
                    operation === "detail"
                    && detailBackUrl !== null
                    && selectedLibraryId !== null
                ) {
                    currentDetailView().render(
                        {
                            state: "item-unavailable",
                            backUrl: detailBackUrl,
                            ...consumeHeadingFocus(),
                        },
                        {
                            backToOverview() {
                                return openOverview(selectedLibraryId);
                            },
                        }
                    );
                    return;
                }

                render({
                    state: "library-unavailable",
                    ...consumeHeadingFocus(),
                });
                return;
            }

            render(
                {
                    state: "request-error",
                    ...consumeHeadingFocus(),
                },
                { retry: beginNavigation }
            );
        }
    }

    function start() {
        if (started) {
            return idlePromise;
        }

        unsubscribePopState = routes.onPopState(beginHistoryNavigation);
        started = true;

        return beginNavigation();
    }

    function destroy() {
        generation += 1;
        startReadingView?.destroy();
        endReadingView?.destroy();
        mutationController?.abort();
        mutationController = null;
        currentController?.abort();
        currentController = null;
        unsubscribePopState?.();
        unsubscribePopState = null;
        started = false;
    }

    return Object.freeze({
        config,
        routes,
        loadLibraryContext,
        start,
        destroy,
        whenIdle() {
            return idlePromise;
        },
    });
}

export function bootstrapLibraryApps({
    documentImpl = globalThis.document,
    appFactory = createLibraryApp,
    appOptions = {},
} = {}) {
    if (typeof documentImpl?.querySelectorAll !== "function") {
        throw new TypeError("A browser Document implementation is required.");
    }

    const apps = [];

    for (const mount of documentImpl.querySelectorAll("[data-biblio-ui-root]")) {
        let app = mountedApps.get(mount);

        if (app === undefined) {
            app = appFactory(mount, { ...appOptions, documentImpl });
            mountedApps.set(mount, app);
            void app.start();
        }

        apps.push(app);
    }

    return Object.freeze(apps);
}

if (typeof document !== "undefined") {
    bootstrapLibraryApps();
}
