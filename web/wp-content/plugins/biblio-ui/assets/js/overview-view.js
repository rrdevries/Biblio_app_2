const READING_STATUS_LABELS = Object.freeze({
    not_read: "Niet gelezen",
    reading: "Aan het lezen",
    read: "Uitgelezen",
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

function actionButton(documentImpl, label, listener) {
    const button = element(documentImpl, "button", {
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
        attributes: {
            "aria-busy": busy ? "true" : "false",
            "data-biblio-view": state,
        },
    });
}

function appHeading(documentImpl, parent) {
    parent.append(element(documentImpl, "h1", { text: "Mijn Bibliotheek" }));
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

function itemCard(documentImpl, item, libraryId, itemUrl, actions) {
    const listItem = element(documentImpl, "li", {
        attributes: { "data-biblio-item-id": item.item_id },
    });
    const canView = item?.capabilities?.view_item === true;
    const content = canView
        ? element(documentImpl, "a", {
            attributes: { href: itemUrl(libraryId, item.item_id) },
        })
        : element(documentImpl, "div");
    const cover = knownText(item.cover_reference);

    if (canView) {
        content.addEventListener("click", (event) => {
            if (!shouldHandleNavigation(event)) {
                return;
            }

            event?.preventDefault();
            actions.openItem(item.item_id);
        });
    }

    if (cover !== null) {
        content.append(element(documentImpl, "img", {
            attributes: {
                alt: `Omslag van ${item.title}`,
                src: cover,
            },
        }));
    }

    content.append(element(documentImpl, "h3", { text: item.title }));

    if (
        item?.authors?.state === "known"
        && Array.isArray(item.authors.values)
        && item.authors.values.length > 0
    ) {
        content.append(element(documentImpl, "p", {
            className: "biblio-ui__authors",
            text: item.authors.values.join(", "),
        }));
    }

    content.append(element(documentImpl, "p", {
        className: "biblio-ui__context",
        text: contextLine(item),
    }));
    listItem.append(content);

    return listItem;
}

function renderLibraryLoading(documentImpl) {
    const view = page(documentImpl, "library-loading", true);
    view.setAttribute("aria-live", "polite");
    view.append(element(documentImpl, "h1", { text: "Bibliotheek laden" }));

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
    view.append(element(documentImpl, "h1", {
        text: "Geen bibliotheek beschikbaar",
    }));

    return view;
}

function renderLibraryUnavailable(documentImpl, overviewUrl) {
    const view = page(documentImpl, "library-unavailable");
    append(
        view,
        element(documentImpl, "h1", {
            text: "Bibliotheek niet beschikbaar",
        }),
        element(documentImpl, "a", {
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
            text: "Bibliotheek kon niet worden geladen",
        }),
        actionButton(documentImpl, "Opnieuw proberen", actions.retry)
    );

    return view;
}

function renderChooser(documentImpl, model, actions) {
    const view = page(documentImpl, "library-chooser");
    appHeading(documentImpl, view);
    view.append(element(documentImpl, "h2", { text: "Kies een bibliotheek" }));
    const list = element(documentImpl, "ul");

    for (const library of model.libraries) {
        const listItem = element(documentImpl, "li");
        const button = actionButton(
            documentImpl,
            library.name,
            () => actions.selectLibrary(library.library_id)
        );
        append(
            listItem,
            button,
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
            attributes: { "data-biblio-load-more-error": "true" },
        });
        error.append(element(documentImpl, "h3", {
            text: "Meer boeken konden niet worden geladen",
        }));

        if (model.canRetryCursor === true) {
            error.append(actionButton(
                documentImpl,
                "Opnieuw proberen",
                actions.retryLoadMore
            ));
        }

        error.append(actionButton(
            documentImpl,
            "Vanaf het begin",
            actions.restart
        ));

        return error;
    }

    if (model.nextCursor === null) {
        return null;
    }

    const button = actionButton(documentImpl, "Meer laden", actions.loadMore);
    button.disabled = model.loadingMore === true;

    if (model.loadingMore === true) {
        button.setAttribute("aria-busy", "true");
    }

    return button;
}

function renderOverview(documentImpl, model, actions, itemUrl) {
    const view = page(documentImpl, "overview", model.loadingMore === true);
    appHeading(documentImpl, view);
    view.append(element(documentImpl, "p", { text: model.library.name }));

    if (model.items.length === 0) {
        view.append(element(documentImpl, "h2", {
            text: "Nog geen actieve boeken",
        }));
        return view;
    }

    view.append(element(documentImpl, "h2", { text: "Boeken" }));
    const list = element(documentImpl, "ul", {
        attributes: { "aria-label": "Actieve boeken" },
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

    view.append(list);
    const loadMore = renderLoadMore(documentImpl, model, actions);

    if (loadMore !== null) {
        view.append(loadMore);
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
            view = renderOverview(documentImpl, model, actions, itemUrl);
            break;
        default:
            throw new TypeError("The Biblio overview view state is invalid.");
        }

        root.replaceChildren(view);
        return view;
    }

    return Object.freeze({ render });
}
