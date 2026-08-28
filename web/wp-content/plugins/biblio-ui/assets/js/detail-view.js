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

function shouldHandleNavigation(event) {
    return !event?.defaultPrevented
        && (event?.button === undefined || event.button === 0)
        && event?.metaKey !== true
        && event?.ctrlKey !== true
        && event?.shiftKey !== true
        && event?.altKey !== true;
}

function navigationLink(documentImpl, label, url, listener) {
    const link = element(documentImpl, "a", {
        text: label,
        attributes: { href: url },
    });

    link.addEventListener("click", (event) => {
        if (!shouldHandleNavigation(event)) {
            return;
        }

        event?.preventDefault();
        listener();
    });

    return link;
}

function definition(documentImpl, list, label, value) {
    append(
        list,
        element(documentImpl, "dt", { text: label }),
        element(documentImpl, "dd", { text: value })
    );
}

function metadataSection(documentImpl, heading, fields) {
    const knownFields = fields.filter(([, value]) => knownText(value) !== null);

    if (knownFields.length === 0) {
        return null;
    }

    const section = element(documentImpl, "section");
    const list = element(documentImpl, "dl");
    section.append(element(documentImpl, "h2", { text: heading }));

    for (const [label, value] of knownFields) {
        definition(documentImpl, list, label, knownText(value));
    }

    section.append(list);

    return section;
}

function renderLoading(documentImpl) {
    const view = element(documentImpl, "section", {
        attributes: {
            "aria-busy": "true",
            "aria-live": "polite",
            "data-biblio-view": "detail-loading",
        },
    });
    view.append(element(documentImpl, "h1", { text: "Boek laden" }));

    return view;
}

function renderUnavailable(documentImpl, model, actions) {
    const view = element(documentImpl, "section", {
        attributes: {
            "aria-busy": "false",
            "data-biblio-view": "item-unavailable",
        },
    });
    append(
        view,
        element(documentImpl, "h1", { text: "Boek niet beschikbaar" }),
        navigationLink(
            documentImpl,
            "Terug naar bibliotheek",
            model.backUrl,
            actions.backToOverview
        )
    );

    return view;
}

function renderReading(documentImpl, reading) {
    const section = element(documentImpl, "section");
    const list = element(documentImpl, "dl");
    section.append(element(documentImpl, "h2", { text: "Lezen" }));
    definition(
        documentImpl,
        list,
        "Leesstatus",
        readingStatusLabel(reading.status)
    );

    for (const [key, label] of [
        ["active_rounds", "Actieve leesrondes"],
        ["completed_rounds", "Uitgelezen leesrondes"],
        ["stopped_rounds", "Gestopte leesrondes"],
        ["historical_completed_rounds", "Waarvan historisch geregistreerd"],
    ]) {
        if (reading[key] > 0) {
            definition(documentImpl, list, label, String(reading[key]));
        }
    }

    section.append(list);

    return section;
}

function renderDetail(documentImpl, model, actions) {
    const detail = model.detail;
    const view = element(documentImpl, "article", {
        attributes: {
            "aria-busy": "false",
            "data-biblio-view": "detail",
        },
    });
    append(
        view,
        navigationLink(
            documentImpl,
            "Terug naar bibliotheek",
            model.backUrl,
            actions.backToOverview
        ),
        element(documentImpl, "p", {
            className: "biblio-ui__eyebrow",
            text: "Mijn Bibliotheek",
        })
    );

    const cover = knownText(detail.cover_reference);

    if (cover !== null) {
        view.append(element(documentImpl, "img", {
            attributes: {
                alt: `Omslag van ${detail.title}`,
                src: cover,
            },
        }));
    }

    view.append(element(documentImpl, "h1", { text: detail.title }));

    if (
        detail.authors.state === "known"
        && detail.authors.values.length > 0
    ) {
        view.append(element(documentImpl, "p", {
            className: "biblio-ui__authors",
            text: detail.authors.values.join(", "),
        }));
    }

    view.append(element(documentImpl, "p", {
        className: "biblio-ui__library",
        text: detail.library.name,
    }));

    if (knownText(detail.form) === "physical_book") {
        const summary = element(documentImpl, "dl", {
            className: "biblio-ui__summary",
        });
        definition(documentImpl, summary, "Vorm", "Boek");
        view.append(summary);
    }

    append(
        view,
        renderReading(documentImpl, detail.reading),
        metadataSection(documentImpl, "Uitgave", [
            ["ISBN", detail.isbn],
            ["Taal", detail.language],
            ["Uitgever", detail.publisher],
            ["Publicatiedatum", detail.publication_date],
            ["Serie", detail.series],
        ]),
        metadataSection(documentImpl, "Exemplaar", [
            ["Locatie", detail.location],
            ["Conditie", detail.condition],
            ["Verwerving", detail.acquisition],
            ["Beschikbaarheid", detail.availability],
        ])
    );

    return view;
}

export function createDetailView(root, {
    documentImpl = globalThis.document,
} = {}) {
    if (typeof root?.replaceChildren !== "function") {
        throw new TypeError("A Biblio UI mount element is required.");
    }

    if (typeof documentImpl?.createElement !== "function") {
        throw new TypeError("A browser Document implementation is required.");
    }

    function render(model, actions = {}) {
        let view;

        switch (model.state) {
        case "detail-loading":
            view = renderLoading(documentImpl);
            break;
        case "item-unavailable":
            view = renderUnavailable(documentImpl, model, actions);
            break;
        case "detail":
            view = renderDetail(documentImpl, model, actions);
            break;
        default:
            throw new TypeError("The Biblio detail view state is invalid.");
        }

        root.replaceChildren(view);
        return view;
    }

    return Object.freeze({ render });
}
