import { BiblioApiError, createBiblioApi } from "biblio-ui/api";
import { resolveLibraryContext } from "biblio-ui/library-state";
import { createOverviewView } from "biblio-ui/overview-view";
import {
    buildRouteUrl,
    createRouteController,
} from "biblio-ui/route-state";

export { BiblioApiError, createBiblioApi } from "biblio-ui/api";
export { resolveLibraryContext } from "biblio-ui/library-state";
export { createOverviewView } from "biblio-ui/overview-view";
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

function overviewPath(libraryId, cursor = null) {
    const path = `libraries/${encodeURIComponent(libraryId)}/items`;

    return cursor === null
        ? path
        : `${path}?cursor=${encodeURIComponent(cursor)}`;
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
    let currentController = null;
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

    function renderResolution(resolution, render) {
        if (resolution.state === "empty") {
            render({ state: "zero-libraries" });
            return true;
        }

        if (resolution.state === "unavailable") {
            render({ state: "library-unavailable" });
            return true;
        }

        if (resolution.state === "chooser") {
            resolution.libraries.forEach(assertLibraryPresentation);
            render(
                {
                    state: "library-chooser",
                    libraries: resolution.libraries,
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

    async function navigate() {
        const runGeneration = generation + 1;
        generation = runGeneration;
        currentController?.abort();
        const controller = abortControllerFactory();
        currentController = controller;
        const render = currentView().render;
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

            if (renderResolution(resolution, render)) {
                return;
            }

            assertLibraryPresentation(resolution.library);
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
                    { state: "overview", ...overview },
                    {
                        loadMore() {
                            return setIdle(loadMore(false));
                        },
                        retryLoadMore() {
                            return setIdle(loadMore(true));
                        },
                        restart: beginNavigation,
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
                render({ state: "library-unavailable" });
                return;
            }

            render(
                { state: "request-error" },
                { retry: beginNavigation }
            );
        }
    }

    function start() {
        if (started) {
            return idlePromise;
        }

        unsubscribePopState = routes.onPopState(beginNavigation);
        started = true;

        return beginNavigation();
    }

    function destroy() {
        generation += 1;
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
