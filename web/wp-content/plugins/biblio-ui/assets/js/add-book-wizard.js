import {
    buildAddBookCommitBody,
    normalizeIsbn,
    readAddBookCommit,
    readAddBookLookup,
    readAddBookWorkPage,
    readClassificationOptions,
} from "./add-book-contracts.js";
import { createIsbnScanner } from "./isbn-scanner.js";

const SNAPSHOT_UNAVAILABLE = "biblio_metadata_lookup_snapshot_unavailable";

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

function button(documentImpl, label, listener, modifier = "secondary") {
    const control = element(documentImpl, "button", {
        className: `biblio-ui__control biblio-ui__control--${modifier}`,
        text: label,
        attributes: { type: "button" },
    });
    control.addEventListener("click", listener);
    return control;
}

function page(documentImpl, step, busy = false) {
    return element(documentImpl, "section", {
        className: "biblio-ui__view biblio-ui__add-book",
        attributes: {
            "data-biblio-view": "add-book",
            "data-add-book-step": step,
            "aria-busy": busy ? "true" : "false",
        },
    });
}

function heading(documentImpl, title, eyebrow = "Boek toevoegen") {
    const header = element(documentImpl, "header", {
        className: "biblio-ui__guided-header",
    });
    header.append(
        element(documentImpl, "p", {
            className: "biblio-ui__eyebrow",
            text: eyebrow,
        }),
        element(documentImpl, "h1", {
            className: "biblio-ui__page-title",
            text: title,
        })
    );
    return header;
}

function actions(documentImpl, ...controls) {
    const row = element(documentImpl, "div", {
        className: "biblio-ui__guided-actions",
    });
    row.append(...controls.filter(Boolean));
    return row;
}

function field(documentImpl, {
    id,
    label,
    name = id,
    type = "text",
    value = "",
    help = null,
    required = false,
    inputMode = null,
} = {}) {
    const wrapper = element(documentImpl, "div", {
        className: "biblio-ui__field",
    });
    const labelNode = element(documentImpl, "label", {
        text: label,
        attributes: { for: id },
    });
    const attributes = {
        id,
        name,
        type,
        value,
    };
    if (required) {
        attributes.required = "required";
        attributes["aria-required"] = "true";
    }
    if (inputMode !== null) {
        attributes.inputmode = inputMode;
    }
    if (help !== null) {
        attributes["aria-describedby"] = `${id}-help`;
    }
    const input = element(documentImpl, "input", { attributes });
    wrapper.append(labelNode, input);
    if (help !== null) {
        wrapper.append(element(documentImpl, "p", {
            className: "biblio-ui__field-help",
            text: help,
            attributes: { id: `${id}-help` },
        }));
    }
    return wrapper;
}

function textAreaField(documentImpl, { id, label, value = "", help = null }) {
    const wrapper = element(documentImpl, "div", {
        className: "biblio-ui__field",
    });
    const area = element(documentImpl, "textarea", {
        attributes: { id, name: id, rows: "2" },
    });
    area.value = value;
    wrapper.append(element(documentImpl, "label", {
        text: label,
        attributes: { for: id },
    }), area);
    if (help !== null) {
        area.setAttribute("aria-describedby", `${id}-help`);
        wrapper.append(element(documentImpl, "p", {
            className: "biblio-ui__field-help",
            text: help,
            attributes: { id: `${id}-help` },
        }));
    }
    return wrapper;
}

function submitForm(form, listener) {
    form.addEventListener("submit", (event) => {
        event.preventDefault();
        listener(new FormData(form));
    });
    return form;
}

function listText(values) {
    return Array.isArray(values) && values.length > 0
        ? values.join(", ")
        : null;
}

function addFact(documentImpl, list, label, value, differing = false) {
    if (value === null || value === undefined || value === "") {
        return;
    }
    const row = element(documentImpl, "div", {
        className: `biblio-ui__edition-fact${differing ? " biblio-ui__edition-fact--different" : ""}`,
    });
    row.append(
        element(documentImpl, "dt", { text: label }),
        element(documentImpl, "dd", { text: String(value) })
    );
    list.append(row);
}

function editionCard(documentImpl, edition, { differences = new Set() } = {}) {
    const card = element(documentImpl, "article", {
        className: "biblio-ui__edition-card",
    });
    const title = edition.edition_title ?? edition.fields?.title;
    card.append(element(documentImpl, "h2", {
        className: "biblio-ui__edition-title",
        text: title,
    }));
    const facts = element(documentImpl, "dl", {
        className: "biblio-ui__edition-facts",
    });
    const fields = edition.fields ?? {};
    addFact(documentImpl, facts, "Ondertitel", fields.subtitle, differences.has("subtitle"));
    addFact(documentImpl, facts, "Auteur(s)", edition.authors
        ? listText(edition.authors.map((author) => author.display_name))
        : listText(fields.contributors), differences.has("contributors"));
    addFact(documentImpl, facts, "Taal", listText(fields.languages), differences.has("languages"));
    addFact(documentImpl, facts, "Uitgever", listText(fields.publishers), differences.has("publishers"));
    addFact(documentImpl, facts, "Publicatie", fields.publication_date, differences.has("publication_date"));
    addFact(documentImpl, facts, "ISBN", edition.canonical_isbn
        ?? edition.identifier?.isbn_13, differences.has("identifier"));
    addFact(documentImpl, facts, "Pagina's", fields.page_count, differences.has("page_count"));
    addFact(documentImpl, facts, "Bindwijze", fields.format, differences.has("format"));
    card.append(facts);
    return card;
}

function localItemContext(documentImpl, edition) {
    if (edition.existing_item_count === 0) {
        return null;
    }
    const section = element(documentImpl, "section", {
        className: "biblio-ui__existing-copy",
    });
    section.append(element(documentImpl, "p", {
        className: "biblio-ui__status-line",
        text: `Deze uitgave staat al ${edition.existing_item_count}× in je Bibliotheek.`,
    }));
    const recognizable = edition.existing_items.filter((item) => (
        item.inventory_number !== null || item.location !== null
    ));
    if (recognizable.length > 0) {
        const list = element(documentImpl, "ul", {
            className: "biblio-ui__compact-list",
        });
        for (const item of recognizable) {
            const parts = [];
            if (item.inventory_number !== null) {
                parts.push(`Inventaris ${item.inventory_number}`);
            }
            if (item.location !== null) {
                parts.push(item.location.display_name);
            }
            list.append(element(documentImpl, "li", { text: parts.join(" · ") }));
        }
        section.append(list);
    }
    return section;
}

function candidateDifferences(candidates) {
    const fields = [
        "subtitle",
        "contributors",
        "languages",
        "publishers",
        "publication_date",
        "page_count",
        "format",
    ];
    const differences = new Set();
    for (const fieldName of fields) {
        const values = candidates.map((candidate) => JSON.stringify(
            candidate.fields[fieldName]
        ));
        if (new Set(values).size > 1) {
            differences.add(fieldName);
        }
    }
    if (new Set(candidates.map((candidate) => candidate.identifier.isbn_13)).size > 1) {
        differences.add("identifier");
    }
    return differences;
}

function csv(value) {
    return typeof value === "string"
        ? value.split(",").map((entry) => entry.trim()).filter(Boolean)
        : [];
}

function initialDraft(identifier = "") {
    return {
        isbn: identifier,
        title: "",
        subtitle: "",
        contributors: [],
        languages: [],
        publishers: [],
        publication_date: "",
        edition_statement: "",
        format: "",
        page_count: null,
    };
}

function candidateDraft(candidate) {
    return {
        isbn: candidate.identifier.isbn_13,
        title: candidate.fields.title,
        subtitle: candidate.fields.subtitle ?? "",
        contributors: [...candidate.fields.contributors],
        languages: [...candidate.fields.languages],
        publishers: [...candidate.fields.publishers],
        publication_date: candidate.fields.publication_date ?? "",
        edition_statement: "",
        format: candidate.fields.format ?? "",
        page_count: candidatePageCount(candidate.fields.page_count),
    };
}

function candidatePageCount(value) {
    return Number.isInteger(value) ? value : null;
}

function localCorrectionDraft(edition) {
    return {
        ...initialDraft(edition.canonical_isbn ?? ""),
        title: edition.edition_title,
    };
}

function statusLabel(status) {
    return status === "librarian_confirmed"
        ? "Bibliografisch bevestigd"
        : "Voorlopige werktitel";
}

function seriesLine(series) {
    return series.map((context) => context.position === null
        ? context.display_name
        : `${context.display_name} · ${context.position}`).join(", ");
}

function isSnapshotUnavailable(error) {
    return error?.status === 409 && error?.code === SNAPSHOT_UNAVAILABLE;
}

export function createAddBookWizard(root, {
    api,
    documentImpl = globalThis.document,
    scannerFactory = createIsbnScanner,
    abortControllerFactory = () => new AbortController(),
} = {}) {
    if (typeof root?.replaceChildren !== "function") {
        throw new TypeError("An Add Book mount element is required.");
    }
    if (typeof api?.get !== "function" || typeof api?.post !== "function") {
        throw new TypeError("The Add Book Wizard requires the Biblio API.");
    }

    const scanner = scannerFactory();
    let state = null;
    let requestController = null;
    let revision = 0;
    let opener = null;
    let callbacks = null;

    function isCurrent(currentRevision, controller) {
        return revision === currentRevision
            && requestController === controller
            && !controller.signal.aborted;
    }

    function liveRegion(message = "") {
        return element(documentImpl, "p", {
            className: "biblio-ui__visually-hidden",
            text: message,
            attributes: {
                role: "status",
                "aria-live": "polite",
                "aria-atomic": "true",
            },
        });
    }

    function render({ focus = true } = {}) {
        if (state === null) {
            return;
        }
        let view;
        switch (state.step) {
        case "loading":
            view = renderLoading();
            break;
        case "start":
            view = renderStart();
            break;
        case "scanning":
            view = renderScanning();
            break;
        case "lookup":
            view = renderLookup();
            break;
        case "existing":
            view = renderExisting();
            break;
        case "ambiguous":
            view = renderAmbiguous();
            break;
        case "identification-problem":
            view = renderIdentificationProblem();
            break;
        case "candidate":
            view = renderCandidate();
            break;
        case "multiple":
            view = renderMultiple();
            break;
        case "miss":
            view = renderMiss();
            break;
        case "provider-failure":
            view = renderProviderFailure();
            break;
        case "edition-form":
            view = renderEditionForm();
            break;
        case "work-search":
            view = renderWorkSearch();
            break;
        case "item":
            view = renderItem();
            break;
        case "summary":
            view = renderSummary();
            break;
        case "committing":
            view = renderCommitting();
            break;
        case "success":
            view = renderSuccess();
            break;
        case "error":
            view = renderError();
            break;
        default:
            throw new TypeError("The Add Book Wizard state is invalid.");
        }
        view.prepend(liveRegion(state.notice));
        root.replaceChildren(view);
        root.setAttribute?.("aria-busy", view.getAttribute("aria-busy"));
        if (focus) {
            const title = view.querySelector?.("h1");
            title?.setAttribute("tabindex", "-1");
            title?.focus?.();
        }
        if (state.step === "scanning") {
            const video = view.querySelector?.("video");
            void scanner.start(video, {
                onDetected(isbn) {
                    state.rawIsbn = isbn;
                    void lookup(isbn);
                },
                onFailure() {
                    state.step = "start";
                    state.notice = "Camera scannen is hier niet beschikbaar. Voer het ISBN handmatig in.";
                    render();
                },
            });
        }
    }

    function cancel() {
        destroyRequest();
        scanner.stop();
        const returnFocus = opener;
        state = null;
        callbacks?.onClose?.();
        returnFocus?.focus?.();
    }

    function destroyRequest() {
        revision += 1;
        requestController?.abort();
        requestController = null;
    }

    async function open({ library, trigger, onClose, onOpenItem }) {
        destroyRequest();
        scanner.stop();
        opener = trigger ?? null;
        callbacks = { onClose, onOpenItem };
        state = {
            step: "loading",
            notice: "Classificatie laden.",
            library,
            options: null,
            rawIsbn: "",
            identifier: null,
            lookup: null,
            selection: null,
            selectedEdition: null,
            selectedCandidate: null,
            editionMode: null,
            observedFields: {},
            editionDraft: initialDraft(),
            selectedWork: null,
            workSearch: { query: "", items: [], nextCursor: null, pending: false, error: false },
            classification: { bookTypeId: "", genreIds: [], subjectIds: [] },
            inventoryNumber: "",
            summaryRequired: false,
            extraCopyAcknowledged: false,
            problemBlocked: false,
            commitResult: null,
            errorMessage: "",
        };
        render();
        const currentRevision = revision + 1;
        revision = currentRevision;
        const controller = abortControllerFactory();
        requestController = controller;
        try {
            const response = await api.get(
                `libraries/${encodeURIComponent(library.library_id)}/classification-options`,
                { signal: controller.signal }
            );
            if (!isCurrent(currentRevision, controller)) {
                return;
            }
            state.options = readClassificationOptions(response, library.library_id);
            state.step = "start";
            state.notice = "Boek toevoegen is gereed.";
            render();
        } catch (error) {
            if (!isCurrent(currentRevision, controller)) {
                return;
            }
            state.step = "error";
            state.errorMessage = "Boek toevoegen kon niet worden gestart.";
            state.notice = state.errorMessage;
            render();
        }
    }

    async function lookup(identifier, { recovery = false } = {}) {
        scanner.stop();
        const canonical = normalizeIsbn(identifier);
        if (canonical === null) {
            state.step = "start";
            state.notice = "Controleer het ISBN. Gebruik een geldig ISBN-10 of ISBN-13.";
            render();
            return;
        }
        destroyRequest();
        state.rawIsbn = identifier;
        state.identifier = canonical;
        state.lookup = null;
        state.selection = null;
        state.selectedEdition = null;
        state.selectedCandidate = null;
        state.step = "lookup";
        state.notice = recovery
            ? "De boekgegevens worden opnieuw opgehaald voor controle."
            : "Boekgegevens ophalen.";
        render();
        const currentRevision = revision + 1;
        revision = currentRevision;
        const controller = abortControllerFactory();
        requestController = controller;
        try {
            const response = await api.post(
                `libraries/${encodeURIComponent(state.library.library_id)}/metadata-lookups`,
                { identifier: canonical },
                { signal: controller.signal }
            );
            if (!isCurrent(currentRevision, controller)) {
                return;
            }
            state.lookup = readAddBookLookup(response, state.library.library_id);
            state.identifier = state.lookup.identifier.isbn_13;
            state.rawIsbn = state.lookup.identifier.isbn_13;
            state.notice = recovery
                ? "Controleer de opnieuw opgehaalde boekgegevens."
                : "Boekgegevens geladen.";
            state.step = ({
                existing_edition: "existing",
                local_ambiguous: "ambiguous",
                single_candidate: "candidate",
                multiple_candidates: "multiple",
                no_usable_candidate: "miss",
                provider_failure: "provider-failure",
            })[state.lookup.status];
            render();
        } catch (error) {
            if (!isCurrent(currentRevision, controller)) {
                return;
            }
            state.step = "error";
            state.errorMessage = error?.status === 422
                ? "Dit ISBN kon niet worden verwerkt. Controleer het nummer."
                : "Boekgegevens konden tijdelijk niet worden opgehaald.";
            state.notice = state.errorMessage;
            render();
        }
    }

    function chooseExisting(edition, corrected = false) {
        state.selectedEdition = edition;
        state.selection = { type: "existing_edition", edition_id: edition.edition_id };
        state.summaryRequired = corrected;
        state.observedFields = corrected ? localCorrectionDraft(edition) : {};
        if (edition.existing_item_count > 0 && !state.extraCopyAcknowledged) {
            state.step = "existing";
            state.notice = "Bevestig dat je nog een exemplaar wilt toevoegen.";
            render();
            return;
        }
        state.step = "item";
        state.notice = "Vul de exemplaargegevens in.";
        render();
    }

    function chooseCandidate(candidate, adjusted = false) {
        state.selectedCandidate = candidate;
        state.selection = {
            type: "candidate",
            lookup_id: state.lookup.lookup_id,
            candidate_id: candidate.candidate_id,
        };
        state.summaryRequired = true;
        state.observedFields = {};
        if (adjusted) {
            state.editionMode = "candidate-adjust";
            state.editionDraft = candidateDraft(candidate);
            state.step = "edition-form";
            state.notice = "Pas alleen gegevens aan die je op het boek controleert.";
        } else {
            state.step = "item";
            state.notice = "Vul de exemplaargegevens in.";
        }
        render();
    }

    function startManual() {
        state.selection = { type: "manual" };
        state.selectedEdition = null;
        state.selectedCandidate = null;
        state.selectedWork = null;
        state.editionMode = "manual";
        state.summaryRequired = true;
        state.editionDraft = initialDraft(state.identifier ?? "");
        state.observedFields = {};
        state.step = "edition-form";
        state.notice = "Voer de uitgavegegevens in.";
        render();
    }

    function readEditionForm(data) {
        const isbn = String(data.get("isbn") ?? "").trim();
        const title = String(data.get("title") ?? "").trim();
        const pageCountText = String(data.get("page_count") ?? "").trim();
        const pageCount = pageCountText === "" ? null : Number(pageCountText);
        if (title === "") {
            state.notice = "Vul de titel van deze uitgave in.";
            render({ focus: false });
            root.querySelector?.("#add-book-title")?.focus?.();
            return null;
        }
        if (isbn !== "") {
            const canonical = normalizeIsbn(isbn);
            if (canonical === null) {
                state.notice = "Controleer het ISBN. Gebruik een geldig ISBN-10 of ISBN-13.";
                render({ focus: false });
                root.querySelector?.("#add-book-edition-isbn")?.focus?.();
                return null;
            }
            if (state.identifier === null || canonical !== state.identifier) {
                state.notice = "Controleer een ander ISBN eerst opnieuw via ISBN invoeren.";
                render({ focus: false });
                root.querySelector?.("#add-book-edition-isbn")?.focus?.();
                return null;
            }
        }
        if (pageCount !== null && (!Number.isInteger(pageCount) || pageCount < 1)) {
            state.notice = "Vul een geldig aantal pagina's in.";
            render({ focus: false });
            root.querySelector?.("#add-book-page-count")?.focus?.();
            return null;
        }
        return {
            isbn: isbn === "" ? "" : state.identifier,
            title,
            subtitle: String(data.get("subtitle") ?? "").trim(),
            contributors: csv(data.get("contributors")),
            languages: csv(data.get("languages")),
            publishers: csv(data.get("publishers")),
            publication_date: String(data.get("publication_date") ?? "").trim(),
            edition_statement: String(data.get("edition_statement") ?? "").trim(),
            format: String(data.get("format") ?? "").trim(),
            page_count: pageCount,
        };
    }

    function continueEdition(data) {
        const draft = readEditionForm(data);
        if (draft === null) {
            return;
        }
        state.editionDraft = draft;
        state.observedFields = { ...draft };
        if (state.identifier === null) {
            delete state.observedFields.isbn;
        }
        if (state.editionMode === "manual") {
            state.selection = state.selectedWork === null
                ? { type: "manual" }
                : { type: "manual", work_id: state.selectedWork.work_id };
        }
        if (
            state.editionMode === "existing-correction"
            && state.selectedEdition.existing_item_count > 0
            && !state.extraCopyAcknowledged
        ) {
            state.step = "existing";
            state.notice = "Bevestig dat je nog een exemplaar wilt toevoegen.";
            render();
            return;
        }
        state.step = "item";
        state.notice = "Vul de exemplaargegevens in.";
        render();
    }

    function beginWorkSearch() {
        state.step = "work-search";
        state.notice = "Zoek een bestaand werk op titel of auteur.";
        render();
    }

    async function searchWorks(query, cursor = null) {
        const normalized = query.trim();
        if (normalized.length < 1 || normalized.length > 100) {
            state.workSearch.error = true;
            state.notice = "Vul 1 tot 100 tekens in om werken te zoeken.";
            render({ focus: false });
            return;
        }
        destroyRequest();
        state.workSearch.pending = true;
        state.workSearch.error = false;
        state.workSearch.query = normalized;
        state.notice = "Werken zoeken.";
        render({ focus: false });
        const currentRevision = revision + 1;
        revision = currentRevision;
        const controller = abortControllerFactory();
        requestController = controller;
        const path = `me/works?q=${encodeURIComponent(normalized)}&limit=10`
            + (cursor === null ? "" : `&cursor=${encodeURIComponent(cursor)}`);
        try {
            const response = await api.get(path, { signal: controller.signal });
            if (!isCurrent(currentRevision, controller)) {
                return;
            }
            const result = readAddBookWorkPage(response);
            state.workSearch.items = cursor === null
                ? [...result.items]
                : [...state.workSearch.items, ...result.items];
            state.workSearch.nextCursor = result.next_cursor;
            state.workSearch.pending = false;
            state.notice = result.items.length === 0 && cursor === null
                ? "Geen passende werken gevonden."
                : "Zoekresultaten geladen.";
            render({ focus: false });
        } catch {
            if (!isCurrent(currentRevision, controller)) {
                return;
            }
            state.workSearch.pending = false;
            state.workSearch.error = true;
            state.notice = "Werken konden tijdelijk niet worden gezocht.";
            render({ focus: false });
        }
    }

    function readSelections(form, name) {
        return [...form.querySelectorAll(`input[name="${name}"]:checked`)]
            .map((input) => input.value);
    }

    function continueItem(data, form) {
        const bookTypeId = String(data.get("book_type_id") ?? "");
        if (bookTypeId === "") {
            state.notice = "Kies een boektype.";
            render({ focus: false });
            root.querySelector?.("#add-book-book-type")?.focus?.();
            return;
        }
        state.classification = {
            bookTypeId,
            genreIds: readSelections(form, "genre_ids"),
            subjectIds: readSelections(form, "subject_ids"),
        };
        state.inventoryNumber = String(data.get("inventory_number") ?? "").trim();
        if (state.summaryRequired) {
            state.step = "summary";
            state.notice = "Controleer de gegevens voor je het boek toevoegt.";
            render();
            return;
        }
        void commit();
    }

    async function commit() {
        if (state.step === "committing") {
            return;
        }
        destroyRequest();
        state.step = "committing";
        state.notice = "Boek toevoegen.";
        render();
        const currentRevision = revision + 1;
        revision = currentRevision;
        const controller = abortControllerFactory();
        requestController = controller;
        try {
            const body = buildAddBookCommitBody({
                identifier: state.identifier,
                selection: state.selection,
                observedFields: state.observedFields,
                classification: state.classification,
                inventoryNumber: state.inventoryNumber,
            });
            const response = await api.post(
                `libraries/${encodeURIComponent(state.library.library_id)}/items`,
                body,
                { signal: controller.signal }
            );
            if (!isCurrent(currentRevision, controller)) {
                return;
            }
            state.commitResult = readAddBookCommit(response);
            state.step = "success";
            state.notice = "Boek toegevoegd.";
            render();
        } catch (error) {
            if (!isCurrent(currentRevision, controller)) {
                return;
            }
            if (isSnapshotUnavailable(error) && state.identifier !== null) {
                state.lookup = null;
                state.selection = null;
                state.selectedCandidate = null;
                await lookup(state.identifier, { recovery: true });
                return;
            }
            state.step = "error";
            state.errorMessage = error?.status === 422
                ? "Controleer de ingevulde gegevens. Het boek is niet toegevoegd."
                : "Het boek kon niet worden toegevoegd. Probeer het opnieuw.";
            state.notice = state.errorMessage;
            render();
        }
    }

    function resetForAnother(notice = "Klaar om nog een boek toe te voegen.") {
        scanner.stop();
        const { library, options } = state;
        state = {
            step: "start",
            notice,
            library,
            options,
            rawIsbn: "",
            identifier: null,
            lookup: null,
            selection: null,
            selectedEdition: null,
            selectedCandidate: null,
            editionMode: null,
            observedFields: {},
            editionDraft: initialDraft(),
            selectedWork: null,
            workSearch: { query: "", items: [], nextCursor: null, pending: false, error: false },
            classification: { bookTypeId: "", genreIds: [], subjectIds: [] },
            inventoryNumber: "",
            summaryRequired: false,
            extraCopyAcknowledged: false,
            problemBlocked: false,
            commitResult: null,
            errorMessage: "",
        };
        render();
    }

    function renderLoading() {
        const view = page(documentImpl, "loading", true);
        view.append(heading(documentImpl, "Boek toevoegen"), element(documentImpl, "p", {
            text: "De invoer wordt voorbereid…",
        }));
        return view;
    }

    function renderStart() {
        const view = page(documentImpl, "start");
        view.append(heading(documentImpl, "Hoe wil je beginnen?"));
        const startActions = actions(
            documentImpl,
            button(documentImpl, "Scan ISBN", () => {
                if (!scanner.supported()) {
                    state.notice = "Camera scannen is hier niet beschikbaar. Voer het ISBN handmatig in.";
                    render({ focus: false });
                    root.querySelector?.("#add-book-isbn")?.focus?.();
                    return;
                }
                state.step = "scanning";
                state.notice = "Richt de camera op de ISBN-barcode.";
                render();
            }, "primary"),
            button(documentImpl, "Geen ISBN", startManual),
            button(documentImpl, "Annuleren", cancel, "tertiary")
        );
        view.append(startActions);
        const form = element(documentImpl, "form", {
            className: "biblio-ui__guided-form",
            attributes: { novalidate: "novalidate" },
        });
        form.append(
            field(documentImpl, {
                id: "add-book-isbn",
                label: "ISBN invoeren",
                value: state.rawIsbn,
                inputMode: "numeric",
                required: true,
                help: "Typ, plak of scan een ISBN-10 of ISBN-13.",
            }),
            actions(documentImpl, button(documentImpl, "Boekgegevens zoeken", () => {
                form.requestSubmit?.();
            }, "primary"))
        );
        submitForm(form, (data) => void lookup(String(data.get("add-book-isbn") ?? "")));
        view.append(form);
        return view;
    }

    function renderScanning() {
        const view = page(documentImpl, "scanning", true);
        view.append(
            heading(documentImpl, "Scan de ISBN-barcode"),
            element(documentImpl, "p", {
                text: "Richt de achterkant van het boek rustig op de camera.",
            }),
            element(documentImpl, "video", {
                className: "biblio-ui__scanner-preview",
                attributes: { "aria-label": "Camerabeeld voor ISBN-scan" },
            }),
            actions(documentImpl, button(documentImpl, "ISBN handmatig invoeren", () => {
                scanner.stop();
                state.step = "start";
                state.notice = "Voer het ISBN handmatig in.";
                render();
            }, "primary"), button(documentImpl, "Annuleren", cancel, "tertiary"))
        );
        return view;
    }

    function renderLookup() {
        const view = page(documentImpl, "lookup", true);
        view.append(heading(documentImpl, "Boekgegevens zoeken"), element(documentImpl, "p", {
            text: "We controleren eerst of deze uitgave al bij Biblio bekend is…",
        }));
        return view;
    }

    function renderExisting() {
        const edition = state.selectedEdition ?? state.lookup.local_matches[0];
        const view = page(documentImpl, "existing");
        view.append(heading(documentImpl,
            state.selectedEdition === null || edition.existing_item_count === 0
                ? "Is dit inderdaad mijn uitgave?"
                : "Nog een exemplaar toevoegen?"
        ));
        view.append(editionCard(documentImpl, edition));
        const existing = localItemContext(documentImpl, edition);
        if (existing !== null) {
            view.append(existing);
        }
        if (state.selectedEdition !== null && edition.existing_item_count > 0) {
            view.append(actions(
                documentImpl,
                button(documentImpl, "Nog een exemplaar toevoegen", () => {
                    state.extraCopyAcknowledged = true;
                    state.step = "item";
                    state.notice = "Vul de exemplaargegevens in.";
                    render();
                }, "primary"),
                button(documentImpl, "Annuleren", cancel, "tertiary")
            ));
            return view;
        }
        view.append(actions(
            documentImpl,
            button(documentImpl, "Deze uitgave gebruiken", () => chooseExisting(edition), "primary"),
            button(documentImpl, "Klopt niet?", () => {
                state.step = "identification-problem";
                state.problemEdition = edition;
                state.notice = "Kies wat er niet klopt.";
                render();
            }),
            button(documentImpl, "Annuleren", cancel, "tertiary")
        ));
        return view;
    }

    function renderAmbiguous() {
        const view = page(documentImpl, "ambiguous");
        view.append(heading(documentImpl, "Welke uitgave heb je?"), element(documentImpl, "p", {
            text: "Dit ISBN hoort bij meerdere bestaande uitgaven. Kies de uitgave die bij jouw boek past.",
        }));
        const list = element(documentImpl, "div", {
            className: "biblio-ui__edition-grid",
        });
        for (const edition of state.lookup.local_matches) {
            const card = editionCard(documentImpl, edition);
            const existing = localItemContext(documentImpl, edition);
            if (existing !== null) {
                card.append(existing);
            }
            card.append(button(documentImpl, "Deze uitgave", () => chooseExisting(edition), "primary"));
            list.append(card);
        }
        view.append(list, actions(
            documentImpl,
            button(documentImpl, "Geen van deze", () => {
                state.problemEdition = null;
                state.step = "identification-problem";
                state.notice = "Dit moet als catalogusprobleem worden beoordeeld.";
                render();
            }),
            button(documentImpl, "Annuleren", cancel, "tertiary")
        ));
        return view;
    }

    function renderIdentificationProblem() {
        const view = page(documentImpl, "identification-problem");
        view.append(heading(documentImpl, "Wat klopt er niet?"));
        if (state.problemBlocked) {
            view.append(element(documentImpl, "p", {
                text: "Deze identificatie vraagt cataloguscontrole. Er wordt niet automatisch een dubbele uitgave gemaakt en er is niets toegevoegd.",
            }), actions(
                documentImpl,
                button(documentImpl, "Terug naar ISBN invoeren", () => {
                    state.problemBlocked = false;
                    state.problemEdition = null;
                    state.step = "start";
                    state.notice = "Voer een ander ISBN in of annuleer.";
                    render();
                }),
                button(documentImpl, "Annuleren", cancel, "tertiary")
            ));
            return view;
        }
        if (state.problemEdition === null) {
            view.append(element(documentImpl, "p", {
                text: "Geen van de bestaande uitgaven past. Er wordt niet automatisch een dubbele uitgave gemaakt.",
            }), actions(
                documentImpl,
                button(documentImpl, "Terug naar uitgaven", () => {
                    state.step = "ambiguous";
                    state.notice = "Kies een bestaande uitgave.";
                    render();
                }),
                button(documentImpl, "Annuleren", cancel, "tertiary")
            ));
            return view;
        }
        view.append(actions(
            documentImpl,
            button(documentImpl, "Dit is niet mijn uitgave", () => {
                state.problemBlocked = true;
                state.notice = "Deze identificatie vraagt cataloguscontrole. Er is niets toegevoegd.";
                render();
            }, "secondary"),
            button(documentImpl, "De uitgave klopt, maar gegevens zijn fout", () => {
                state.selectedEdition = state.problemEdition;
                state.selection = {
                    type: "existing_edition",
                    edition_id: state.problemEdition.edition_id,
                };
                state.editionMode = "existing-correction";
                state.editionDraft = localCorrectionDraft(state.problemEdition);
                state.summaryRequired = true;
                state.step = "edition-form";
                state.notice = "Pas alleen gegevens aan die je op het boek controleert.";
                render();
            }, "primary"),
            button(documentImpl, "Terug", () => {
                state.step = "existing";
                state.notice = "Controleer de bestaande uitgave.";
                render();
            }, "tertiary")
        ));
        return view;
    }

    function renderCandidate() {
        const candidate = state.lookup.candidates[0];
        const view = page(documentImpl, "candidate");
        view.append(heading(documentImpl, "Is dit de juiste uitgave?"), editionCard(documentImpl, candidate));
        view.append(actions(
            documentImpl,
            button(documentImpl, "Ja, deze uitgave", () => chooseCandidate(candidate), "primary"),
            button(documentImpl, "Gegevens aanpassen", () => chooseCandidate(candidate, true)),
            button(documentImpl, "Handmatig invoeren", startManual),
            button(documentImpl, "Annuleren", cancel, "tertiary")
        ));
        return view;
    }

    function renderMultiple() {
        const view = page(documentImpl, "multiple");
        view.append(heading(documentImpl, "Kies de juiste uitgave"), element(documentImpl, "p", {
            text: "Vergelijk de gegevens op je boek. Biblio kiest niet automatisch.",
        }));
        const list = element(documentImpl, "div", {
            className: "biblio-ui__edition-grid",
        });
        const differences = candidateDifferences(state.lookup.candidates);
        for (const candidate of state.lookup.candidates) {
            const card = editionCard(documentImpl, candidate, { differences });
            card.append(button(documentImpl, "Deze uitgave", () => chooseCandidate(candidate), "primary"));
            list.append(card);
        }
        view.append(list, actions(
            documentImpl,
            button(documentImpl, "Geen van deze / Handmatig invoeren", startManual),
            button(documentImpl, "Annuleren", cancel, "tertiary")
        ));
        return view;
    }

    function renderMiss() {
        const view = page(documentImpl, "miss");
        view.append(heading(documentImpl, "Geen bruikbare boekgegevens gevonden"), element(documentImpl, "p", {
            text: "Je kunt deze uitgave handmatig invoeren.",
        }), actions(
            documentImpl,
            button(documentImpl, "Handmatig invoeren", startManual, "primary"),
            button(documentImpl, "Annuleren", cancel, "tertiary")
        ));
        return view;
    }

    function renderProviderFailure() {
        const view = page(documentImpl, "provider-failure");
        view.append(heading(documentImpl, "Boekgegevens niet beschikbaar"), element(documentImpl, "p", {
            text: "Boekgegevens konden tijdelijk niet worden opgehaald.",
            attributes: { role: "alert" },
        }), actions(
            documentImpl,
            button(documentImpl, "Opnieuw proberen", () => void lookup(state.rawIsbn), "primary"),
            button(documentImpl, "Handmatig invoeren", startManual),
            button(documentImpl, "Annuleren", cancel, "tertiary")
        ));
        return view;
    }

    function renderEditionForm() {
        const draft = state.editionDraft;
        const view = page(documentImpl, "edition-form");
        view.append(heading(documentImpl,
            state.editionMode === "manual" ? "Uitgave handmatig invoeren" : "Uitgavegegevens aanpassen"
        ));
        const form = element(documentImpl, "form", {
            className: "biblio-ui__guided-form",
            attributes: { novalidate: "novalidate" },
        });
        form.append(
            field(documentImpl, {
                id: "add-book-edition-isbn",
                name: "isbn",
                label: "ISBN",
                value: draft.isbn,
                inputMode: "numeric",
                help: state.identifier === null
                    ? "Laat leeg wanneer deze uitgave geen ISBN heeft. Een nieuw ISBN moet eerst worden opgezocht."
                    : "Dit ISBN blijft gekoppeld aan de gecontroleerde uitgave.",
            }),
            field(documentImpl, { id: "add-book-title", name: "title", label: "Titel van deze uitgave", value: draft.title, required: true }),
            field(documentImpl, { id: "subtitle", label: "Ondertitel (optioneel)", value: draft.subtitle }),
            textAreaField(documentImpl, { id: "contributors", label: "Bijdragers (optioneel)", value: draft.contributors.join(", "), help: "Scheid namen met komma's." }),
            field(documentImpl, { id: "languages", label: "Taal/talen (optioneel)", value: draft.languages.join(", "), help: "Scheid meerdere talen met komma's." }),
            field(documentImpl, { id: "publishers", label: "Uitgever of imprint (optioneel)", value: draft.publishers.join(", "), help: "Scheid meerdere namen met komma's." }),
            field(documentImpl, { id: "publication_date", label: "Publicatiejaar of -datum (optioneel)", value: draft.publication_date }),
            field(documentImpl, { id: "edition_statement", label: "Druk- of editieaanduiding (optioneel)", value: draft.edition_statement }),
            field(documentImpl, { id: "format", label: "Bindwijze (optioneel)", value: draft.format }),
            field(documentImpl, { id: "add-book-page-count", name: "page_count", label: "Aantal pagina's (optioneel)", type: "number", value: draft.page_count ?? "", inputMode: "numeric" })
        );
        const workSection = element(documentImpl, "section", {
            className: "biblio-ui__work-link",
            attributes: { "aria-labelledby": "add-book-work-link-title" },
        });
        workSection.append(element(documentImpl, "h2", {
            text: "Werk",
            attributes: { id: "add-book-work-link-title" },
        }));
        if (state.editionMode === "manual") {
            workSection.append(element(documentImpl, "p", {
                text: state.selectedWork === null
                    ? "Optioneel: koppel deze uitgave bewust aan een bestaand werk."
                    : `Gekozen werk: ${state.selectedWork.title}`,
            }), button(documentImpl, "Koppel aan bestaand werk", () => {
                const draft = readEditionForm(new FormData(form));
                if (draft === null) {
                    return;
                }
                state.editionDraft = draft;
                beginWorkSearch();
            }));
            if (state.selectedWork !== null) {
                workSection.append(button(documentImpl, "Koppeling verwijderen", () => {
                    const draft = readEditionForm(new FormData(form));
                    if (draft === null) {
                        return;
                    }
                    state.editionDraft = draft;
                    state.selectedWork = null;
                    state.notice = "De bestaande Work-koppeling is verwijderd.";
                    render({ focus: false });
                }, "tertiary"));
            }
        } else {
            workSection.append(element(documentImpl, "p", {
                text: "Werk-identiteit en centrale catalogusrelaties worden hier niet aangepast.",
            }));
        }
        form.append(workSection, actions(
            documentImpl,
            button(documentImpl, "Verder", () => form.requestSubmit?.(), "primary"),
            button(documentImpl, "Terug", () => {
                state.step = state.editionMode === "candidate-adjust"
                    ? "candidate"
                    : state.editionMode === "existing-correction" ? "existing" : "start";
                state.notice = "Terug naar de vorige stap.";
                render();
            }, "tertiary"),
            button(documentImpl, "Annuleren", cancel, "tertiary")
        ));
        submitForm(form, continueEdition);
        view.append(form);
        return view;
    }

    function renderWorkSearch() {
        const view = page(documentImpl, "work-search", state.workSearch.pending);
        view.append(heading(documentImpl, "Koppel aan bestaand werk"));
        const form = element(documentImpl, "form", {
            className: "biblio-ui__guided-form biblio-ui__work-search-form",
            attributes: { role: "search", novalidate: "novalidate" },
        });
        form.append(field(documentImpl, {
            id: "work-query",
            label: "Zoek op werktitel of auteur",
            value: state.workSearch.query,
            required: true,
        }), actions(documentImpl, button(documentImpl, "Zoeken", () => form.requestSubmit?.(), "primary")));
        submitForm(form, (data) => void searchWorks(String(data.get("work-query") ?? "")));
        view.append(form);
        if (state.workSearch.items.length > 0) {
            const list = element(documentImpl, "ul", {
                className: "biblio-ui__work-results",
                attributes: { "aria-label": "Gevonden werken" },
            });
            for (const work of state.workSearch.items) {
                const item = element(documentImpl, "li", {
                    className: "biblio-ui__work-result",
                });
                item.append(element(documentImpl, "h2", { text: work.title }));
                if (work.authors.length > 0) {
                    item.append(element(documentImpl, "p", { text: work.authors.map((author) => author.display_name).join(", ") }));
                }
                item.append(element(documentImpl, "p", {
                    className: "biblio-ui__context",
                    text: statusLabel(work.work_title_status),
                }));
                if (work.series.length > 0) {
                    item.append(element(documentImpl, "p", { text: `Serie: ${seriesLine(work.series)}` }));
                }
                item.append(button(documentImpl, "Dit werk gebruiken", () => {
                    state.selectedWork = work;
                    state.selection = { type: "manual", work_id: work.work_id };
                    state.step = "edition-form";
                    state.notice = `${work.title} is als bestaand werk gekozen.`;
                    render();
                }, "primary"));
                list.append(item);
            }
            view.append(list);
        }
        if (state.workSearch.nextCursor !== null) {
            view.append(button(documentImpl, "Meer werken laden", () => void searchWorks(
                state.workSearch.query,
                state.workSearch.nextCursor
            )));
        }
        view.append(actions(
            documentImpl,
            button(documentImpl, "Geen passend werk", () => {
                state.selectedWork = null;
                state.selection = { type: "manual" };
                state.step = "edition-form";
                state.notice = "Er is geen bestaand werk gekozen.";
                render();
            }),
            button(documentImpl, "Terug", () => {
                state.step = "edition-form";
                state.notice = "Terug naar de uitgavegegevens.";
                render();
            }, "tertiary")
        ));
        return view;
    }

    function checkboxGroup(documentImpl, title, terms, fieldName, idField, selected) {
        if (terms.length === 0) {
            return null;
        }
        const group = element(documentImpl, "fieldset", {
            className: "biblio-ui__choice-group",
        });
        group.append(element(documentImpl, "legend", { text: `${title} (optioneel)` }));
        for (const term of terms) {
            const id = `add-book-${fieldName}-${term[idField]}`;
            const label = element(documentImpl, "label", {
                className: "biblio-ui__check-choice",
                attributes: { for: id },
            });
            const attributes = { id, name: fieldName, type: "checkbox", value: term[idField] };
            if (selected.includes(term[idField])) {
                attributes.checked = "checked";
            }
            label.append(element(documentImpl, "input", { attributes }), element(documentImpl, "span", { text: term.display_name }));
            group.append(label);
        }
        return group;
    }

    function renderItem() {
        const view = page(documentImpl, "item");
        view.append(heading(documentImpl, "Exemplaar en classificatie"));
        const form = element(documentImpl, "form", {
            className: "biblio-ui__guided-form",
            attributes: { novalidate: "novalidate" },
        });
        const selectField = element(documentImpl, "div", { className: "biblio-ui__field" });
        const select = element(documentImpl, "select", {
            attributes: { id: "add-book-book-type", name: "book_type_id", required: "required", "aria-required": "true" },
        });
        select.append(element(documentImpl, "option", { text: "Kies een boektype", attributes: { value: "" } }));
        for (const term of state.options.book_types) {
            const attrs = { value: term.book_type_id };
            if (state.classification.bookTypeId === term.book_type_id) {
                attrs.selected = "selected";
            }
            select.append(element(documentImpl, "option", { text: term.display_name, attributes: attrs }));
        }
        selectField.append(element(documentImpl, "label", { text: "Boektype", attributes: { for: "add-book-book-type" } }), select);
        form.append(selectField);
        const genres = checkboxGroup(documentImpl, "Genres", state.options.genres, "genre_ids", "genre_id", state.classification.genreIds);
        const subjects = checkboxGroup(documentImpl, "Onderwerpen", state.options.subjects, "subject_ids", "subject_id", state.classification.subjectIds);
        if (genres !== null) {
            form.append(genres);
        }
        if (subjects !== null) {
            form.append(subjects);
        }
        form.append(field(documentImpl, {
            id: "inventory_number",
            label: "Inventarisnummer (optioneel)",
            value: state.inventoryNumber,
            help: "Locatie is hier nog niet beschikbaar omdat er geen veilige keuzebron voor deze wizard bestaat.",
        }));
        form.append(actions(
            documentImpl,
            button(documentImpl, state.summaryRequired ? "Naar controle" : "Boek toevoegen", () => form.requestSubmit?.(), "primary"),
            button(documentImpl, "Terug", () => {
                state.step = state.selection.type === "existing_edition"
                    ? "existing"
                    : state.editionMode !== null
                        ? "edition-form"
                        : state.lookup?.status === "multiple_candidates"
                            ? "multiple"
                            : "candidate";
                state.notice = "Terug naar de vorige stap.";
                render();
            }, "tertiary"),
            button(documentImpl, "Annuleren", cancel, "tertiary")
        ));
        submitForm(form, (data) => continueItem(data, form));
        view.append(form);
        return view;
    }

    function selectedTitle() {
        return state.observedFields.title
            ?? state.selectedEdition?.edition_title
            ?? state.selectedCandidate?.fields.title
            ?? "Onbekende uitgave";
    }

    function renderSummary() {
        const view = page(documentImpl, "summary");
        view.append(heading(documentImpl, "Controleer je boek"));
        const summary = element(documentImpl, "dl", {
            className: "biblio-ui__summary",
        });
        addFact(documentImpl, summary, "Titel", selectedTitle());
        addFact(documentImpl, summary, "ISBN", state.identifier);
        const bookType = state.options.book_types.find((term) => term.book_type_id === state.classification.bookTypeId);
        addFact(documentImpl, summary, "Boektype", bookType?.display_name);
        addFact(documentImpl, summary, "Bestaand werk", state.selectedWork?.title);
        addFact(documentImpl, summary, "Inventarisnummer", state.inventoryNumber);
        view.append(summary, actions(
            documentImpl,
            button(documentImpl, "Boek toevoegen", () => void commit(), "primary"),
            button(documentImpl, "Terug", () => {
                state.step = "item";
                state.notice = "Pas exemplaar of classificatie aan.";
                render();
            }),
            button(documentImpl, "Annuleren", cancel, "tertiary")
        ));
        return view;
    }

    function renderCommitting() {
        const view = page(documentImpl, "committing", true);
        view.append(heading(documentImpl, "Boek toevoegen"), element(documentImpl, "p", {
            text: "Je boek wordt toegevoegd…",
        }));
        const pending = button(documentImpl, "Boek toevoegen", () => {}, "primary");
        pending.disabled = true;
        pending.setAttribute("aria-busy", "true");
        view.append(actions(documentImpl, pending));
        return view;
    }

    function renderSuccess() {
        const view = page(documentImpl, "success");
        view.append(heading(documentImpl, "Boek toegevoegd", "Gereed"), element(documentImpl, "p", {
            className: "biblio-ui__success-title",
            text: state.commitResult.edition_title,
        }));
        const describe = button(documentImpl, "Exemplaar verder beschrijven", () => {});
        describe.disabled = true;
        describe.setAttribute("aria-describedby", "add-book-describe-note");
        view.append(actions(
            documentImpl,
            button(documentImpl, "Bekijk boek", () => callbacks?.onOpenItem?.(state.commitResult.item_id), "primary"),
            describe,
            button(documentImpl, "Nog een boek toevoegen", () => resetForAnother())
        ), element(documentImpl, "p", {
            className: "biblio-ui__field-help",
            text: "Exemplaar verder beschrijven komt beschikbaar zodra Boekdetail bewerken deze context kan openen.",
            attributes: { id: "add-book-describe-note" },
        }));
        return view;
    }

    function renderError() {
        const view = page(documentImpl, "error");
        view.append(heading(documentImpl, "Boek toevoegen lukt niet"), element(documentImpl, "p", {
            text: state.errorMessage,
            attributes: { role: "alert" },
        }), actions(
            documentImpl,
            state.selection === null ? null : button(documentImpl, "Opnieuw proberen", () => {
                state.step = state.summaryRequired ? "summary" : "item";
                state.notice = "Controleer de gegevens en probeer opnieuw.";
                render();
            }, "primary"),
            button(documentImpl, "Opnieuw beginnen", () => {
                resetForAnother("Begin opnieuw met ISBN of handmatige invoer.");
            }),
            button(documentImpl, "Annuleren", cancel, "tertiary")
        ));
        return view;
    }

    function destroy() {
        destroyRequest();
        scanner.stop();
        state = null;
        callbacks = null;
        opener = null;
    }

    return Object.freeze({ open, destroy });
}
