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

function icon(documentImpl, name) {
    return element(documentImpl, "span", {
        className: "biblio-ui__icon",
        attributes: {
            "aria-hidden": "true",
            "data-biblio-icon": name,
        },
    });
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

    return segments.join(" · ");
}

function coverImage(documentImpl, item, modifier) {
    const cover = knownText(item.cover_reference);

    if (cover !== null) {
        return element(documentImpl, "img", {
            className: `biblio-ui__cover biblio-ui__cover--${modifier}`,
            attributes: {
                alt: `Omslag van ${item.title}`,
                src: cover,
            },
        });
    }

    const placeholder = element(documentImpl, "span", {
        className: `biblio-ui__cover biblio-ui__cover--${modifier} biblio-ui__cover--placeholder`,
        attributes: {
            role: "img",
            "aria-label": `Geen omslag beschikbaar voor ${item.title}`,
        },
    });
    placeholder.append(
        icon(documentImpl, "book-open"),
        element(documentImpl, "span", {
            className: "biblio-ui__cover-label",
            text: "Biblio",
            attributes: { "aria-hidden": "true" },
        })
    );
    return placeholder;
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

    content.append(coverImage(documentImpl, item, "overview"));

    const body = element(documentImpl, "div", {
        className: "biblio-ui__book-copy",
    });
    body.append(element(documentImpl, "h3", {
        className: "biblio-ui__book-title",
        text: item.title,
    }));
    const authors = authorLine(item);
    body.append(element(documentImpl, "p", {
        className: authors === null
            ? "biblio-ui__authors biblio-ui__authors--unknown"
            : "biblio-ui__authors",
        text: authors ?? "Auteur onbekend",
    }));
    const metadata = element(documentImpl, "div", {
        className: "biblio-ui__book-meta",
    });
    const context = contextLine(item);
    if (context.length > 0) {
        metadata.append(element(documentImpl, "p", {
            className: "biblio-ui__context",
            text: context,
        }));
    }
    metadata.append(element(documentImpl, "span", {
        className: `biblio-ui__status biblio-ui__status--${item.reading_status}`,
        text: readingStatusLabel(item.reading_status),
    }));
    if (item.item_status === "archived") {
        metadata.append(element(documentImpl, "span", {
            className: "biblio-ui__status biblio-ui__status--archived",
            text: "Archief",
        }));
    }
    if (typeof item.contained_match_title === "string") {
        metadata.append(element(documentImpl, "span", {
            className: "biblio-ui__contained-match",
            text: `Bevat: ${item.contained_match_title}`,
        }));
    }
    body.append(metadata);
    content.append(body);
    listItem.append(content);

    if (canView) {
        const quickView = actionButton(
            documentImpl,
            "",
            () => actions.quickView(item.item_id),
            "tertiary"
        );
        quickView.className += " biblio-ui__quick-view-trigger";
        quickView.setAttribute("data-quick-view-for", item.item_id);
        quickView.setAttribute("aria-label", `Snel bekijken: ${item.title}`);
        quickView.setAttribute("title", "Snel bekijken");
        quickView.append(icon(documentImpl, "eye"));
        listItem.append(quickView);
    }

    return listItem;
}

function optionMap(options) {
    return new Map(options.map((option) => [option.id, option.label]));
}

function activeFilterCount(query) {
    return [
        "readingStatuses",
        "authorIds",
        "seriesIds",
        "locationIds",
        "bookTypeIds",
        "genreIds",
        "subjectIds",
        "collectionIds",
    ].reduce((count, property) => count + query[property].length, 0)
        + (query.withoutCollection ? 1 : 0)
        + (query.archiveScope === "active_and_archived" ? 1 : 0);
}

function checkbox(documentImpl, label, checked, listener, focusKey = null) {
    const wrapper = element(documentImpl, "label", {
        className: "biblio-ui__filter-option",
    });
    const input = element(documentImpl, "input", {
        attributes: {
            type: "checkbox",
            ...(focusKey === null ? {} : { "data-biblio-focus-key": focusKey }),
        },
    });
    input.checked = checked;
    input.addEventListener("change", (event) => listener(event.currentTarget.checked));
    wrapper.append(input, element(documentImpl, "span", { text: label }));
    return wrapper;
}

function filterGroup(documentImpl, legend, property, options, query, actions) {
    if (options.length === 0) {
        return null;
    }
    const fieldset = element(documentImpl, "fieldset", {
        className: "biblio-ui__filter-group",
    });
    fieldset.append(element(documentImpl, "legend", { text: legend }));
    for (const option of options) {
        fieldset.append(checkbox(
            documentImpl,
            option.label,
            query[property].includes(option.id),
            (selected) => actions.setFilter(property, option.id, selected),
            `filter:${property}:${option.id}`
        ));
    }
    return fieldset;
}

function renderToolbar(documentImpl, model, actions, {
    filtersOpen,
    selectedView,
    setFiltersOpen,
    setView,
}) {
    const query = model.query;
    const count = activeFilterCount(query);
    const region = element(documentImpl, "section", {
        className: "biblio-ui__toolbar-region",
        attributes: { "aria-label": "Bibliotheekweergave" },
    });
    const toolbar = element(documentImpl, "div", {
        className: "biblio-ui__toolbar",
    });
    const searchForm = element(documentImpl, "form", {
        className: "biblio-ui__search",
        attributes: { role: "search" },
    });
    const searchInput = element(documentImpl, "input", {
        attributes: {
            type: "search",
            placeholder: "Zoeken in deze bibliotheek",
            value: model.searchDraft,
            "aria-describedby": "biblio-search-help",
            "data-biblio-focus-key": "search",
        },
    });
    searchInput.value = model.searchDraft;
    searchInput.addEventListener("input", (event) => {
        actions.searchInput(event.currentTarget.value);
    });
    searchForm.addEventListener("submit", (event) => {
        event.preventDefault();
        actions.submitSearch(searchInput.value);
    });
    searchForm.append(
        element(documentImpl, "label", {
            className: "biblio-ui__visually-hidden",
            text: "Zoeken in deze bibliotheek",
            attributes: { for: "biblio-catalog-search" },
        }),
        searchInput
    );
    searchInput.setAttribute("id", "biblio-catalog-search");
    if (model.searchDraft !== "") {
        const clear = actionButton(documentImpl, "Wissen", actions.clearSearch, "tertiary");
        clear.className += " biblio-ui__search-clear";
        clear.setAttribute("aria-label", "Zoekopdracht wissen");
        clear.setAttribute("data-biblio-focus-key", "search-clear");
        searchForm.append(clear);
    }

    const filterButton = actionButton(
        documentImpl,
        count === 0 ? "Filters" : `Filters (${count})`,
        () => setFiltersOpen(!filtersOpen),
        "secondary"
    );
    filterButton.className += " biblio-ui__filter-toggle";
    filterButton.setAttribute("data-biblio-focus-key", "filter-toggle");
    filterButton.setAttribute("aria-expanded", filtersOpen ? "true" : "false");
    filterButton.setAttribute("aria-controls", "biblio-filter-panel");

    const sortLabel = element(documentImpl, "label", {
        className: "biblio-ui__sort",
    });
    const sortSelect = element(documentImpl, "select", {
        attributes: {
            "aria-label": "Sorteren",
            "data-biblio-focus-key": "sort",
        },
    });
    for (const [value, label] of [
        ["title", "Titel A–Z"],
        ["author", "Auteur A–Z"],
        ...(query.seriesIds.length > 0 ? [["series", "Serievolgorde"]] : []),
    ]) {
        const option = element(documentImpl, "option", {
            text: label,
            attributes: { value },
        });
        option.selected = query.sort === value;
        sortSelect.append(option);
    }
    sortSelect.value = query.sort;
    sortSelect.addEventListener("change", (event) => {
        actions.setSort(event.currentTarget.value);
    });
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
            value === "bookshelf" ? () => {} : () => setView(value),
            value === selectedView ? "active" : "tertiary"
        );
        button.setAttribute("aria-pressed", value === selectedView ? "true" : "false");
        button.setAttribute("data-biblio-focus-key", `view:${value}`);
        if (value === "bookshelf") {
            button.disabled = true;
            button.setAttribute("aria-describedby", "biblio-toolbar-contract-note");
            button.setAttribute("title", "Boekenplank is nog niet beschikbaar");
        }
        switcher.append(button);
    }

    toolbar.append(searchForm, filterButton, sortLabel, switcher);
    region.append(toolbar);

    const searchLength = [...model.searchDraft.trim()].length;
    region.append(element(documentImpl, "p", {
        className: "biblio-ui__search-help",
        text: searchLength === 1
            ? "Typ nog één teken om te zoeken."
            : searchLength > 191
                ? "Gebruik maximaal 191 tekens."
                : "Zoeken start vanaf twee tekens.",
        attributes: { id: "biblio-search-help" },
    }));
    region.append(element(documentImpl, "p", {
        className: "biblio-ui__visually-hidden",
        text: "Boekenplank is nog niet beschikbaar.",
        attributes: { id: "biblio-toolbar-contract-note" },
    }));

    if (filtersOpen) {
        const filterPanel = element(documentImpl, "div", {
            className: "biblio-ui__filter-panel",
            attributes: { id: "biblio-filter-panel" },
        });
        const readingOptions = [
            { id: "reading", label: "Aan het lezen" },
            { id: "read", label: "Uitgelezen" },
            { id: "not_read", label: "Niet gelezen" },
        ];
        filterPanel.append(element(documentImpl, "p", {
            className: "biblio-ui__filter-heading",
            text: "Filters",
        }));
        for (const group of [
            filterGroup(documentImpl, "Leesstatus", "readingStatuses", readingOptions, query, actions),
            filterGroup(documentImpl, "Boeksoort", "bookTypeIds", model.filterOptions.bookTypes, query, actions),
            filterGroup(documentImpl, "Genre", "genreIds", model.filterOptions.genres, query, actions),
            filterGroup(documentImpl, "Onderwerp", "subjectIds", model.filterOptions.subjects, query, actions),
        ]) {
            if (group !== null) {
                filterPanel.append(group);
            }
        }
        filterPanel.append(checkbox(
            documentImpl,
            "Zonder collectie",
            query.withoutCollection,
            actions.setWithoutCollection,
            "filter:without-collection"
        ));
        filterPanel.append(checkbox(
            documentImpl,
            "Ook in archief zoeken",
            query.archiveScope === "active_and_archived",
            actions.setArchiveScope,
            "filter:archive"
        ));
        region.append(filterPanel);
    }

    if (count > 0) {
        const labels = {
            readingStatuses: new Map([
                ["reading", "Aan het lezen"],
                ["read", "Uitgelezen"],
                ["not_read", "Niet gelezen"],
            ]),
            bookTypeIds: optionMap(model.filterOptions.bookTypes),
            genreIds: optionMap(model.filterOptions.genres),
            subjectIds: optionMap(model.filterOptions.subjects),
            authorIds: new Map(),
            seriesIds: new Map(),
            locationIds: new Map(),
            collectionIds: new Map(),
        };
        const prefixes = {
            authorIds: "Auteur",
            seriesIds: "Serie",
            locationIds: "Locatie",
            collectionIds: "Collectie",
        };
        const chips = element(documentImpl, "div", {
            className: "biblio-ui__filter-chips",
            attributes: { "aria-label": "Actieve filters" },
        });
        for (const property of Object.keys(labels)) {
            for (const value of query[property]) {
                const label = labels[property].get(value)
                    ?? `${prefixes[property] ?? "Filter"}`;
                const chip = actionButton(
                    documentImpl,
                    `${label} ×`,
                    () => actions.setFilter(property, value, false),
                    "tertiary"
                );
                chip.className += " biblio-ui__filter-chip";
                chip.setAttribute("data-biblio-focus-key", `chip:${property}:${value}`);
                chip.setAttribute("aria-label", `${label} verwijderen`);
                chips.append(chip);
            }
        }
        if (query.withoutCollection) {
            const chip = actionButton(
                documentImpl,
                "Zonder collectie ×",
                () => actions.setWithoutCollection(false),
                "tertiary"
            );
            chip.className += " biblio-ui__filter-chip";
            chip.setAttribute("data-biblio-focus-key", "chip:without-collection");
            chips.append(chip);
        }
        if (query.archiveScope === "active_and_archived") {
            const chip = actionButton(
                documentImpl,
                "Ook in archief ×",
                () => actions.setArchiveScope(false),
                "tertiary"
            );
            chip.className += " biblio-ui__filter-chip";
            chip.setAttribute("data-biblio-focus-key", "chip:archive");
            chips.append(chip);
        }
        const clearFilters = actionButton(
            documentImpl,
            "Alle filters wissen",
            actions.clearFilters,
            "tertiary"
        );
        clearFilters.setAttribute("data-biblio-focus-key", "clear-filters");
        chips.append(clearFilters);
        region.append(chips);
    }
    return region;
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
        dialog.append(coverImage(documentImpl, detail, "quick-view"));
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
    const view = page(
        documentImpl,
        "overview",
        model.loadingMore === true || model.refreshing === true
    );
    const header = element(documentImpl, "header", {
        className: "biblio-ui__page-header",
    });
    const headingGroup = element(documentImpl, "div", {
        className: "biblio-ui__page-heading",
    });
    headingGroup.append(
        element(documentImpl, "p", {
            className: "biblio-ui__eyebrow",
            text: "Catalogus",
        })
    );
    appHeading(documentImpl, headingGroup);
    headingGroup.append(element(documentImpl, "p", {
        className: "biblio-ui__library",
        text: model.library.name,
    }));
    header.append(headingGroup);
    if (model.library.capabilities.add_catalog_item === true) {
        const addBook = actionButton(
            documentImpl,
            "Boek toevoegen",
            (event) => actions.addBook(event?.currentTarget),
            "primary"
        );
        addBook.className += " biblio-ui__add-book-trigger";
        header.append(addBook);
    }
    view.append(header);

    const rerender = () => uiState.render(model, actions);
    view.append(renderToolbar(documentImpl, model, actions, {
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

    view.append(element(documentImpl, "p", {
        className: "biblio-ui__result-status biblio-ui__visually-hidden",
        text: model.resultAnnouncement,
        attributes: { "aria-live": "polite", role: "status" },
    }));

    if (model.refreshing === true) {
        view.append(element(documentImpl, "p", {
            className: "biblio-ui__query-loading",
            text: "Boeken zoeken…",
            attributes: { role: "status" },
        }));
        return view;
    }

    if (model.queryError === true) {
        const error = element(documentImpl, "section", {
            className: "biblio-ui__inline-error",
        });
        append(
            error,
            element(documentImpl, "h2", { text: "Catalogus kon niet worden bijgewerkt" }),
            element(documentImpl, "p", {
                text: "Je zoekopdracht en filters zijn bewaard.",
                attributes: { role: "alert" },
            }),
            actionButton(documentImpl, "Opnieuw proberen", actions.retryQuery, "primary")
        );
        view.append(error);
        return view;
    }

    if (model.items.length === 0) {
        const hasQuery = model.query.search !== ""
            || activeFilterCount(model.query) > 0;
        const empty = element(documentImpl, "section", {
            className: "biblio-ui__empty-state",
            attributes: { "aria-labelledby": "biblio-empty-title" },
        });
        append(
            empty,
            icon(documentImpl, "book-open"),
            element(documentImpl, "h2", {
                text: hasQuery ? "Geen boeken gevonden" : "Nog geen actieve boeken",
                attributes: { id: "biblio-empty-title" },
            }),
            element(documentImpl, "p", {
                text: hasQuery
                    ? "Geen boeken passen bij deze zoekopdracht en filters."
                    : "Boeken die aan deze bibliotheek zijn toegevoegd verschijnen hier.",
            })
        );
        if (model.query.search !== "") {
            empty.append(actionButton(documentImpl, "Zoekopdracht wissen", actions.clearSearch));
        }
        if (activeFilterCount(model.query) > 0) {
            empty.append(actionButton(documentImpl, "Alle filters wissen", actions.clearFilters));
        }
        view.append(empty);
        return view;
    }

    const heading = element(documentImpl, "h2", {
        className: "biblio-ui__visually-hidden",
        text: "Boeken",
    });
    const list = element(documentImpl, "ul", {
        className: "biblio-ui__catalog-list",
        attributes: {
            "aria-label": model.query.archiveScope === "active_and_archived"
                ? "Boeken en archiefboeken"
                : "Actieve boeken",
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
        const activeElement = documentImpl.activeElement;
        const focusKey = activeElement?.getAttribute?.("data-biblio-focus-key");
        const restoreSearchFocus = focusKey === "search";
        const selectionStart = restoreSearchFocus ? activeElement.selectionStart : null;
        const selectionEnd = restoreSearchFocus ? activeElement.selectionEnd : null;
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
        } else if (typeof focusKey === "string") {
            const focusTargets = view.querySelectorAll?.("[data-biblio-focus-key]") ?? [];
            let nextFocus = [...focusTargets].find((candidate) => (
                candidate.getAttribute("data-biblio-focus-key") === focusKey
            ));
            if (nextFocus === undefined && focusKey === "search-clear") {
                nextFocus = [...focusTargets].find((candidate) => (
                    candidate.getAttribute("data-biblio-focus-key") === "search"
                ));
            }
            if (nextFocus === undefined && (
                focusKey.startsWith("chip:") || focusKey === "clear-filters"
            )) {
                nextFocus = [...focusTargets].find((candidate) => (
                    candidate.getAttribute("data-biblio-focus-key") === "filter-toggle"
                ));
            }
            nextFocus?.focus?.({ preventScroll: true });
            if (
                restoreSearchFocus
                && Number.isInteger(selectionStart)
                && Number.isInteger(selectionEnd)
                && typeof nextFocus?.setSelectionRange === "function"
            ) {
                nextFocus.setSelectionRange(selectionStart, selectionEnd);
            }
        }
        return view;
    }

    uiState.render = render;
    return Object.freeze({ render });
}
