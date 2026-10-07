import {clearSearchReturns} from "./search-return.js";
import { createUiPreferences } from "./ui-preferences.js";

function element(documentImpl, tagName, {
    className,
    text,
    attributes = {},
} = {}) {
    const node = documentImpl.createElement(tagName);

    if (className !== undefined) {
        node.className = className;
    }

    if (text !== undefined) {
        node.textContent = text;
    }

    for (const [name, value] of Object.entries(attributes)) {
        node.setAttribute(name, value);
    }

    return node;
}

function icon(documentImpl, name, className = "") {
    return element(documentImpl, "span", {
        className: `biblio-ui__icon ${className}`.trim(),
        attributes: {
            "aria-hidden": "true",
            "data-biblio-icon": name,
        },
    });
}

export function createLibraryShell(mount, {
    documentImpl = globalThis.document,
    eventTarget = globalThis,
    overviewUrl,
    platformUrl,
    libraryHomeUrl,
    settingsUrl,
    librarySettingsUrl,
    searchUrl,
    wishlistUrl,
    nextReadingUrl,
    loginUrl,
    accountState = "guest",
    accountName = "",
    logoutUrl,
    activeDestination = "library",
    preferences = createUiPreferences(),
} = {}) {
    if (typeof mount?.replaceChildren !== "function") {
        throw new TypeError("A Biblio UI mount element is required.");
    }

    const shell = element(documentImpl, "div", {
        className: "biblio-ui__shell",
        attributes: {
            "data-biblio-appearance": "light",
            "data-biblio-theme": "ink",
        },
    });
    const navId = "biblio-library-navigation";
    const sidebar = element(documentImpl, "aside", {
        className: "biblio-ui__sidebar",
        attributes: { id: navId },
    });
    const brand = element(documentImpl, "a", {
        className: "biblio-ui__brand",
        attributes: { href: platformUrl || overviewUrl },
    });
    brand.append(
        icon(documentImpl, "book-open", "biblio-ui__brand-mark"),
        element(documentImpl, "span", {
            className: "biblio-ui__nav-label",
            text: "Biblio",
        })
    );

    const collapseButton = element(documentImpl, "button", {
        className: "biblio-ui__sidebar-toggle",
        attributes: {
            type: "button",
            "aria-controls": navId,
        },
    });
    const collapseMark = icon(
        documentImpl,
        "chevron-left",
        "biblio-ui__collapse-mark"
    );
    const collapseLabel = element(documentImpl, "span", {
        className: "biblio-ui__nav-label",
    });
    collapseButton.append(collapseMark, collapseLabel);

    const nav = element(documentImpl, "nav", {
        className: "biblio-ui__nav",
        attributes: { "aria-label": "Hoofdnavigatie" },
    });
    let activeLibrary = null;
    function renderNavigation() {
        const contextual = typeof platformUrl === "string" && platformUrl.length > 0;
        const destinations = contextual ? [
            ["platform", "Mijn Biblio", platformUrl, "user"],
            ["search", "Zoeken in Biblio", searchUrl, "search"],
            ...(activeLibrary === null ? [] : [
                ["section", activeLibrary.name],
                ["home", "Home", activeLibrary.homeUrl, "book-open"],
                ["library", "Catalogus", activeLibrary.catalogUrl, "books"],
                ...(accountState === "authenticated" && activeLibrary.manageDefaults && activeLibrary.settingsUrl ? [
                    ["library-settings", "Instellingen", activeLibrary.settingsUrl, "settings"],
                ] : []),
            ]),
            ["section", "Persoonlijk"],
            ["wishlist", "Verlanglijst", wishlistUrl, "bookmark"],
            ["next-reading", "Hierna lezen", nextReadingUrl, "book-open"],
        ] : [
            ["library", "Mijn Bibliotheek", overviewUrl, "books"],
            ["search", "Zoeken", searchUrl, "search"],
            ["wishlist", "Verlanglijst", wishlistUrl, "bookmark"],
            ["next-reading", "Hierna lezen", nextReadingUrl, "book-open"],
        ];
        const links = [];
        for (const [key, label, href, iconName] of destinations) {
            if (key === "section") {
                links.push(element(documentImpl, "p", {
                    className: "biblio-ui__nav-section biblio-ui__nav-label",
                    text: label,
                }));
                continue;
            }
            if (typeof href !== "string" || href.length === 0) {
                continue;
            }
            const link = element(documentImpl, "a", {
                className: "biblio-ui__nav-link",
                attributes: {
                    href,
                    ...(activeDestination === key ? { "aria-current": "page" } : {}),
                    title: key === "library-settings" ? "Bibliotheekinstellingen" : label,
                    ...(key === "library-settings" ? {"aria-label":`Bibliotheekinstellingen voor ${activeLibrary.name}`} : {}),
                },
            });
            link.append(
                icon(documentImpl, iconName, "biblio-ui__nav-mark"),
                element(documentImpl, "span", {
                    className: "biblio-ui__nav-label",
                    text: label,
                })
            );
            link.addEventListener("click", closeMobileNavigation);
            links.push(link);
        }
        nav.replaceChildren(...links);
    }
    renderNavigation();

    const account = element(documentImpl, "nav", {
        className: "biblio-ui__sidebar-account",
        attributes: { "aria-label": "Account" },
    });
    const authenticated = accountState === "authenticated";
    if (authenticated) {
        const identity = element(documentImpl, "p", {
            className: "biblio-ui__sidebar-context",
            attributes: { title: accountName || "Aangemeld" },
        });
        identity.append(
            icon(documentImpl, "user", "biblio-ui__context-mark"),
            element(documentImpl, "span", {
                className: "biblio-ui__nav-label",
                text: accountName || "Aangemeld",
            })
        );
        account.append(identity);
    }
    const settingsSlot = element(documentImpl, "div", { className: "biblio-ui__settings-slot" });
    settingsSlot.hidden = true;
    account.append(settingsSlot);
    const actionLabel = authenticated ? "Uitloggen" : "Inloggen";
    const actionUrl = authenticated ? logoutUrl : loginUrl;
    if (typeof actionUrl === "string" && actionUrl.length > 0) {
        const action = element(documentImpl, "a", {
            className: "biblio-ui__account-action",
            attributes: {
                href: actionUrl,
                title: actionLabel,
                "aria-label": actionLabel,
            },
        });
        action.append(
            icon(documentImpl, authenticated ? "log-out" : "log-in", "biblio-ui__context-mark"),
            element(documentImpl, "span", {
                className: "biblio-ui__nav-label",
                text: actionLabel,
            })
        );
        if (authenticated) { action.addEventListener("click", () => clearSearchReturns()); }
        account.append(action);
    }

    function renderSettingsLink() {
        const links = [];
        if (authenticated && activeLibrary && typeof settingsUrl === "string" && settingsUrl.length > 0) {
            const url = new URL(settingsUrl);
            url.search = "";
            url.hash = "";
            url.searchParams.set("library_id", activeLibrary.id);
            const link = element(documentImpl, "a", {
                className: "biblio-ui__account-action",
                attributes: { href: url.toString(), title: "Mijn voorkeuren", "aria-label": `Mijn voorkeuren voor ${activeLibrary.name}`,
                    ...(activeDestination === "settings" ? { "aria-current": "page" } : {}) },
            });
            link.append(icon(documentImpl, "settings", "biblio-ui__context-mark"), element(documentImpl, "span", {
                className: "biblio-ui__nav-label", text: "Mijn voorkeuren",
            }));
            link.addEventListener("click", closeMobileNavigation);
            links.push(link);
        }
        settingsSlot.hidden = links.length === 0;
        settingsSlot.replaceChildren(...links);
    }
    sidebar.append(brand, collapseButton, nav, account);

    const scrim = element(documentImpl, "button", {
        className: "biblio-ui__nav-scrim",
        attributes: {
            type: "button",
            "aria-label": "Navigatie sluiten",
            tabindex: "-1",
        },
    });
    const workspace = element(documentImpl, "div", {
        className: "biblio-ui__workspace",
    });
    const mobileBar = element(documentImpl, "div", {
        className: "biblio-ui__mobile-bar",
    });
    const menuButton = element(documentImpl, "button", {
        className: "biblio-ui__menu-toggle",
        attributes: {
            type: "button",
            "aria-controls": navId,
            "aria-expanded": "false",
            "aria-label": "Navigatie openen",
        },
    });
    menuButton.append(icon(documentImpl, "menu"));
    mobileBar.append(
        menuButton,
        element(documentImpl, "span", {
            className: "biblio-ui__mobile-wordmark",
            text: "Biblio",
        })
    );
    const contentRoot = element(documentImpl, "main", {
        className: "biblio-ui__workspace-content",
        attributes: { id: "biblio-library-content" },
    });
    workspace.append(mobileBar, contentRoot);
    shell.append(sidebar, scrim, workspace);
    mount.replaceChildren(shell);

    let collapsed = preferences.sidebarCollapsed();
    let mobileOpen = false;

    function sync() {
        shell.setAttribute(
            "data-sidebar-collapsed",
            collapsed ? "true" : "false"
        );
        shell.setAttribute("data-mobile-nav-open", mobileOpen ? "true" : "false");
        collapseButton.setAttribute("aria-expanded", collapsed ? "false" : "true");
        collapseButton.setAttribute(
            "aria-label",
            collapsed ? "Navigatie uitklappen" : "Navigatie inklappen"
        );
        collapseButton.setAttribute(
            "title",
            collapsed ? "Navigatie uitklappen" : "Navigatie inklappen"
        );
        collapseLabel.textContent = collapsed ? "Uitklappen" : "Inklappen";
        collapseMark.setAttribute(
            "data-biblio-icon",
            collapsed ? "chevron-right" : "chevron-left"
        );
        menuButton.setAttribute("aria-expanded", mobileOpen ? "true" : "false");
        menuButton.setAttribute(
            "aria-label",
            mobileOpen ? "Navigatie sluiten" : "Navigatie openen"
        );
        scrim.setAttribute("tabindex", mobileOpen ? "0" : "-1");
    }

    function closeMobileNavigation() {
        mobileOpen = false;
        sync();
    }

    function onKeyDown(event) {
        if (event?.key === "Escape" && mobileOpen) {
            closeMobileNavigation();
            menuButton.focus?.();
        }
    }

    collapseButton.addEventListener("click", () => {
        collapsed = !collapsed;
        preferences.setSidebarCollapsed(collapsed);
        sync();
    });
    menuButton.addEventListener("click", () => {
        mobileOpen = !mobileOpen;
        sync();
    });
    scrim.addEventListener("click", () => {
        closeMobileNavigation();
        menuButton.focus?.();
    });
    eventTarget?.addEventListener?.("keydown", onKeyDown);
    sync();

    return Object.freeze({
        contentRoot,
        setLibraryContext(library) {
            if (library === null) {
                activeLibrary = null;
            } else {
                if (typeof library?.library_id !== "string" || library.library_id.length === 0
                    || typeof library.name !== "string" || library.name.length === 0
                    || typeof libraryHomeUrl !== "string" || typeof overviewUrl !== "string") {
                    throw new TypeError("An authorized Library and its routes are required.");
                }
                const home = new URL(libraryHomeUrl);
                const catalog = new URL(overviewUrl);
                home.searchParams.set("library_id", library.library_id);
                catalog.searchParams.set("library_id", library.library_id);
                let settings = null;
                if (typeof librarySettingsUrl === "string" && librarySettingsUrl.length > 0) {
                    settings = new URL(librarySettingsUrl);
                    settings.search = ""; settings.hash = "";
                    settings.searchParams.set("library_id", library.library_id);
                }
                activeLibrary = { id: library.library_id, name: library.name, homeUrl: home.toString(), catalogUrl: catalog.toString(),
                    manageDefaults: library.capabilities?.manage_defaults === true, settingsUrl:settings?.toString() };
            }
            renderNavigation();
            renderSettingsLink();
        },
        destroy() {
            eventTarget?.removeEventListener?.("keydown", onKeyDown);
        },
    });
}
