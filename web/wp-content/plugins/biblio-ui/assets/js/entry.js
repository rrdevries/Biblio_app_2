import { createBiblioApi } from "biblio-ui/api";
import { createLibraryShell } from "biblio-ui/ui-shell";

function element(documentImpl, tag, text, className = "") {
    const node = documentImpl.createElement(tag);
    node.textContent = text;
    node.className = className;
    return node;
}

function link(documentImpl, label, href) {
    const node = element(documentImpl, "a", label, "biblio-ui__entry-link");
    node.setAttribute("href", href);
    return node;
}

export function entryLibraryUrl(baseUrl, libraryId) {
    if (typeof libraryId !== "string" || libraryId.length === 0) {
        throw new TypeError("An explicit Library ID is required.");
    }
    const url = new URL(baseUrl);
    url.search = "";
    url.hash = "";
    url.searchParams.set("library_id", libraryId);
    return url.toString();
}

export function readEntryLibraries(payload) {
    if (payload === null || typeof payload !== "object" || !Array.isArray(payload.libraries)) {
        throw new TypeError("The accessible Libraries response is invalid.");
    }
    const ids = new Set();
    for (const library of payload.libraries) {
        if (library === null || typeof library !== "object"
            || typeof library.library_id !== "string" || library.library_id.length === 0
            || typeof library.name !== "string" || library.name.length === 0
            || library.status !== "active"
            || library.capabilities?.view_collection !== true
            || typeof library.capabilities.add_catalog_item !== "boolean") {
            throw new TypeError("An accessible Library has an invalid contract.");
        }
        if (ids.has(library.library_id)) {
            throw new TypeError("An accessible Library was repeated.");
        }
        ids.add(library.library_id);
    }
    return payload.libraries;
}

export function selectEntryLibrary(libraries, url) {
    const id = new URL(url).searchParams.get("library_id");
    if (id === null || id.length === 0) return null;
    return libraries.find((library) => library.library_id === id) ?? null;
}

function view(documentImpl, eyebrow, title) {
    const section = element(documentImpl, "section", "", "biblio-ui__view biblio-ui__entry");
    const header = element(documentImpl, "header", "", "biblio-ui__entry-header");
    header.append(
        element(documentImpl, "p", eyebrow, "biblio-ui__eyebrow"),
        element(documentImpl, "h1", title, "biblio-ui__page-title")
    );
    section.append(header);
    return section;
}

export function createEntryApp(root, {
    api = createBiblioApi({ restRoot: root.dataset.restRoot, restNonce: root.dataset.restNonce }),
    documentImpl = globalThis.document,
    locationImpl = globalThis.location,
    shellFactory = createLibraryShell,
} = {}) {
    const config = root.dataset;
    const isLibraryHome = config.entryMode === "library";
    const shell = shellFactory(root, {
        documentImpl,
        platformUrl: config.platformUrl,
        libraryHomeUrl: config.libraryHomeUrl,
        overviewUrl: config.overviewUrl,
        searchUrl: config.searchUrl,
        wishlistUrl: config.wishlistUrl,
        nextReadingUrl: config.nextReadingUrl,
        loginUrl: config.loginUrl,
        logoutUrl: config.logoutUrl,
        accountState: config.accountState,
        accountName: config.accountName,
        activeDestination: isLibraryHome ? "home" : "platform",
    });
    const host = shell.contentRoot;

    function show(message, title = isLibraryHome ? "Bibliotheek openen" : "Mijn Biblio", needsLogin = false) {
        const section = view(documentImpl, isLibraryHome ? "Bibliotheek" : "Persoonlijk", title);
        section.append(element(documentImpl, "p", message));
        if (config.accountState !== "authenticated" || needsLogin) {
            section.append(link(documentImpl, "Inloggen", config.loginUrl));
        } else if (isLibraryHome) {
            section.append(link(documentImpl, "Terug naar Mijn Biblio", config.platformUrl));
        }
        host.replaceChildren(section);
    }

    async function load() {
        if (config.accountState !== "authenticated") {
            show("Log in om je bibliotheken te bekijken.");
            return;
        }
        show("Je bibliotheken worden geladen.", isLibraryHome ? "Bibliotheek laden" : "Mijn Biblio");
        let libraries;
        try {
            libraries = readEntryLibraries(await api.get("me/libraries"));
        } catch (error) {
            if (error?.status === 401) {
                show("Je sessie is verlopen. Log opnieuw in.", undefined, true);
            } else {
                show("Je bibliotheken konden niet worden geladen. Ververs de pagina om het opnieuw te proberen.");
            }
            return;
        }

        if (isLibraryHome) {
            const chosen = selectEntryLibrary(libraries, locationImpl.href);
            if (chosen === null) {
                show("Deze bibliotheek bestaat niet of is niet meer toegankelijk.", "Bibliotheek niet beschikbaar");
                return;
            }
            let overview;
            try {
                overview = await api.get(`libraries/${encodeURIComponent(chosen.library_id)}/items?page_size=1`);
            } catch (error) {
                if (error?.status === 401) {
                    show("Je sessie is verlopen. Log opnieuw in.", undefined, true);
                    return;
                }
                const unavailable = error?.status === 403 || error?.status === 404;
                show(unavailable
                    ? "Deze bibliotheek bestaat niet of is niet meer toegankelijk."
                    : "De bibliotheek kon niet worden geladen. Ververs de pagina om het opnieuw te proberen.",
                unavailable ? "Bibliotheek niet beschikbaar" : "Bibliotheek kon niet worden geladen");
                return;
            }
            if (overview?.library?.library_id !== chosen.library_id || !Array.isArray(overview.items)) {
                show("De bibliotheek kon niet worden geladen. Ververs de pagina om het opnieuw te proberen.", "Bibliotheek kon niet worden geladen");
                return;
            }
            const library = readEntryLibraries({ libraries: [overview.library] })[0];
            shell.setLibraryContext(library);
            const section = view(documentImpl, "Bibliotheek · Home", library.name);
            section.append(element(documentImpl, "p", overview.items.length === 0
                ? "Deze bibliotheek heeft nog geen boeken. Begin in de Catalogus wanneer je een boek wilt toevoegen of bekijken."
                : "Kies wat je in deze bibliotheek wilt doen."));
            const actions = element(documentImpl, "div", "", "biblio-ui__entry-actions");
            const catalogUrl = entryLibraryUrl(config.overviewUrl, library.library_id);
            actions.append(
                link(documentImpl, "Catalogus bekijken", catalogUrl),
                link(documentImpl, "Zoeken in Biblio", config.searchUrl)
            );
            if (library.capabilities.add_catalog_item) {
                actions.append(link(documentImpl, "Naar Catalogus om een boek toe te voegen", catalogUrl));
            }
            actions.append(link(documentImpl, "Andere bibliotheek kiezen", config.platformUrl));
            section.append(actions);
            host.replaceChildren(section);
            return;
        }

        const section = view(documentImpl, "Persoonlijk", "Mijn Biblio");
        section.append(element(documentImpl, "p", "Kies een bibliotheek om haar Home en Catalogus te openen. Je persoonlijke lijsten blijven in Mijn Biblio beschikbaar."));
        section.append(element(documentImpl, "h2", "Mijn bibliotheken"));
        if (libraries.length === 0) {
            section.append(element(documentImpl, "p", "Je hebt nog geen toegankelijke bibliotheek."));
        } else {
            const list = element(documentImpl, "ul", "", "biblio-ui__entry-libraries");
            for (const library of libraries) {
                const item = element(documentImpl, "li", "");
                item.append(link(documentImpl, library.name, entryLibraryUrl(config.libraryHomeUrl, library.library_id)));
                list.append(item);
            }
            section.append(list);
        }
        section.append(element(documentImpl, "h2", "Persoonlijk"));
        const actions = element(documentImpl, "div", "", "biblio-ui__entry-actions");
        actions.append(
            link(documentImpl, "Verlanglijst", config.wishlistUrl),
            link(documentImpl, "Hierna lezen", config.nextReadingUrl)
        );
        section.append(actions);
        host.replaceChildren(section);
    }

    return Object.freeze({ load, destroy: () => shell.destroy() });
}

if (typeof document !== "undefined") {
    const bootstrap = () => {
        for (const root of document.querySelectorAll("[data-biblio-entry-root]")) {
            void createEntryApp(root).load();
        }
    };
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", bootstrap, { once: true });
    else bootstrap();
}
