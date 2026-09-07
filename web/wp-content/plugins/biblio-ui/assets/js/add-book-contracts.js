import { readWorkPage } from "./work-discovery.js";

const LOOKUP_FIELDS = [
    "library_id",
    "status",
    "identifier",
    "lookup_id",
    "local_matches",
    "candidates",
    "field_bindings",
    "manual_available",
    "retry_available",
];
const LOOKUP_STATUSES = new Set([
    "existing_edition",
    "local_ambiguous",
    "single_candidate",
    "multiple_candidates",
    "no_usable_candidate",
    "provider_failure",
]);
const OBSERVED_FIELDS = new Set([
    "isbn",
    "title",
    "subtitle",
    "contributors",
    "languages",
    "publishers",
    "publication_date",
    "edition_statement",
    "format",
    "page_count",
]);

function record(value) {
    return value !== null && typeof value === "object" && !Array.isArray(value);
}

function exact(value, fields) {
    return record(value)
        && Object.keys(value).length === fields.length
        && fields.every((field) => Object.hasOwn(value, field));
}

function text(value) {
    return typeof value === "string" && value.length > 0;
}

function nullableText(value) {
    return value === null || typeof value === "string";
}

function textList(value) {
    return Array.isArray(value)
        && value.every((entry) => typeof entry === "string");
}

function textMap(value) {
    if (Array.isArray(value)) {
        return value.length === 0;
    }

    return record(value)
        && Object.entries(value).every(([key, entry]) => text(key) && text(entry));
}

function readIdentifier(value) {
    if (
        !exact(value, ["isbn_10", "isbn_13"])
        || !nullableText(value.isbn_10)
        || !text(value.isbn_13)
    ) {
        throw new TypeError("The Add Book identifier contract is invalid.");
    }

    return Object.freeze({ ...value });
}

function readAuthors(value) {
    if (!Array.isArray(value)) {
        throw new TypeError("The Add Book Author contract is invalid.");
    }

    return Object.freeze(value.map((author) => {
        if (
            !exact(author, ["author_id", "display_name"])
            || !text(author.author_id)
            || !text(author.display_name)
        ) {
            throw new TypeError("The Add Book Author contract is invalid.");
        }

        return Object.freeze({ ...author });
    }));
}

function readExistingItem(value) {
    if (
        !exact(value, ["item_id", "inventory_number", "location"])
        || !text(value.item_id)
        || !nullableText(value.inventory_number)
        || !(value.location === null || (
            exact(value.location, ["location_id", "display_name"])
            && text(value.location.location_id)
            && text(value.location.display_name)
        ))
    ) {
        throw new TypeError("The Add Book existing Item contract is invalid.");
    }

    return Object.freeze({
        item_id: value.item_id,
        inventory_number: value.inventory_number,
        location: value.location === null
            ? null
            : Object.freeze({ ...value.location }),
    });
}

function readLocalMatch(value) {
    const fields = [
        "work_id",
        "work_title",
        "work_title_status",
        "edition_id",
        "edition_title",
        "authors",
        "canonical_isbn",
        "existing_item_count",
        "existing_items",
    ];

    if (
        !exact(value, fields)
        || !text(value.work_id)
        || !text(value.work_title)
        || !["provisional", "librarian_confirmed"].includes(
            value.work_title_status
        )
        || !text(value.edition_id)
        || !text(value.edition_title)
        || !nullableText(value.canonical_isbn)
        || !Number.isInteger(value.existing_item_count)
        || value.existing_item_count < 0
        || !Array.isArray(value.existing_items)
        || value.existing_items.length !== value.existing_item_count
    ) {
        throw new TypeError("The Add Book local Edition contract is invalid.");
    }

    return Object.freeze({
        ...value,
        authors: readAuthors(value.authors),
        existing_items: Object.freeze(value.existing_items.map(readExistingItem)),
    });
}

function readCandidate(value) {
    const fields = [
        "candidate_id",
        "source",
        "quality",
        "identifier",
        "fields",
        "work_signal",
    ];
    const metadataFields = [
        "title",
        "subtitle",
        "contributors",
        "languages",
        "publishers",
        "publication_date",
        "page_count",
        "format",
    ];

    if (
        !exact(value, fields)
        || !text(value.candidate_id)
        || !exact(value.source, ["provider_key", "retrieved_at", "match_method"])
        || !text(value.source.provider_key)
        || !text(value.source.retrieved_at)
        || !text(value.source.match_method)
        || !text(value.quality)
        || !exact(value.fields, metadataFields)
        || !text(value.fields.title)
        || !nullableText(value.fields.subtitle)
        || !textList(value.fields.contributors)
        || !textList(value.fields.languages)
        || !textList(value.fields.publishers)
        || !nullableText(value.fields.publication_date)
        || !(value.fields.page_count === null
            || (Number.isInteger(value.fields.page_count)
                && value.fields.page_count > 0))
        || !nullableText(value.fields.format)
        || !(value.work_signal === null || (
            exact(value.work_signal, ["relation"])
            && text(value.work_signal.relation)
        ))
    ) {
        throw new TypeError("The Add Book metadata candidate contract is invalid.");
    }

    return Object.freeze({
        candidate_id: value.candidate_id,
        source: Object.freeze({ ...value.source }),
        quality: value.quality,
        identifier: readIdentifier(value.identifier),
        fields: Object.freeze({
            ...value.fields,
            contributors: Object.freeze([...value.fields.contributors]),
            languages: Object.freeze([...value.fields.languages]),
            publishers: Object.freeze([...value.fields.publishers]),
        }),
        work_signal: value.work_signal === null
            ? null
            : Object.freeze({ ...value.work_signal }),
    });
}

function readFieldBinding(value) {
    if (
        !exact(value, [
            "field",
            "target",
            "explicit_mappings",
            "fallback_target",
        ])
        || !text(value.field)
        || !text(value.target)
        || !textMap(value.explicit_mappings)
        || !nullableText(value.fallback_target)
    ) {
        throw new TypeError("The Add Book field-binding contract is invalid.");
    }

    return Object.freeze({
        ...value,
        explicit_mappings: Object.freeze({ ...value.explicit_mappings }),
    });
}

export function readAddBookLookup(value, libraryId) {
    if (
        !exact(value, LOOKUP_FIELDS)
        || value.library_id !== libraryId
        || !LOOKUP_STATUSES.has(value.status)
        || !(value.lookup_id === null || text(value.lookup_id))
        || !Array.isArray(value.local_matches)
        || !Array.isArray(value.candidates)
        || !Array.isArray(value.field_bindings)
        || typeof value.manual_available !== "boolean"
        || typeof value.retry_available !== "boolean"
    ) {
        throw new TypeError("The Add Book lookup response is invalid.");
    }

    const identifier = readIdentifier(value.identifier);
    const localMatches = Object.freeze(value.local_matches.map(readLocalMatch));
    const candidates = Object.freeze(value.candidates.map(readCandidate));

    if (
        (["existing_edition", "local_ambiguous"].includes(value.status)
            && value.lookup_id !== null)
        || (value.status === "existing_edition" && localMatches.length !== 1)
        || (value.status === "local_ambiguous" && localMatches.length < 2)
        || (value.status === "single_candidate"
            && (candidates.length !== 1 || value.lookup_id === null))
        || (value.status === "multiple_candidates"
            && (candidates.length < 2 || value.lookup_id === null))
        || (["no_usable_candidate", "provider_failure"].includes(value.status)
            && (localMatches.length !== 0 || candidates.length !== 0))
    ) {
        throw new TypeError("The Add Book lookup response is inconsistent.");
    }

    return Object.freeze({
        library_id: value.library_id,
        status: value.status,
        identifier,
        lookup_id: value.lookup_id,
        local_matches: localMatches,
        candidates,
        field_bindings: Object.freeze(value.field_bindings.map(readFieldBinding)),
        manual_available: value.manual_available,
        retry_available: value.retry_available,
    });
}

function readClassificationTerm(value, idField) {
    if (
        !exact(value, [idField, "display_name"])
        || !text(value[idField])
        || !text(value.display_name)
    ) {
        throw new TypeError("The Add Book classification term is invalid.");
    }

    return Object.freeze({ ...value });
}

export function readClassificationOptions(value, libraryId) {
    if (
        !exact(value, ["library_id", "book_types", "genres", "subjects"])
        || value.library_id !== libraryId
        || !Array.isArray(value.book_types)
        || !Array.isArray(value.genres)
        || !Array.isArray(value.subjects)
    ) {
        throw new TypeError("The Add Book classification response is invalid.");
    }

    return Object.freeze({
        library_id: libraryId,
        book_types: Object.freeze(value.book_types.map(
            (term) => readClassificationTerm(term, "book_type_id")
        )),
        genres: Object.freeze(value.genres.map(
            (term) => readClassificationTerm(term, "genre_id")
        )),
        subjects: Object.freeze(value.subjects.map(
            (term) => readClassificationTerm(term, "subject_id")
        )),
    });
}

export function readAddBookCommit(value) {
    if (
        !exact(value, [
            "item_id",
            "edition_id",
            "work_id",
            "edition_title",
            "work_title_status",
            "existing_edition",
        ])
        || !text(value.item_id)
        || !text(value.edition_id)
        || !text(value.work_id)
        || !text(value.edition_title)
        || !["provisional", "librarian_confirmed"].includes(
            value.work_title_status
        )
        || typeof value.existing_edition !== "boolean"
    ) {
        throw new TypeError("The Add Book commit response is invalid.");
    }

    return Object.freeze({ ...value });
}

export function readAddBookWorkPage(value) {
    return readWorkPage(value);
}

export function normalizeIsbn(value) {
    const compact = typeof value === "string"
        ? value.replace(/[\s-]+/gu, "").toUpperCase()
        : "";

    if (/^[0-9]{13}$/u.test(compact)) {
        const sum = [...compact].slice(0, 12).reduce(
            (total, digit, index) => total + Number(digit) * (index % 2 === 0 ? 1 : 3),
            0
        );
        return (10 - (sum % 10)) % 10 === Number(compact[12])
            ? compact
            : null;
    }

    if (/^[0-9]{9}[0-9X]$/u.test(compact)) {
        const sum = [...compact].reduce((total, digit, index) => (
            total + (digit === "X" ? 10 : Number(digit)) * (10 - index)
        ), 0);
        if (sum % 11 !== 0) {
            return null;
        }
        const stem = `978${compact.slice(0, 9)}`;
        const checkSum = [...stem].reduce(
            (total, digit, index) => total + Number(digit) * (index % 2 === 0 ? 1 : 3),
            0
        );
        return `${stem}${(10 - (checkSum % 10)) % 10}`;
    }

    return null;
}

export function observedFieldsFromForm(values) {
    if (!record(values)) {
        throw new TypeError("The Add Book Edition form is invalid.");
    }

    const observed = {};
    for (const [key, value] of Object.entries(values)) {
        if (!OBSERVED_FIELDS.has(key)) {
            throw new TypeError("The Add Book Edition form contains an unknown field.");
        }
        if (value === null || value === "" || (Array.isArray(value) && value.length === 0)) {
            continue;
        }
        observed[key] = value;
    }
    return observed;
}

export function buildAddBookCommitBody(state) {
    if (!record(state) || !record(state.selection) || !record(state.classification)) {
        throw new TypeError("The Add Book commit state is invalid.");
    }

    const item = {};
    if (typeof state.inventoryNumber === "string"
        && state.inventoryNumber.trim() !== "") {
        item.inventory_number = state.inventoryNumber.trim();
    }

    return {
        identifier: state.identifier,
        selection: { ...state.selection },
        observed_fields: observedFieldsFromForm(state.observedFields ?? {}),
        classification: {
            book_type_id: state.classification.bookTypeId,
            genre_ids: [...(state.classification.genreIds ?? [])],
            subject_ids: [...(state.classification.subjectIds ?? [])],
        },
        item,
    };
}
