// A control owns one confirmed setting and one pending intent. Retries always
// reconcile the server first, including successful writes whose response was lost.
export function createSettingControl({ initial, read, write, changed = () => {} }) {
    let confirmed = initial;
    let intent;
    let status = "idle";
    let failure;
    function notify() { changed(snapshot()); }
    function snapshot() { return { confirmed, intent, status, failure }; }
    function stop(error) {
        failure = error;
        status = [401, 403, 404].includes(error?.status) ? "blocked" : error?.status === 409 ? "conflict" : "error";
        notify();
    }
    async function send() {
        try {
            confirmed = await write(intent, confirmed.version);
            intent = undefined;
            status = "saved";
            notify();
        } catch (error) { stop(error); }
    }
    return {
        snapshot,
        async choose(value) {
            if (status === "pending" || status === "blocked") return;
            // A new deliberate choice after an unknown outcome must also
            // reconcile before writing; it is not a shortcut around safe retry.
            if (["error", "conflict"].includes(status)) {
                status = "pending";
                notify();
                try { confirmed = await read(); } catch (error) { stop(error); return; }
            }
            intent = value;
            failure = undefined;
            status = "pending";
            notify();
            await send();
        },
        async retry() {
            if (!["error", "conflict"].includes(status)) return;
            const previousVersion = confirmed.version;
            const wasConflict = status === "conflict";
            status = "pending";
            notify();
            try {
                const current = await read();
                confirmed = current;
                // A conflict always needs a fresh deliberate choice.
                if (wasConflict) { intent = undefined; status = "idle"; notify(); return; }
                if (current.value === intent) {
                    intent = undefined;
                    status = "saved";
                    notify();
                } else if (current.version !== previousVersion) {
                    intent = undefined;
                    status = "conflict";
                    notify();
                } else {
                    await send();
                }
            } catch (error) { stop(error); }
        },
    };
}

export function readSettings(payload, libraryId, shared = false) {
    const settings = payload?.[shared ? "defaults" : "preferences"];
    if (payload?.library?.library_id !== libraryId || typeof payload.library.name !== "string"
        || typeof payload?.capabilities?.manage_defaults !== "boolean" || !settings) {
        throw new TypeError("Invalid scoped settings response.");
    }
    for (const key of shared ? ["catalog_view"] : ["catalog_view", "catalog_archive_visible"]) {
        const state = settings[key];
        const allowed = key === "catalog_view" ? ["grid", "list"] : [true, false];
        if (!state || !Number.isSafeInteger(state.version) || state.version < 0
            || !allowed.includes(state.effective) || !(state.value === null || allowed.includes(state.value))
            || !["personal", "library", "biblio"].includes(state.source)) {
            throw new TypeError("Invalid setting state.");
        }
    }
    return payload;
}

export function preferenceStamp(state) {
    return JSON.stringify([state.version, state.value, state.effective, state.source, state.default_version ?? null]);
}

export function createCatalogPresentationSession({ storage = globalThis.sessionStorage, scope, preference, setting }) {
    const key = `biblio.catalog.presentation.${encodeURIComponent(scope)}.${setting}`;
    const stamp = preferenceStamp(preference);
    return {
        read() {
            try {
                const state = JSON.parse(storage?.getItem(key) ?? "null");
                const allowed = setting === "view" ? ["grid", "list"] : [true, false];
                return state?.stamp === stamp && allowed.includes(state.value) ? state.value : preference.effective;
            } catch { return preference.effective; }
        },
        write(value) {
            try { storage?.setItem(key, JSON.stringify({ value, stamp })); } catch { /* Optional temporary state. */ }
        },
    };
}
