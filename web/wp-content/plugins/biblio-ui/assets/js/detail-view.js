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

function knownList(value) {
    return value?.state === "known" && Array.isArray(value.values)
        && value.values.length > 0
        ? value.values.join(", ")
        : null;
}

function classificationNames(terms) {
    return Array.isArray(terms) && terms.length > 0
        ? terms.map((term) => term.display_name).join(", ")
        : null;
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

function coverPresentation(documentImpl, detail) {
    const cover = knownText(detail.cover_reference);

    if (cover !== null) {
        return element(documentImpl, "img", {
            className: "biblio-ui__cover biblio-ui__cover--detail",
            attributes: {
                alt: `Omslag van ${detail.title}`,
                src: cover,
            },
        });
    }

    const placeholder = element(documentImpl, "span", {
        className: "biblio-ui__cover biblio-ui__cover--detail biblio-ui__cover--placeholder",
        attributes: {
            role: "img",
            "aria-label": `Geen omslag beschikbaar voor ${detail.title}`,
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

function readingStatusLabel(status) {
    const label = READING_STATUS_LABELS[status];

    if (label === undefined) {
        throw new TypeError("The Biblio Item reading status is invalid.");
    }

    return label;
}

function readingDateLabel(value) {
    if (value === null || value === undefined) {
        return null;
    }

    const months = [
        "januari", "februari", "maart", "april", "mei", "juni",
        "juli", "augustus", "september", "oktober", "november", "december",
    ];

    if (value.month === null) {
        return String(value.year);
    }

    if (value.day === null) {
        return `${months[value.month - 1]} ${value.year}`;
    }

    return `${value.day} ${months[value.month - 1]} ${value.year}`;
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

function metadataSection(documentImpl, id, heading, fields) {
    const knownFields = fields.filter(([, value]) => (
        typeof value === "string" && value.length > 0
    ));

    if (knownFields.length === 0) {
        return null;
    }

    const section = element(documentImpl, "section", {
        className: "biblio-ui__section biblio-ui__metadata-section",
        attributes: { id },
    });
    const list = element(documentImpl, "dl", {
        className: "biblio-ui__metadata",
    });
    section.append(element(documentImpl, "h2", { text: heading }));

    for (const [label, value] of knownFields) {
        definition(documentImpl, list, label, value);
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

function renderReading(documentImpl, reading, activeRound) {
    const section = element(documentImpl, "section", {
        className: "biblio-ui__section biblio-ui__reading biblio-ui__detail-section",
        attributes: { id: "overzicht" },
    });
    const list = element(documentImpl, "dl", {
        className: "biblio-ui__metadata",
    });
    const heading = element(documentImpl, "h2", { text: "Overzicht" });
    section.append(
        element(documentImpl, "p", {
            className: "biblio-ui__section-kicker",
            text: "Persoonlijk",
        }),
        heading,
        element(documentImpl, "p", {
            className: "biblio-ui__detail-empty-copy",
            text: "Voor dit boek is nog geen beschrijving beschikbaar.",
        })
    );
    const startedOn = readingDateLabel(activeRound?.started_on);
    if (startedOn !== null) {
        section.append(element(documentImpl, "p", {
            className: "biblio-ui__current-round",
            text: `Huidige leesronde · gestart op ${startedOn}`,
        }));
    }
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
    backLink.className = "biblio-ui__quiet-link biblio-ui__detail-back";
    view.append(backLink);
    const hero = element(documentImpl, "header", {
        className: "biblio-ui__detail-hero",
    });
    const identity = element(documentImpl, "div", {
        className: "biblio-ui__detail-identity",
    });
    identity.append(element(documentImpl, "p", {
        className: "biblio-ui__eyebrow",
        text: detail.library.name,
    }));
    identity.append(element(documentImpl, "h1", {
        className: "biblio-ui__page-title",
        text: detail.title,
    }));

    if (
        detail.authors.state === "known"
        && detail.authors.values.length > 0
    ) {
        identity.append(element(documentImpl, "p", {
            className: "biblio-ui__authors biblio-ui__detail-authors",
            text: detail.authors.values.join(", "),
        }));
    }

    const heroMeta = element(documentImpl, "div", {
        className: "biblio-ui__detail-hero-meta",
        attributes: { "aria-label": "Leesstatus en boeksoort", role: "group" },
    });
    heroMeta.append(element(documentImpl, "span", {
        className: `biblio-ui__status biblio-ui__status--${detail.reading.status}`,
        text: readingStatusLabel(detail.reading.status),
    }));
    const bookType = detail.classification.book_types[0] ?? null;
    if (bookType !== null) {
        heroMeta.append(element(documentImpl, "span", {
            className: "biblio-ui__detail-chip biblio-ui__detail-chip--classification",
            text: bookType.display_name,
        }));
    } else if (knownText(detail.form) === "physical_book") {
        heroMeta.append(element(documentImpl, "span", {
            className: "biblio-ui__detail-chip",
            text: "Boek",
        }));
    }
    identity.append(heroMeta);

    const heroActions = element(documentImpl, "div", {
        className: "biblio-ui__detail-actions",
    });

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
        heroActions.append(startButton);
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
        heroActions.append(endButton);
    }
    identity.append(heroActions);
    hero.append(coverPresentation(documentImpl, detail), identity);
    view.append(hero);

    const bookDetails = metadataSection(documentImpl, "boekdetails", "Boekdetails", [
        ["Auteur", knownList(detail.authors)],
        ["Serie", knownText(detail.series)],
        ["Genres", classificationNames(detail.classification.genres)],
        ["Onderwerpen", classificationNames(detail.classification.subjects)],
    ]);
    const editionDetails = metadataSection(documentImpl, "uitgave", "Uitgave", [
        ["Titel", detail.title],
        ["ISBN", knownText(detail.isbn)],
        ["Taal", knownText(detail.language)],
        ["Uitgever", knownText(detail.publisher)],
        ["Publicatiedatum", knownText(detail.publication_date)],
        ["Vorm", knownText(detail.form) === "physical_book" ? "Boek" : null],
    ]);
    const itemDetails = metadataSection(documentImpl, "exemplaar", "Exemplaar", [
        ["Bibliotheek", detail.library.name],
        ["Locatie", knownText(detail.location)],
        ["Conditie", knownText(detail.condition)],
        ["Verwerving", knownText(detail.acquisition)],
        ["Beschikbaarheid", knownText(detail.availability)],
    ]);

    const navItems = [
        ["Overzicht", "overzicht"],
        ["Leesgeschiedenis", "leesgeschiedenis"],
        ["Mijn notities", "mijn-notities"],
        ...(bookDetails === null ? [] : [["Boekdetails", "boekdetails"]]),
        ...(editionDetails === null ? [] : [["Uitgave", "uitgave"]]),
        ...(itemDetails === null ? [] : [["Exemplaar", "exemplaar"]]),
    ];
    const subnav = element(documentImpl, "nav", {
        className: "biblio-ui__detail-subnav",
        attributes: { "aria-label": "Boeksecties" },
    });
    const subnavList = element(documentImpl, "ul");
    for (const [label, id] of navItems) {
        const listItem = element(documentImpl, "li");
        listItem.append(element(documentImpl, "a", {
            text: label,
            attributes: { href: `#${id}` },
        }));
        subnavList.append(listItem);
    }
    subnav.append(subnavList);
    view.append(subnav);

    const body = element(documentImpl, "div", {
        className: "biblio-ui__detail-body",
    });
    const content = element(documentImpl, "div", {
        className: "biblio-ui__detail-content",
    });

    const reading = renderReading(
        documentImpl,
        detail.reading,
        detail.active_reading_round
    );
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
        reading.section.append(focusTarget);
    }

    content.append(element(documentImpl, "div", {
        className: "biblio-ui__history-region biblio-ui__detail-section",
        attributes: {
            "aria-busy": "false",
            "data-biblio-reading-history": "true",
            id: "leesgeschiedenis",
        },
    }));

    content.append(element(documentImpl, "div", {
        className: "biblio-ui__private-notes-region biblio-ui__detail-section",
        attributes: {
            "aria-busy": "false",
            "data-biblio-private-notes": "true",
            id: "mijn-notities",
        },
    }));
    const context = element(documentImpl, "aside", {
        className: "biblio-ui__detail-context",
        attributes: { "aria-label": "Boek- en exemplaargegevens" },
    });
    append(context, bookDetails, editionDetails, itemDetails);
    body.append(content, context);
    view.append(body);

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
