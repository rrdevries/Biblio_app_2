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

function navMark(documentImpl) {
    return element(documentImpl, "span", {
        className: "biblio-ui__nav-mark",
        text: "MB",
        attributes: { "aria-hidden": "true" },
    });
}

export function createLibraryShell(mount, {
    documentImpl = globalThis.document,
    eventTarget = globalThis,
    overviewUrl,
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
        attributes: { href: overviewUrl },
    });
    brand.append(
        element(documentImpl, "span", {
            className: "biblio-ui__brand-mark",
            text: "B",
            attributes: { "aria-hidden": "true" },
        }),
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
    const collapseMark = element(documentImpl, "span", {
        className: "biblio-ui__collapse-mark",
        text: "‹",
        attributes: { "aria-hidden": "true" },
    });
    const collapseLabel = element(documentImpl, "span", {
        className: "biblio-ui__nav-label",
    });
    collapseButton.append(collapseMark, collapseLabel);

    const nav = element(documentImpl, "nav", {
        className: "biblio-ui__nav",
        attributes: { "aria-label": "Hoofdnavigatie" },
    });
    const navLink = element(documentImpl, "a", {
        className: "biblio-ui__nav-link",
        attributes: {
            href: overviewUrl,
            "aria-current": "page",
            title: "Mijn Bibliotheek",
        },
    });
    navLink.append(
        navMark(documentImpl),
        element(documentImpl, "span", {
            className: "biblio-ui__nav-label",
            text: "Mijn Bibliotheek",
        })
    );
    nav.append(navLink);

    const account = element(documentImpl, "p", {
        className: "biblio-ui__sidebar-context",
    });
    account.append(
        element(documentImpl, "span", {
            className: "biblio-ui__context-mark",
            text: "P",
            attributes: { "aria-hidden": "true" },
        }),
        element(documentImpl, "span", {
            className: "biblio-ui__nav-label",
            text: "Privéomgeving",
        })
    );

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
    menuButton.append(element(documentImpl, "span", {
        text: "☰",
        attributes: { "aria-hidden": "true" },
    }));
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
        collapseMark.textContent = collapsed ? "›" : "‹";
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
    navLink.addEventListener("click", closeMobileNavigation);
    eventTarget?.addEventListener?.("keydown", onKeyDown);
    sync();

    return Object.freeze({
        contentRoot,
        destroy() {
            eventTarget?.removeEventListener?.("keydown", onKeyDown);
        },
    });
}
