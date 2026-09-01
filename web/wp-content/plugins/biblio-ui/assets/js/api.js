const CLIENT_ERROR = Object.freeze({
    ABORTED: "biblio_ui_request_aborted",
    INVALID_RESPONSE: "biblio_ui_invalid_response",
    NETWORK: "biblio_ui_network_error",
});

const ERROR_KIND = Object.freeze({
    ABORTED: "aborted",
    HTTP: "http",
    INVALID_RESPONSE: "invalid_response",
    NETWORK: "network",
});

export class BiblioApiError extends Error {
    constructor({ kind, code, status, message }) {
        super(message);
        this.name = "BiblioApiError";
        this.kind = kind;
        this.code = code;
        this.status = status;
    }
}

function clientError(kind, code, status, message) {
    return new BiblioApiError({ kind, code, status, message });
}

function invalidResponse(status) {
    return clientError(
        ERROR_KIND.INVALID_RESPONSE,
        CLIENT_ERROR.INVALID_RESPONSE,
        status,
        "Biblio returned an invalid response."
    );
}

function normalizeRestRoot(restRoot) {
    if (typeof restRoot !== "string" || restRoot.length === 0) {
        throw new TypeError("A Biblio REST root is required.");
    }

    const normalized = new URL(restRoot);
    normalized.hash = "";
    normalized.search = "";

    if (!normalized.pathname.endsWith("/")) {
        normalized.pathname += "/";
    }

    return normalized;
}

function requestUrl(restRoot, path) {
    if (typeof path !== "string" || path.length === 0) {
        throw new TypeError("A relative Biblio REST path is required.");
    }

    const normalizedPath = path.replace(/^\/+/, "");
    const url = new URL(normalizedPath, restRoot);

    if (
        url.origin !== restRoot.origin
        || !url.pathname.startsWith(restRoot.pathname)
    ) {
        throw new TypeError("The request path must stay within the Biblio REST root.");
    }

    return url.toString();
}

function isRecord(value) {
    return value !== null && typeof value === "object" && !Array.isArray(value);
}

function isWordPressRestError(payload, responseStatus) {
    return isRecord(payload)
        && typeof payload.code === "string"
        && payload.code.length > 0
        && typeof payload.message === "string"
        && isRecord(payload.data)
        && Number.isInteger(payload.data.status)
        && payload.data.status === responseStatus;
}

function transportFailure(error, signal) {
    if (signal?.aborted || error?.name === "AbortError") {
        return clientError(
            ERROR_KIND.ABORTED,
            CLIENT_ERROR.ABORTED,
            null,
            "The Biblio request was aborted."
        );
    }

    return clientError(
        ERROR_KIND.NETWORK,
        CLIENT_ERROR.NETWORK,
        null,
        "Biblio could not be reached."
    );
}

async function parseResponse(response, signal) {
    let responseText;

    try {
        responseText = await response.text();
    } catch (error) {
        throw transportFailure(error, signal);
    }

    if (responseText.trim() === "") {
        if (response.ok) {
            return null;
        }

        throw invalidResponse(response.status);
    }

    let payload;

    try {
        payload = JSON.parse(responseText);
    } catch {
        throw invalidResponse(response.status);
    }

    if (response.ok) {
        if (!isRecord(payload) || !Object.hasOwn(payload, "data")) {
            throw invalidResponse(response.status);
        }

        return payload.data;
    }

    if (!isWordPressRestError(payload, response.status)) {
        throw invalidResponse(response.status);
    }

    throw clientError(
        ERROR_KIND.HTTP,
        payload.code,
        response.status,
        payload.message
    );
}

export function createBiblioApi({ restRoot, restNonce, fetchImpl = fetch }) {
    const normalizedRoot = normalizeRestRoot(restRoot);

    if (typeof restNonce !== "string" || restNonce.length === 0) {
        throw new TypeError("A WordPress REST nonce is required.");
    }

    if (typeof fetchImpl !== "function") {
        throw new TypeError("A fetch implementation is required.");
    }

    async function request(path, { method = "GET", body, signal } = {}) {
        const normalizedMethod = method.toUpperCase();

        if (!["GET", "POST", "PATCH", "DELETE"].includes(normalizedMethod)) {
            throw new TypeError("The Biblio UI transport method is unsupported.");
        }

        if (normalizedMethod === "GET" && body !== undefined) {
            throw new TypeError("GET requests cannot contain a JSON body.");
        }

        if (signal?.aborted) {
            throw transportFailure(null, signal);
        }

        const headers = {
            Accept: "application/json",
            "X-WP-Nonce": restNonce,
        };
        const options = {
            method: normalizedMethod,
            credentials: "same-origin",
            headers,
        };

        if (signal !== undefined) {
            options.signal = signal;
        }

        if (["POST", "PATCH", "DELETE"].includes(normalizedMethod)) {
            headers["Content-Type"] = "application/json";

            if (body !== undefined) {
                options.body = JSON.stringify(body);
            }
        }

        const url = requestUrl(normalizedRoot, path);
        let response;

        try {
            response = await fetchImpl(url, options);
        } catch (error) {
            throw transportFailure(error, signal);
        }

        return parseResponse(response, signal);
    }

    return Object.freeze({
        request,
        get(path, options = {}) {
            return request(path, { ...options, method: "GET" });
        },
        post(path, body, options = {}) {
            return request(path, { ...options, method: "POST", body });
        },
        patch(path, body, options = {}) {
            return request(path, { ...options, method: "PATCH", body });
        },
        delete(path, body, options = {}) {
            return request(path, { ...options, method: "DELETE", body });
        },
    });
}
