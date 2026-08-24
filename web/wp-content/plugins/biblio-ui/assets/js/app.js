import { BiblioApiError, createBiblioApi } from "biblio-ui/api";
import { resolveLibraryContext } from "biblio-ui/library-state";
import { createRouteController } from "biblio-ui/route-state";

export { BiblioApiError, createBiblioApi } from "biblio-ui/api";
export { resolveLibraryContext } from "biblio-ui/library-state";
export {
    buildRouteUrl,
    createRouteController,
    readRouteState,
} from "biblio-ui/route-state";

function mountValue(mount, key) {
    const value = mount?.dataset?.[key];

    if (typeof value !== "string" || value.length === 0) {
        throw new TypeError(`The Biblio mount requires data-${key}.`);
    }

    return value;
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

    async function loadLibraryContext({ signal } = {}) {
        const routeState = routes.read();
        let payload;

        try {
            payload = await api.get("me/libraries", { signal });
        } catch (error) {
            if (error instanceof BiblioApiError && error.kind === "aborted") {
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

    return Object.freeze({
        config,
        routes,
        loadLibraryContext,
    });
}
