const PAGE_FIELDS = ["items", "next_cursor"];
const WORK_FIELDS = ["work_id", "title", "authors", "work_title_status", "series"];
const AUTHOR_FIELDS = ["author_id", "display_name"];
const SERIES_FIELDS = ["series_id", "display_name", "position"];
const WORK_TITLE_STATUSES = new Set(["provisional", "librarian_confirmed"]);

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

export function readWorkDiscoveryWork(value) {
    if (
        !exact(value, WORK_FIELDS)
        || !text(value.work_id)
        || !text(value.title)
        || !Array.isArray(value.authors)
        || !WORK_TITLE_STATUSES.has(value.work_title_status)
        || !Array.isArray(value.series)
    ) {
        throw new TypeError("The Biblio Work discovery item is invalid.");
    }

    const authors = value.authors.map((author) => {
        if (
            !exact(author, AUTHOR_FIELDS)
            || !text(author.author_id)
            || !text(author.display_name)
        ) {
            throw new TypeError("The Biblio Work discovery Author is invalid.");
        }
        return Object.freeze({ ...author });
    });
    const series = value.series.map((context) => {
        if (
            !exact(context, SERIES_FIELDS)
            || !text(context.series_id)
            || !text(context.display_name)
            || !(context.position === null || text(context.position))
        ) {
            throw new TypeError("The Biblio Work discovery Series context is invalid.");
        }
        return Object.freeze({ ...context });
    });

    return Object.freeze({
        work_id: value.work_id,
        title: value.title,
        authors: Object.freeze(authors),
        work_title_status: value.work_title_status,
        series: Object.freeze(series),
    });
}

export function readWorkPage(value) {
    if (
        !exact(value, PAGE_FIELDS)
        || !Array.isArray(value.items)
        || !(value.next_cursor === null || text(value.next_cursor))
    ) {
        throw new TypeError("The Biblio Work discovery response is invalid.");
    }

    return Object.freeze({
        items: Object.freeze(value.items.map(readWorkDiscoveryWork)),
        next_cursor: value.next_cursor,
    });
}
