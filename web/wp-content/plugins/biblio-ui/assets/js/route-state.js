function routeIdentifier(value, key) {
    if (value !== null && typeof value !== "string") {
        throw new TypeError(`${key} must be a string or null.`);
    }

    return value;
}

export function readRouteState(url) {
    const parsedUrl = new URL(url);

    return Object.freeze({
        libraryId: parsedUrl.searchParams.has("library_id")
            ? parsedUrl.searchParams.get("library_id")
            : null,
        itemId: parsedUrl.searchParams.has("item_id")
            ? parsedUrl.searchParams.get("item_id")
            : null,
    });
}

export function buildRouteUrl(overviewUrl, {
    libraryId = null,
    itemId = null,
} = {}) {
    const url = new URL(overviewUrl);
    const normalizedLibraryId = routeIdentifier(libraryId, "libraryId");
    const normalizedItemId = routeIdentifier(itemId, "itemId");

    url.search = "";
    url.hash = "";

    if (normalizedLibraryId !== null) {
        url.searchParams.set("library_id", normalizedLibraryId);
    }

    if (normalizedItemId !== null) {
        url.searchParams.set("item_id", normalizedItemId);
    }

    return url.toString();
}

export function createRouteController({
    overviewUrl,
    historyImpl,
    locationImpl,
    eventTarget,
}) {
    if (typeof overviewUrl !== "string" || overviewUrl.length === 0) {
        throw new TypeError("A canonical Biblio overview URL is required.");
    }

    if (
        typeof historyImpl?.pushState !== "function"
        || typeof historyImpl?.replaceState !== "function"
    ) {
        throw new TypeError("A browser History implementation is required.");
    }

    if (typeof locationImpl?.href !== "string") {
        throw new TypeError("A browser Location implementation is required.");
    }

    if (
        typeof eventTarget?.addEventListener !== "function"
        || typeof eventTarget?.removeEventListener !== "function"
    ) {
        throw new TypeError("A browser event target is required.");
    }

    function read() {
        return readRouteState(locationImpl.href);
    }

    function push(routeState) {
        const url = buildRouteUrl(overviewUrl, routeState);
        historyImpl.pushState(null, "", url);
        return url;
    }

    function replace(routeState) {
        const url = buildRouteUrl(overviewUrl, routeState);
        historyImpl.replaceState(null, "", url);
        return url;
    }

    function onPopState(listener) {
        if (typeof listener !== "function") {
            throw new TypeError("A popstate listener is required.");
        }

        const handlePopState = () => listener(read());
        eventTarget.addEventListener("popstate", handlePopState);

        return () => eventTarget.removeEventListener("popstate", handlePopState);
    }

    return Object.freeze({ read, push, replace, onPopState });
}
