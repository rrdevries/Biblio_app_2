import { createBiblioApi } from "biblio-ui/api";
import { createLibraryShell } from "biblio-ui/ui-shell";
import { createSettingControl, readSettings } from "./settings-state.js";

function node(doc, tag, text = "", className = "") {
    const result = doc.createElement(tag);
    result.textContent = text;
    result.className = className;
    return result;
}
function button(doc, text, action) {
    const result = node(doc, "button", text, "biblio-ui__settings-button");
    result.type = "button";
    result.addEventListener("click", action);
    return result;
}
const sourceLabel = { personal: "Eigen keuze", library: "Bibliotheekstandaard", biblio: "Biblio-standaard" };
const viewLabel = value => value === "list" ? "Lijst" : "Kaarten";

export function createSettingsApp(root, {
    api = createBiblioApi({ restRoot: root.dataset.restRoot, restNonce: root.dataset.restNonce }),
    documentImpl = globalThis.document,
    locationImpl = globalThis.location,
    shellFactory = createLibraryShell,
} = {}) {
    const doc = documentImpl;
    const config = root.dataset;
    const id = new URL(locationImpl.href).searchParams.get("library_id");
    const mode = config.settingsMode ?? "preferences";
    const management = mode === "defaults";
    const title = management ? "Bibliotheekinstellingen" : "Mijn voorkeuren";
    const shell = shellFactory(root, { documentImpl: doc, ...config, activeDestination: management ? "library-settings" : "settings" });
    const host = shell.contentRoot;
    let destroyed = false;
    const path = shared => `libraries/${encodeURIComponent(id)}/${shared ? "defaults" : "preferences"}`;
    async function get(shared = false) { return readSettings(await api.get(path(shared)), id, shared); }
    function notice(message, canRetry = false, needsLogin = false) {
        shell.setLibraryContext(null);
        const section = node(doc, "section", "", "biblio-ui__view biblio-ui__entry");
        section.append(node(doc, "h1", title, "biblio-ui__page-title"), node(doc, "p", message));
        if (canRetry) section.append(button(doc, "Opnieuw proberen", load));
        const link = node(doc, "a", needsLogin ? "Inloggen" : "Terug naar Mijn Biblio", "biblio-ui__settings-button");
        link.href = needsLogin ? config.loginUrl : config.platformUrl;
        section.append(link);
        host.replaceChildren(section);
    }
    function control(section, key, initial, shared = false) {
        const archive = key === "catalog_archive_visible";
        const group = node(doc, "fieldset", "", "biblio-ui__setting");
        group.append(node(doc, "legend", archive ? "Archief tonen" : "Standaardweergave", "biblio-ui__setting-title"));
        const explanation = node(doc, "p", shared
            ? "Deze standaard geldt voor gebruikers die geen eigen weergave hebben gekozen. Hun persoonlijke keuzes blijven behouden."
            : archive ? "Toon bij het openen van de Catalogus ook gearchiveerde items." : "Kies hoe je de Catalogus in deze bibliotheek normaal opent.");
        const inputs = node(doc, "div", "", "biblio-ui__setting-choices");
        const confirmed = node(doc, "p", "", "biblio-ui__setting-source");
        const feedback = node(doc, "p", "", "biblio-ui__setting-feedback");
        feedback.setAttribute("role", "status"); feedback.setAttribute("aria-live", "polite");
        const retry = button(doc, "Opnieuw proberen", () => controller.retry());
        const reset = button(doc, "Biblio-standaard gebruiken", () => controller.choose(null));
        const options = [];
        let pendingFocus;
        function draw(snapshot) {
            if (destroyed) return;
            if (snapshot.status === "blocked") {
                notice("Je aanmelding of bibliotheektoegang is gewijzigd. Open de bibliotheek opnieuw.", false, [401,403].includes(snapshot.failure?.status));
                return;
            }
            const state = snapshot.confirmed;
            if (snapshot.status === "pending" && group.contains(doc.activeElement)) pendingFocus ??= doc.activeElement;
            group.disabled = ["pending", "blocked"].includes(snapshot.status);
            for (const option of options) {
                const selected = snapshot.status === "pending" && snapshot.intent !== undefined ? snapshot.intent : state.value;
                option.input.checked = archive ? (selected ?? state.effective) : selected === option.value;
                if (option.value === null) {
                    // The response includes the inherited value even while an override is active.
                    option.label.textContent = shared ? "Biblio-standaard gebruiken (Kaarten)" : `${state.default_source === "library" ? "Bibliotheekstandaard" : "Biblio-standaard"} gebruiken (${viewLabel(state.default_effective ?? "grid")})`;
                }
            }
            confirmed.textContent = `Laatst bevestigd: ${archive ? (state.effective ? "Aan" : "Uit") : viewLabel(state.effective)} · ${sourceLabel[state.source]}`;
            reset.hidden = !archive || state.value === null;
            retry.hidden = !["error", "conflict"].includes(snapshot.status);
            retry.textContent = snapshot.status === "conflict" ? "Actuele waarde ophalen" : "Opnieuw proberen";
            if (snapshot.status !== "pending" && pendingFocus) {
                const oldFocus = pendingFocus;
                pendingFocus = undefined;
                if (!group.disabled && (!doc.activeElement || doc.activeElement === doc.body || group.contains(doc.activeElement))) {
                    const target = oldFocus.hidden ? options.find(option => option.input.checked)?.input ?? options[0].input : oldFocus;
                    target.focus();
                }
            }
            feedback.textContent = ({ pending: "Opslaan…", saved: "Opgeslagen", error: "Opslag niet bevestigd. Controleer de actuele waarde met Opnieuw proberen.", conflict: "Deze instelling is intussen gewijzigd. Haal de actuele waarde op en kies opnieuw.", blocked: "Je aanmelding of toegang is gewijzigd. Open de pagina opnieuw of log opnieuw in." })[snapshot.status] ?? "";
        }
        const controller = createSettingControl({ initial,
            read: async () => {
                const payload = await get(shared);
                return payload[shared ? "defaults" : "preferences"][key];
            },
            write: async (value, version) => {
                const payload = readSettings(await api.patch(path(shared), {
                    setting: key, operation: value === null ? "reset" : "set",
                    ...(value === null ? {} : {value}), expected_version: version,
                }), id, shared);
                return payload[shared ? "defaults" : "preferences"][key];
            }, changed: draw,
        });
        if (archive) {
            const label = node(doc, "label", "", "biblio-ui__setting-choice");
            const input = doc.createElement("input"); input.type = "checkbox"; input.setAttribute("role", "switch");
            input.addEventListener("change", () => { void controller.choose(input.checked); });
            label.append(input, node(doc, "span", "Archief tonen")); inputs.append(label); options.push({input,value:true});
        } else {
            for (const value of [null, "grid", "list"]) {
                const label = node(doc, "label", "", "biblio-ui__setting-choice");
                const input = doc.createElement("input"); input.type = "radio"; input.name = `${shared ? "shared" : "personal"}-${key}`;
                const text = node(doc, "span", value === null ? "" : viewLabel(value));
                input.addEventListener("change", () => { if (input.checked) {void controller.choose(value);} });
                label.append(input, text); inputs.append(label); options.push({input,value,label:text});
            }
        }
        group.append(explanation, inputs, confirmed, reset, feedback, retry); section.append(group);
        draw(controller.snapshot());
    }
    async function load() {
        notice("Instellingen laden…");
        if (!["preferences", "defaults"].includes(mode)) { notice("Deze instellingenpagina is niet beschikbaar."); return; }
        if (config.accountState !== "authenticated") { notice(management ? "Log in om de Bibliotheekinstellingen te bekijken." : "Log in om je voorkeuren te bekijken.", false, true); return; }
        if (!id || id.trim() !== id || id.length > 191) { notice("Open Instellingen vanuit een specifieke bibliotheek."); return; }
        try {
            const payload = await get(management);
            if (management && payload.capabilities.manage_defaults !== true) {
                notice("Je hebt geen toegang tot deze Bibliotheekinstellingen."); return;
            }
            if (destroyed) return;
            shell.setLibraryContext({...payload.library, capabilities:payload.capabilities});
            const section = node(doc, "section", "", "biblio-ui__view biblio-ui__settings");
            section.append(node(doc, "p", payload.library.name, "biblio-ui__eyebrow"), node(doc, "h1", title, "biblio-ui__page-title"));
            const content = node(doc, "section", "", "biblio-ui__settings-section");
            content.append(node(doc, "p", management
                ? `Gedeelde instellingen voor ${payload.library.name}.`
                : `Jouw persoonlijke keuzes voor ${payload.library.name}.`));
            if (management) {
                control(content, "catalog_view", payload.defaults.catalog_view, true);
            } else {
                control(content, "catalog_view", payload.preferences.catalog_view);
                control(content, "catalog_archive_visible", payload.preferences.catalog_archive_visible);
            }
            section.append(content);
            host.replaceChildren(section);
        } catch (error) {
            if (destroyed) return;
            notice([401,403].includes(error?.status) ? "Je aanmelding of toegang is niet meer geldig." : "Instellingen konden niet worden geladen.", ![401,403,404].includes(error?.status), [401,403].includes(error?.status));
        }
    }
    return {load, destroy() {destroyed = true; shell.destroy();}};
}

if (typeof document !== "undefined") {
    for (const root of document.querySelectorAll("[data-biblio-settings-root]")) void createSettingsApp(root).load();
}
