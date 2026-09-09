const QUERY_SORTS = new Set(["title", "author", "series"]);
const QUERY_READING_STATUSES = new Set(["not_read", "reading", "read"]);
const PRESENTATION_READING_STATUSES = new Set([
    "not_read", "reading", "read", "unknown",
]);
const ARCHIVE_SCOPES = new Set(["active_only", "active_and_archived"]);
const FILTER_FIELDS = Object.freeze({
    readingStatuses: "reading_statuses",
    authorIds: "author_ids",
    seriesIds: "series_ids",
    locationIds: "location_ids",
    bookTypeIds: "book_type_ids",
    genreIds: "genre_ids",
    subjectIds: "subject_ids",
    collectionIds: "collection_ids",
});
const URL_FIELDS = Object.freeze({
    search: "catalog_search",
    sort: "catalog_sort",
    withoutCollection: "catalog_without_collection",
    readingStatuses: "catalog_reading_status",
    authorIds: "catalog_author",
    seriesIds: "catalog_series",
    locationIds: "catalog_location",
    bookTypeIds: "catalog_book_type",
    genreIds: "catalog_genre",
    subjectIds: "catalog_subject",
    collectionIds: "catalog_collection",
});
const ITEM_FIELDS = [
    "item_id",
    "work_id",
    "edition_id",
    "title",
    "item_status",
    "inventory_number",
    "authors",
    "series",
    "location",
    "classification",
    "collection_ids",
    "reading_status",
    "read_date_known",
    "contained_match_title",
];
const PAGE_FIELDS = ["library", "items", "next_cursor"];
const OPTION_FIELDS = ["library_id", "book_types", "genres", "subjects"];

function isRecord(value) {
    return value !== null && typeof value === "object" && !Array.isArray(value);
}

function hasExactFields(value, fields) {
    return isRecord(value)
        && Object.keys(value).length === fields.length
        && fields.every((field) => Object.hasOwn(value, field));
}

function identifier(value) {
    return typeof value === "string"
        && value.length > 0
        && value === value.trim();
}

function uniqueIdentifiers(values) {
    return Array.isArray(values)
        && values.length <= 100
        && values.every(identifier)
        && new Set(values).size === values.length;
}

function exactIdentifierList(params, name) {
    const values = params.getAll(name);
    if (!uniqueIdentifiers(values)) {
        throw new TypeError(`Catalog query parameter ${name} is invalid.`);
    }
    return Object.freeze([...values]);
}

function normalizedList(values, allowed = null) {
    if (!uniqueIdentifiers(values)) {
        throw new TypeError("Catalog filter values must be unique identifiers.");
    }
    if (allowed !== null && values.some((value) => !allowed.has(value))) {
        throw new TypeError("Catalog filter value is not supported.");
    }
    return Object.freeze([...values].sort());
}

export function defaultCatalogQuery() {
    return Object.freeze({
        search: "",
        readingStatuses: Object.freeze([]),
        authorIds: Object.freeze([]),
        seriesIds: Object.freeze([]),
        locationIds: Object.freeze([]),
        bookTypeIds: Object.freeze([]),
        genreIds: Object.freeze([]),
        subjectIds: Object.freeze([]),
        collectionIds: Object.freeze([]),
        withoutCollection: false,
        sort: "title",
        archiveScope: "active_only",
    });
}

export function normalizeCatalogQuery(value) {
    if (!isRecord(value)) {
        throw new TypeError("Catalog query state must be an object.");
    }

    const search = value.search ?? "";
    if (
        typeof search !== "string"
        || (search !== "" && (
            search !== search.trim()
            || [...search].length < 2
            || [...search].length > 191
        ))
    ) {
        throw new TypeError("Catalog search must be empty or contain 2 to 191 trimmed characters.");
    }

    const query = {
        search,
        readingStatuses: normalizedList(
            value.readingStatuses ?? [],
            QUERY_READING_STATUSES
        ),
        authorIds: normalizedList(value.authorIds ?? []),
        seriesIds: normalizedList(value.seriesIds ?? []),
        locationIds: normalizedList(value.locationIds ?? []),
        bookTypeIds: normalizedList(value.bookTypeIds ?? []),
        genreIds: normalizedList(value.genreIds ?? []),
        subjectIds: normalizedList(value.subjectIds ?? []),
        collectionIds: normalizedList(value.collectionIds ?? []),
        withoutCollection: value.withoutCollection === true,
        sort: value.sort ?? "title",
        archiveScope: value.archiveScope ?? "active_only",
    };

    if (!QUERY_SORTS.has(query.sort) || !ARCHIVE_SCOPES.has(query.archiveScope)) {
        throw new TypeError("Catalog sort or archive scope is invalid.");
    }
    if (query.withoutCollection && query.collectionIds.length > 0) {
        throw new TypeError("Without Collection is exclusive with Collection filters.");
    }
    if (query.sort === "series" && query.seriesIds.length === 0) {
        throw new TypeError("Series sort requires an active Series filter.");
    }

    return Object.freeze(query);
}

export function readCatalogQueryFromUrl(url) {
    const params = new URL(url).searchParams;
    const explicit = Object.values(URL_FIELDS).some((name) => params.has(name));
    if (!explicit) {
        return Object.freeze({ explicit: false, query: defaultCatalogQuery() });
    }

    const search = params.get(URL_FIELDS.search) ?? "";
    const sort = params.get(URL_FIELDS.sort) ?? "title";
    const withoutCollection = params.get(URL_FIELDS.withoutCollection) === "true";
    if (
        params.getAll(URL_FIELDS.search).length > 1
        || params.getAll(URL_FIELDS.sort).length > 1
        || params.getAll(URL_FIELDS.withoutCollection).length > 1
        || (params.has(URL_FIELDS.withoutCollection)
            && params.get(URL_FIELDS.withoutCollection) !== "true")
    ) {
        throw new TypeError("Catalog URL query state is invalid.");
    }

    return Object.freeze({
        explicit: true,
        query: normalizeCatalogQuery({
            search,
            sort,
            archiveScope: "active_only",
            withoutCollection,
            readingStatuses: exactIdentifierList(params, URL_FIELDS.readingStatuses),
            authorIds: exactIdentifierList(params, URL_FIELDS.authorIds),
            seriesIds: exactIdentifierList(params, URL_FIELDS.seriesIds),
            locationIds: exactIdentifierList(params, URL_FIELDS.locationIds),
            bookTypeIds: exactIdentifierList(params, URL_FIELDS.bookTypeIds),
            genreIds: exactIdentifierList(params, URL_FIELDS.genreIds),
            subjectIds: exactIdentifierList(params, URL_FIELDS.subjectIds),
            collectionIds: exactIdentifierList(params, URL_FIELDS.collectionIds),
        }),
    });
}

export function writeCatalogQueryToUrl(url, queryValue) {
    const query = normalizeCatalogQuery(queryValue);
    const params = url.searchParams;
    for (const name of Object.values(URL_FIELDS)) {
        params.delete(name);
    }
    if (query.search !== "") {
        params.set(URL_FIELDS.search, query.search);
    }
    if (query.sort !== "title") {
        params.set(URL_FIELDS.sort, query.sort);
    }
    if (query.withoutCollection) {
        params.set(URL_FIELDS.withoutCollection, "true");
    }
    for (const [property, name] of Object.entries(URL_FIELDS)) {
        if (!Object.hasOwn(FILTER_FIELDS, property)) {
            continue;
        }
        for (const value of query[property]) {
            params.append(name, value);
        }
    }
    return url;
}

export function catalogQueryPath(libraryId, queryValue, cursor = null) {
    if (!identifier(libraryId) || !(cursor === null || identifier(cursor))) {
        throw new TypeError("Catalog request identifiers are invalid.");
    }
    const query = normalizeCatalogQuery(queryValue);
    const params = new URLSearchParams();
    if (query.search !== "") {
        params.set("search", query.search);
    }
    for (const [property, name] of Object.entries(FILTER_FIELDS)) {
        for (const value of query[property]) {
            params.append(`${name}[]`, value);
        }
    }
    if (query.withoutCollection) {
        params.set("without_collection", "true");
    }
    if (query.sort !== "title") {
        params.set("sort", query.sort);
    }
    if (query.archiveScope !== "active_only") {
        params.set("archive_scope", query.archiveScope);
    }
    if (cursor !== null) {
        params.set("cursor", cursor);
    }
    const suffix = params.toString();
    return `libraries/${encodeURIComponent(libraryId)}/catalog${suffix === "" ? "" : `?${suffix}`}`;
}

function readAuthors(value) {
    if (!Array.isArray(value) || !value.every((author) => (
        hasExactFields(author, ["author_id", "display_name"])
        && identifier(author.author_id)
        && identifier(author.display_name)
    ))) {
        throw new TypeError("Catalog authors are invalid.");
    }
    return Object.freeze(value.map((author) => Object.freeze({ ...author })));
}

function readSeries(value) {
    if (!Array.isArray(value) || !value.every((series) => (
        hasExactFields(series, ["series_id", "display_name", "position"])
        && identifier(series.series_id)
        && identifier(series.display_name)
        && (series.position === null || typeof series.position === "string")
    ))) {
        throw new TypeError("Catalog Series context is invalid.");
    }
    return Object.freeze(value.map((series) => Object.freeze({ ...series })));
}

function readLocation(value) {
    if (value === null) {
        return null;
    }
    if (
        !hasExactFields(value, ["location_id", "display_name"])
        || !identifier(value.location_id)
        || !identifier(value.display_name)
    ) {
        throw new TypeError("Catalog Location is invalid.");
    }
    return Object.freeze({ ...value });
}

function readClassification(value) {
    if (value === null) {
        return null;
    }
    if (
        !hasExactFields(value, ["book_type_id", "genre_ids", "subject_ids"])
        || !identifier(value.book_type_id)
        || !uniqueIdentifiers(value.genre_ids)
        || !uniqueIdentifiers(value.subject_ids)
    ) {
        throw new TypeError("Catalog classification is invalid.");
    }
    return Object.freeze({
        book_type_id: value.book_type_id,
        genre_ids: Object.freeze([...value.genre_ids]),
        subject_ids: Object.freeze([...value.subject_ids]),
    });
}

function readCatalogItem(value, libraryName) {
    if (
        !hasExactFields(value, ITEM_FIELDS)
        || !identifier(value.item_id)
        || !identifier(value.work_id)
        || !identifier(value.edition_id)
        || !identifier(value.title)
        || !["active", "archived"].includes(value.item_status)
        || !(value.inventory_number === null || identifier(value.inventory_number))
        || !PRESENTATION_READING_STATUSES.has(value.reading_status)
        || !(value.read_date_known === null
            || typeof value.read_date_known === "boolean")
        || !(value.contained_match_title === null || identifier(value.contained_match_title))
        || !uniqueIdentifiers(value.collection_ids)
    ) {
        throw new TypeError("Catalog Item is invalid.");
    }
    const authors = readAuthors(value.authors);
    const location = readLocation(value.location);

    const presentation = {
        item_id: value.item_id,
        work_id: value.work_id,
        edition_id: value.edition_id,
        title: value.title,
        authors: Object.freeze({
            state: authors.length === 0 ? "unknown" : "known",
            values: Object.freeze(authors.map((author) => author.display_name)),
        }),
        cover_reference: Object.freeze({ state: "unknown", value: null }),
        form: Object.freeze({ state: "known", value: "physical_book" }),
        location_or_source: Object.freeze({
            state: "known",
            value: location?.display_name ?? libraryName,
        }),
        capabilities: Object.freeze({
            view_item: value.item_status === "active",
            start_reading: false,
        }),
        reading_status: value.reading_status,
        read_date_known: value.read_date_known,
        item_status: value.item_status,
    };
    readSeries(value.series);
    readClassification(value.classification);
    if (value.contained_match_title !== null) {
        presentation.contained_match_title = value.contained_match_title;
    }
    return Object.freeze(presentation);
}

export function readCatalogPage(payload, selectedLibraryId) {
    if (
        !hasExactFields(payload, PAGE_FIELDS)
        || !isRecord(payload.library)
        || payload.library.library_id !== selectedLibraryId
        || !identifier(payload.library.name)
        || !Array.isArray(payload.items)
        || !(payload.next_cursor === null || identifier(payload.next_cursor))
    ) {
        throw new TypeError("Catalog response is invalid.");
    }
    return Object.freeze({
        library: payload.library,
        items: Object.freeze(payload.items.map((item) => (
            readCatalogItem(item, payload.library.name)
        ))),
        nextCursor: payload.next_cursor,
    });
}

function readOptionList(values, idField) {
    if (!Array.isArray(values) || !values.every((option) => (
        hasExactFields(option, [idField, "display_name"])
        && identifier(option[idField])
        && identifier(option.display_name)
    ))) {
        throw new TypeError("Catalog filter options are invalid.");
    }
    const ids = values.map((option) => option[idField]);
    if (new Set(ids).size !== ids.length) {
        throw new TypeError("Catalog filter options contain duplicate identifiers.");
    }
    return Object.freeze(values.map((option) => Object.freeze({
        id: option[idField],
        label: option.display_name,
    })));
}

export function readClassificationOptions(payload, selectedLibraryId) {
    if (!hasExactFields(payload, OPTION_FIELDS) || payload.library_id !== selectedLibraryId) {
        throw new TypeError("Classification options response is invalid.");
    }
    return Object.freeze({
        bookTypes: readOptionList(payload.book_types, "book_type_id"),
        genres: readOptionList(payload.genres, "genre_id"),
        subjects: readOptionList(payload.subjects, "subject_id"),
    });
}

export function createCatalogQuerySession({
    storage = globalThis.sessionStorage,
    scope,
}) {
    const available = storage !== null
        && typeof storage?.getItem === "function"
        && typeof storage?.setItem === "function"
        ? storage
        : null;
    if (!identifier(scope)) {
        throw new TypeError("A current authenticated catalog session scope is required.");
    }
    const key = `biblio.catalog.query.${encodeURIComponent(scope)}`;

    return Object.freeze({
        read() {
            try {
                const value = available?.getItem(key);
                if (value === null || value === undefined) {
                    return null;
                }
                const parsed = JSON.parse(value);
                return isRecord(parsed)
                    ? normalizeCatalogQuery({
                        ...parsed,
                        archiveScope: "active_only",
                    })
                    : null;
            } catch {
                return null;
            }
        },
        write(query) {
            try {
                available?.setItem(key, JSON.stringify(normalizeCatalogQuery({
                    ...query,
                    archiveScope: "active_only",
                })));
            } catch {
                // Temporary query state must not make the catalog unavailable.
            }
        },
    });
}

export { FILTER_FIELDS, URL_FIELDS };
