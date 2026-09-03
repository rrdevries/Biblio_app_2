const READING_STATUS_LABELS = Object.freeze({
    not_read: "Niet gelezen",
    reading: "Aan het lezen",
    read: "Uitgelezen",
});

const VIEW_LABELS = Object.freeze({
    grid: "Grid",
    list: "Lijst",
    bookshelf: "Boekenplank",
});

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

function append(parent, ...children) {
    parent.append(...children.filter((child) => child !== null));
    return parent;
}

function actionButton(documentImpl, label, listener, modifier = "secondary") {
    const button = element(documentImpl, "button", {
        className: `biblio-ui__control biblio-ui__control--${modifier}`,
        text: label,
        attributes: { type: "button" },
    });

    button.addEventListener("click", listener);
    return button;
}

function shouldHandleNavigation(event) {
    return !event?.defaultPrevented
        && (event?.button === undefined || event.button === 0)
        && event?.metaKey !== true
        && event?.ctrlKey !== true
        && event?.shiftKey !== true
        && event?.altKey !== true;
}

function page(documentImpl, state, busy = false) {
    return element(documentImpl, "section", {
        className: "biblio-ui__view",
        attributes: {
            "aria-busy": busy ? "true" : "false",
            "data-biblio-view": state,
        },
    });
}

function appHeading(documentImpl, parent) {
    parent.append(element(documentImpl, "h1", {
        className: "biblio-ui__page-title",
        text: "Mijn Bibliotheek",
    }));
}

function libraryAccessLabel(library) {
    if (library?.capabilities?.use_item_directly === true) {
        return "Directe toegang";
    }

    if (library?.capabilities?.receive_internal_loan === true) {
        return "Lenen";
    }

    return "Alleen bekijken";
}

function knownText(value) {
    return value?.state === "known" && typeof value.value === "string"
        && value.value.length > 0
        ? value.value
        : null;
}

function authorLine(item) {
    return item?.authors?.state === "known"
        && Array.isArray(item.authors.values)
        && item.authors.values.length > 0
        ? item.authors.values.join(", ")
        : null;
}

function readingStatusLabel(status) {
    const label = READING_STATUS_LABELS[status];

    if (label === undefined) {
        throw new TypeError("The Biblio Item reading status is invalid.");
    }

    return label;
}

function contextLine(item) {
    const segments = [];

    if (knownText(item.form) === "physical_book") {
        segments.push("Boek");
    }

    const locationOrSource = knownText(item.location_or_source);

    if (locationOrSource !== null) {
        segments.push(locationOrSource);
    }

    segments.push(readingStatusLabel(item.reading_status));
    return segments.join(" · ");
}

function coverImage(documentImpl, item, modifier) {
    const cover = knownText(item.cover_reference);

    return cover === null
        ? null
        : element(documentImpl, "img", {
            className: `biblio-ui__cover biblio-ui__cover--${modifier}`,
            attributes: {
                alt: `Omslag van ${item.title}`,
                src: cover,
            },
        });
}

function itemCard(documentImpl, item, libraryId, itemUrl, actions) {
    const listItem = element(documentImpl, "li", {
        className: "biblio-ui__catalog-item",
        attributes: { "data-biblio-item-id": item.item_id },
    });
    const canView = item?.capabilities?.view_item === true;
    const content = canView
        ? element(documentImpl, "a", {
            className: "biblio-ui__book-link",
            attributes: { href: itemUrl(libraryId, item.item_id) },
        })
        : element(documentImpl, "div", {
            className: "biblio-ui__book-link",
        });

    if (canView) {
        content.addEventListener("click", (event) => {
            if (!shouldHandleNavigation(event)) {
                return;
            }

            event?.preventDefault();
            actions.openItem(item.item_id);
        });
    }

    const cover = coverImage(documentImpl, item, "overview");
    if (cover !== null) {
        content.append(cover);
    }

    const body = element(documentImpl, "div", {
        className: "biblio-ui__book-copy",
    });
    body.append(element(documentImpl, "h3", {
        className: "biblio-ui__book-title",
        text: item.title,
    }));
    const authors = authorLine(item);
    if (authors !== null) {
        body.append(element(documentImpl, "p", {
            className: "biblio-ui__authors",
            text: authors,
        }));
    }
    body.append(element(documentImpl, "p", {
        className: "biblio-ui__context",
        text: contextLine(item),
    }));
    content.append(body);
    listItem.append(content);

    if (canView) {
        const quickView = actionButton(
            documentImpl,
            `Snel bekijken: ${item.title}`,
            () => actions.quickView(item.item_id),
            "tertiary"
        );
        quickView.className += " biblio-ui__quick-view-trigger";
        quickView.setAttribute("data-quick-view-for", item.item_id);
        listItem.append(quickView);
    }

    return listItem;
}

function renderToolbar(documentImpl, {
    filtersOpen,
    selectedView,
    setFiltersOpen,
    setView,
}) {
    const region = element(documentImpl, "section", {
        className: "biblio-ui__toolbar-region",
        attributes: { "aria-label": "Bibliotheekweergave" },
    });
    const toolbar = element(documentImpl, "div", {
        className: "biblio-ui__toolbar",
    });
    const searchLabel = element(documentImpl, "label", {
        className: "biblio-ui__search",
    });
    searchLabel.append(
        element(documentImpl, "span", {
            className: "biblio-ui__visually-hidden",
            text: "Zoeken",
        }),
        element(documentImpl, "input", {
            attributes: {
                type: "search",
                placeholder: "Zoeken",
                disabled: "disabled",
                "aria-describedby": "biblio-toolbar-contract-note",
            },
        })
    );

    const filterButton = actionButton(
        documentImpl,
        "Filters",
        () => setFiltersOpen(!filtersOpen),
        "secondary"
    );
    filterButton.className += " biblio-ui__filter-toggle";
    filterButton.setAttribute("aria-expanded", filtersOpen ? "true" : "false");
    filterButton.setAttribute("aria-controls", "biblio-filter-panel");

    const sortLabel = element(documentImpl, "label", {
        className: "biblio-ui__sort",
    });
    const sortSelect = element(documentImpl, "select", {
        attributes: {
            disabled: "disabled",
            "aria-describedby": "biblio-toolbar-contract-note",
            "aria-label": "Sorteren",
        },
    });
    sortSelect.append(element(documentImpl, "option", { text: "Titel A–Z" }));
    sortLabel.append(
        element(documentImpl, "span", {
            className: "biblio-ui__visually-hidden",
            text: "Sorteren",
        }),
        sortSelect
    );

    const switcher = element(documentImpl, "div", {
        className: "biblio-ui__view-switch",
        attributes: { "aria-label": "Weergave", role: "group" },
    });
    for (const [value, label] of Object.entries(VIEW_LABELS)) {
        const button = actionButton(
            documentImpl,
            label,
            () => setView(value),
            value === selectedView ? "active" : "tertiary"
        );
        button.setAttribute("aria-pressed", value === selectedView ? "true" : "false");
        switcher.append(button);
    }

    toolbar.append(searchLabel, filterButton, sortLabel, switcher);
    region.append(toolbar);

    if (filtersOpen) {
        const filterPanel = element(documentImpl, "div", {
            className: "biblio-ui__filter-panel",
            attributes: { id: "biblio-filter-panel" },
        });
        filterPanel.append(
            element(documentImpl, "p", {
                className: "biblio-ui__filter-heading",
                text: "Gedetailleerde filters",
            }),
            element(documentImpl, "p", {
                text: "Filteropties worden actief zodra het Library-REST-contract zoeken en filteren ondersteunt.",
            })
        );
        region.append(filterPanel);
    }

    region.append(element(documentImpl, "p", {
        className: "biblio-ui__toolbar-note",
        text: "Zoeken, filterwaarden en alternatieve sortering zijn nog niet beschikbaar voor de volledige catalogus.",
        attributes: { id: "biblio-toolbar-contract-note" },
    }));
    return region;
}

function renderBookshelfPlaceholder(documentImpl, setView) {
    const placeholder = element(documentImpl, "section", {
        className: "biblio-ui__bookshelf-placeholder",
        attributes: { "aria-labelledby": "biblio-bookshelf-title" },
    });
    append(
        placeholder,
        element(documentImpl, "p", {
            className: "biblio-ui__eyebrow",
            text: "Weergave voorbereid",
        }),
        element(documentImpl, "h2", {
            text: "Boekenplank",
            attributes: { id: "biblio-bookshelf-title" },
        }),
        element(documentImpl, "p", {
            text: "De fysieke rugweergave wacht op het definitieve coverratio- en titelcontract.",
        }),
        actionButton(documentImpl, "Terug naar Grid", () => setView("grid"))
    );
    return placeholder;
}

function quickViewDetail(
    documentImpl,
    quickView,
    itemUrl,
    libraryId,
    actions,
    restoreFocus
) {
    const dialog = element(documentImpl, "dialog", {
        className: "biblio-ui__quick-view",
        attributes: { "aria-labelledby": "biblio-quick-view-title" },
    });
    const header = element(documentImpl, "header", {
        className: "biblio-ui__quick-view-header",
    });
    const heading = quickView.state === "ready"
        ? quickView.detail.title
        : quickView.state === "loading"
            ? "Boek laden"
            : "Boek niet beschikbaar";
    header.append(
        element(documentImpl, "p", {
            className: "biblio-ui__eyebrow",
            text: "Snel bekijken",
        }),
        element(documentImpl, "h2", {
            className: "biblio-ui__quick-view-title",
            text: heading,
            attributes: { id: "biblio-quick-view-title" },
        })
    );
    const closeButton = actionButton(
        documentImpl,
        "×",
        () => dialog.close?.(),
        "tertiary"
    );
    closeButton.setAttribute("aria-label", "Snel bekijken sluiten");
    closeButton.className += " biblio-ui__quick-view-close";
    header.append(closeButton);
    dialog.append(header);

    if (quickView.state === "loading") {
        dialog.setAttribute("aria-busy", "true");
        dialog.append(element(documentImpl, "p", {
            text: "Boekgegevens laden…",
            attributes: { "aria-live": "polite" },
        }));
    } else if (quickView.state === "ready") {
        const detail = quickView.detail;
        const cover = coverImage(documentImpl, detail, "quick-view");
        if (cover !== null) {
            dialog.append(cover);
        }
        const authors = authorLine(detail);
        if (authors !== null) {
            dialog.append(element(documentImpl, "p", {
                className: "biblio-ui__authors",
                text: authors,
            }));
        }
        dialog.append(element(documentImpl, "p", {
            className: "biblio-ui__status-line",
            text: `Leesstatus: ${readingStatusLabel(detail.reading.status)}`,
        }));
        const fullDetail = element(documentImpl, "a", {
            className: "biblio-ui__control biblio-ui__control--primary",
            text: "Volledige boekdetails",
            attributes: { href: itemUrl(libraryId, detail.item_id) },
        });
        fullDetail.addEventListener("click", (event) => {
            if (!shouldHandleNavigation(event)) {
                return;
            }
            event?.preventDefault();
            actions.openItem(detail.item_id);
        });
        dialog.append(fullDetail);
    } else {
        dialog.append(element(documentImpl, "p", {
            text: quickView.state === "unavailable"
                ? "Dit boek bestaat niet of is niet meer toegankelijk."
                : "De boekgegevens konden niet worden geladen.",
            attributes: { role: "alert" },
        }));
        if (quickView.state === "error") {
            dialog.append(actionButton(
                documentImpl,
                "Opnieuw proberen",
                () => actions.retryQuickView(quickView.itemId)
            ));
        }
    }

    dialog.addEventListener("close", () => {
        actions.closeQuickView();
        dialog.remove?.();
        restoreFocus();
    });
    return dialog;
}

function renderLibraryLoading(documentImpl) {
    const view = page(documentImpl, "library-loading", true);
    view.setAttribute("aria-live", "polite");
    view.append(element(documentImpl, "h1", {
        className: "biblio-ui__page-title",
        text: "Bibliotheek laden",
    }));
    return view;
}

function renderOverviewLoading(documentImpl, model) {
    const view = page(documentImpl, "overview-loading", true);
    view.setAttribute("aria-live", "polite");
    appHeading(documentImpl, view);
    append(
        view,
        element(documentImpl, "p", { text: model.library.name }),
        element(documentImpl, "h2", { text: "Boeken laden" })
    );
    return view;
}

function renderZeroLibraries(documentImpl) {
    const view = page(documentImpl, "zero-libraries");
    append(
        view,
        element(documentImpl, "h1", {
            className: "biblio-ui__page-title",
            text: "Geen bibliotheek beschikbaar",
        }),
        element(documentImpl, "p", {
            text: "Er is nog geen bibliotheek die je hier kunt openen.",
        })
    );
    return view;
}

function renderLibraryUnavailable(documentImpl, overviewUrl) {
    const view = page(documentImpl, "library-unavailable");
    append(
        view,
        element(documentImpl, "h1", {
            className: "biblio-ui__page-title",
            text: "Bibliotheek niet beschikbaar",
        }),
        element(documentImpl, "p", {
            text: "Deze bibliotheek bestaat niet of is niet meer toegankelijk.",
            attributes: { role: "alert" },
        }),
        element(documentImpl, "a", {
            className: "biblio-ui__control biblio-ui__control--primary",
            text: "Terug naar Mijn Bibliotheek",
            attributes: { href: overviewUrl },
        })
    );
    return view;
}

function renderRequestError(documentImpl, actions) {
    const view = page(documentImpl, "request-error");
    append(
        view,
        element(documentImpl, "h1", {
            className: "biblio-ui__page-title",
            text: "Bibliotheek kon niet worden geladen",
        }),
        element(documentImpl, "p", {
            text: "Probeer de bibliotheek opnieuw te laden.",
            attributes: { role: "alert" },
        }),
        actionButton(documentImpl, "Opnieuw proberen", actions.retry, "primary")
    );
    return view;
}

function renderChooser(documentImpl, model, actions) {
    const view = page(documentImpl, "library-chooser");
    appHeading(documentImpl, view);
    view.append(element(documentImpl, "h2", { text: "Kies een bibliotheek" }));
    const list = element(documentImpl, "ul", {
        className: "biblio-ui__library-list",
    });

    for (const library of model.libraries) {
        const listItem = element(documentImpl, "li");
        append(
            listItem,
            actionButton(
                documentImpl,
                library.name,
                () => actions.selectLibrary(library.library_id)
            ),
            element(documentImpl, "p", { text: libraryAccessLabel(library) })
        );
        list.append(listItem);
    }
    view.append(list);
    return view;
}

function renderLoadMore(documentImpl, model, actions) {
    if (model.loadMoreError === true) {
        const error = element(documentImpl, "section", {
            className: "biblio-ui__inline-error",
            attributes: { "data-biblio-load-more-error": "true" },
        });
        append(
            error,
            element(documentImpl, "h3", {
                text: "Meer boeken konden niet worden geladen",
            }),
            element(documentImpl, "p", {
                text: "De al geladen boeken blijven beschikbaar.",
                attributes: { role: "alert" },
            })
        );
        if (model.canRetryCursor === true) {
            error.append(actionButton(
                documentImpl,
                "Opnieuw proberen",
                actions.retryLoadMore
            ));
        }
        error.append(actionButton(documentImpl, "Vanaf het begin", actions.restart));
        return error;
    }

    if (model.nextCursor === null) {
        return null;
    }

    const button = actionButton(documentImpl, "Meer laden", actions.loadMore);
    button.className += " biblio-ui__load-more";
    button.disabled = model.loadingMore === true;
    if (model.loadingMore === true) {
        button.setAttribute("aria-busy", "true");
    }
    return button;
}

function renderOverview(documentImpl, model, actions, itemUrl, uiState) {
    const view = page(documentImpl, "overview", model.loadingMore === true);
    const header = element(documentImpl, "header", {
        className: "biblio-ui__page-header",
    });
    appHeading(documentImpl, header);
    header.append(element(documentImpl, "p", {
        className: "biblio-ui__library",
        text: model.library.name,
    }));
    view.append(header);

    const rerender = () => uiState.render(model, actions);
    view.append(renderToolbar(documentImpl, {
        filtersOpen: uiState.filtersOpen,
        selectedView: uiState.selectedView,
        setFiltersOpen(value) {
            uiState.filtersOpen = value;
            rerender();
        },
        setView(value) {
            uiState.selectedView = value;
            rerender();
        },
    }));

    if (model.items.length === 0) {
        view.append(element(documentImpl, "h2", {
            text: "Nog geen actieve boeken",
        }));
        return view;
    }

    if (uiState.selectedView === "bookshelf") {
        view.append(renderBookshelfPlaceholder(documentImpl, (value) => {
            uiState.selectedView = value;
            rerender();
        }));
    } else {
        const heading = element(documentImpl, "h2", {
            className: "biblio-ui__visually-hidden",
            text: "Boeken",
        });
        const list = element(documentImpl, "ul", {
            className: "biblio-ui__catalog-list",
            attributes: {
                "aria-label": "Actieve boeken",
                "data-catalog-view": uiState.selectedView,
            },
        });
        for (const item of model.items) {
            list.append(itemCard(
                documentImpl,
                item,
                model.library.library_id,
                itemUrl,
                actions
            ));
        }
        view.append(heading, list);
    }

    const loadMore = renderLoadMore(documentImpl, model, actions);
    if (loadMore !== null) {
        view.append(loadMore);
    }

    if (model.quickView !== null && model.quickView !== undefined) {
        const dialog = quickViewDetail(
            documentImpl,
            model.quickView,
            itemUrl,
            model.library.library_id,
            actions,
            () => {
                const triggers = view.querySelectorAll?.("[data-quick-view-for]") ?? [];
                for (const trigger of triggers) {
                    if (trigger.getAttribute("data-quick-view-for") === model.quickView.itemId) {
                        trigger.focus?.();
                        break;
                    }
                }
            }
        );
        view.append(dialog);
    }
    return view;
}

export function createOverviewView(root, {
    documentImpl = globalThis.document,
    overviewUrl,
    itemUrl,
} = {}) {
    if (typeof root?.replaceChildren !== "function") {
        throw new TypeError("A Biblio UI mount element is required.");
    }
    if (typeof documentImpl?.createElement !== "function") {
        throw new TypeError("A browser Document implementation is required.");
    }
    if (typeof overviewUrl !== "string" || overviewUrl.length === 0) {
        throw new TypeError("A canonical Biblio overview URL is required.");
    }
    if (typeof itemUrl !== "function") {
        throw new TypeError("A Biblio Item URL builder is required.");
    }

    const uiState = {
        filtersOpen: false,
        selectedView: "grid",
        render: null,
    };

    function render(model, actions = {}) {
        let view;

        switch (model.state) {
        case "library-loading":
            view = renderLibraryLoading(documentImpl);
            break;
        case "overview-loading":
            view = renderOverviewLoading(documentImpl, model);
            break;
        case "zero-libraries":
            view = renderZeroLibraries(documentImpl);
            break;
        case "library-unavailable":
            view = renderLibraryUnavailable(documentImpl, overviewUrl);
            break;
        case "request-error":
            view = renderRequestError(documentImpl, actions);
            break;
        case "library-chooser":
            view = renderChooser(documentImpl, model, actions);
            break;
        case "overview":
            view = renderOverview(documentImpl, model, actions, itemUrl, uiState);
            break;
        default:
            throw new TypeError("The Biblio overview view state is invalid.");
        }

        root.replaceChildren(view);
        root.setAttribute?.("aria-busy", view.getAttribute("aria-busy"));

        const quickView = view.querySelector?.("dialog");
        quickView?.showModal?.();

        if (model.focusHeading === true) {
            const heading = view.querySelector("h1");
            heading?.setAttribute("tabindex", "-1");
            heading?.focus();
        }
        return view;
    }

    uiState.render = render;
    return Object.freeze({ render });
}
