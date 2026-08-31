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

    const section = element(documentImpl, "section", {
        className: "biblio-ui__section",
    });
    const list = element(documentImpl, "dl", {
        className: "biblio-ui__metadata",
    });
    section.append(element(documentImpl, "h2", { text: heading }));

    for (const [label, value] of knownFields) {
        definition(documentImpl, list, label, knownText(value));
    }

    section.append(list);

    return section;
}

function renderLoading(documentImpl) {
    const view = element(documentImpl, "section", {
        className: "biblio-ui__view",
        attributes: {
            "aria-busy": "true",
            "aria-live": "polite",
            "data-biblio-view": "detail-loading",
        },
    });
    view.append(element(documentImpl, "h1", {
        className: "biblio-ui__page-title",
        text: "Boek laden",
    }));

    return view;
}

function renderUnavailable(documentImpl, model, actions) {
    const view = element(documentImpl, "section", {
        className: "biblio-ui__view",
        attributes: {
            "aria-busy": "false",
            "data-biblio-view": "item-unavailable",
        },
    });
    append(
        view,
        element(documentImpl, "h1", {
            className: "biblio-ui__page-title",
            text: "Boek niet beschikbaar",
        }),
        element(documentImpl, "p", {
            text: "Dit boek bestaat niet of is niet meer toegankelijk.",
            attributes: { role: "alert" },
        }),
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
    const section = element(documentImpl, "section", {
        className: "biblio-ui__section biblio-ui__reading",
    });
    const list = element(documentImpl, "dl", {
        className: "biblio-ui__metadata",
    });
    const heading = element(documentImpl, "h2", { text: "Lezen" });
    section.append(heading);
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

    return { section, heading };
}

function renderDetail(documentImpl, model, actions) {
    const detail = model.detail;
    const view = element(documentImpl, "article", {
        className: "biblio-ui__view biblio-ui__detail",
        attributes: {
            "aria-busy": "false",
            "data-biblio-view": "detail",
        },
    });
    const backLink = navigationLink(
        documentImpl,
        "Terug naar bibliotheek",
        model.backUrl,
        actions.backToOverview
    );
    backLink.className = "biblio-ui__quiet-link";
    view.append(backLink);
    append(
        view,
        element(documentImpl, "p", {
            className: "biblio-ui__eyebrow",
            text: "Mijn Bibliotheek",
        })
    );

    const layout = element(documentImpl, "div", {
        className: "biblio-ui__detail-layout",
    });
    const content = element(documentImpl, "div", {
        className: "biblio-ui__detail-content",
    });
    const cover = knownText(detail.cover_reference);

    if (cover !== null) {
        layout.append(element(documentImpl, "img", {
            className: "biblio-ui__cover biblio-ui__cover--detail",
            attributes: {
                alt: `Omslag van ${detail.title}`,
                src: cover,
            },
        }));
    }

    content.append(element(documentImpl, "h1", {
        className: "biblio-ui__page-title",
        text: detail.title,
    }));

    if (
        detail.authors.state === "known"
        && detail.authors.values.length > 0
    ) {
        content.append(element(documentImpl, "p", {
            className: "biblio-ui__authors",
            text: detail.authors.values.join(", "),
        }));
    }

    content.append(element(documentImpl, "p", {
        className: "biblio-ui__library",
        text: detail.library.name,
    }));

    if (knownText(detail.form) === "physical_book") {
        const summary = element(documentImpl, "dl", {
            className: "biblio-ui__summary",
        });
        definition(documentImpl, summary, "Vorm", "Boek");
        content.append(summary);
    }

    const reading = renderReading(documentImpl, detail.reading);
    content.append(reading.section);
    let focusTarget = reading.heading;

    if (typeof model.notice === "string" && model.notice.length > 0) {
        focusTarget = element(documentImpl, "p", {
            text: model.notice,
            attributes: {
                "aria-live": "polite",
                role: "status",
                tabindex: "-1",
            },
        });
        content.append(focusTarget);
    }

    if (detail.capabilities.start_reading === true) {
        const startButton = element(documentImpl, "button", {
            className: "biblio-ui__control biblio-ui__control--primary biblio-ui__start-reading",
            text: "Lezen starten",
            attributes: { type: "button" },
        });
        startButton.addEventListener(
            "click",
            () => actions.startReading(startButton)
        );
        content.append(startButton);
    }

    if (
        detail.capabilities.end_reading === true
        && detail.active_reading_round !== null
        && detail.active_reading_round !== undefined
    ) {
        const endButton = element(documentImpl, "button", {
            className: "biblio-ui__control biblio-ui__control--secondary biblio-ui__end-reading",
            text: "Leesronde afronden",
            attributes: { type: "button" },
        });
        endButton.addEventListener(
            "click",
            () => actions.endReading(endButton)
        );
        content.append(endButton);
    }

    content.append(element(documentImpl, "div", {
        className: "biblio-ui__history-region",
        attributes: {
            "aria-busy": "false",
            "data-biblio-reading-history": "true",
        },
    }));

    append(
        content,
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
    layout.append(content);
    view.append(layout);

    return { focusTarget, view };
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
        let focusTarget = null;
        let view;

        switch (model.state) {
        case "detail-loading":
            view = renderLoading(documentImpl);
            break;
        case "item-unavailable":
            view = renderUnavailable(documentImpl, model, actions);
            break;
        case "detail":
            ({ focusTarget, view } = renderDetail(
                documentImpl,
                model,
                actions
            ));
            break;
        default:
            throw new TypeError("The Biblio detail view state is invalid.");
        }

        root.replaceChildren(view);
        root.setAttribute("aria-busy", view.getAttribute("aria-busy"));

        if (model.focusReading === true) {
            focusTarget.setAttribute("tabindex", "-1");
            focusTarget.focus();
        }

        if (model.focusHeading === true) {
            const heading = view.querySelector("h1");
            heading?.setAttribute("tabindex", "-1");
            heading?.focus();
        }

        return view;
    }

    return Object.freeze({ render });
}
