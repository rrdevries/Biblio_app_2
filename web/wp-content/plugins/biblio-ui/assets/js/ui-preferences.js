const SIDEBAR_COLLAPSED_KEY = "biblio.ui.sidebar.collapsed";

function safeStorage(storage) {
    return storage !== null
        && typeof storage?.getItem === "function"
        && typeof storage?.setItem === "function"
        ? storage
        : null;
}

export function createUiPreferences({
    storage = globalThis.localStorage,
} = {}) {
    const availableStorage = safeStorage(storage);

    function sidebarCollapsed() {
        try {
            return availableStorage?.getItem(SIDEBAR_COLLAPSED_KEY) === "true";
        } catch {
            return false;
        }
    }

    function setSidebarCollapsed(collapsed) {
        try {
            availableStorage?.setItem(
                SIDEBAR_COLLAPSED_KEY,
                collapsed === true ? "true" : "false"
            );
        } catch {
            // Storage can be unavailable without making navigation unusable.
        }
    }

    return Object.freeze({ sidebarCollapsed, setSidebarCollapsed });
}

export { SIDEBAR_COLLAPSED_KEY };
