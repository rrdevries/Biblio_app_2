const DISCOVERY_FIELDS = ["query", "status", "discovery_id", "results", "provider_attempts"];
const QUERY_FIELDS = ["type", "normalized"];
const CANDIDATE_FIELDS = [
    "candidate_id",
    "type",
    "work_id",
    "edition_id",
    "title",
    "subtitle",
    "contributors",
    "languages",
    "publishers",
    "publication_date",
    "page_count",
    "format",
    "isbn_10",
    "isbn_13",
    "provider_evidence",
    "presentation_order",
    "capabilities",
];
const CAPABILITY_FIELDS = ["can_add_work_only", "can_add_edition_specific"];
const EVIDENCE_FIELDS = [
    "provider_key",
    "provider_record_id",
    "provider_work_id",
    "retrieved_at",
    "match_method",
];
const ATTEMPT_FIELDS = ["provider_key", "status", "failure_reason"];
const MATERIALIZATION_FIELDS = ["work_id", "edition_id", "reused"];
const QUERY_TYPES = new Set(["isbn", "text"]);
const STATUSES = new Set([
    "results",
    "no_results",
    "provider_failure",
    "configuration_failure",
    "invalid_provider_response",
]);
const CANDIDATE_TYPES = new Set([
    "local_work",
    "local_edition",
    "external_work_candidate",
    "external_edition_candidate",
]);
const PROVIDER_STATUSES = new Set([
    "candidates",
    "miss",
    "unavailable",
    "rate_limited",
    "configuration_error",
    "invalid_response",
]);
const FAILURE_REASONS = new Set([
    "timeout",
    "network",
    "rate_limited",
    "configuration",
    "http_error",
    "http_5xx",
    "malformed",
    "isbn_mismatch",
]);
const MATCH_METHODS = new Set(["exact_isbn", "text_search"]);
const MATERIALIZATION_INTENTS = new Set(["work_only", "work_and_edition"]);
const EXTERNAL_TYPES = new Set([
    "external_work_candidate",
    "external_edition_candidate",
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
    return typeof value === "string" && value.length > 0 && value.trim() === value;
}

function boundedText(value, maximum) {
    return text(value) && [...value].length <= maximum;
}

function nullableText(value) {
    return value === null || text(value);
}

function stringList(value) {
    return Array.isArray(value) && value.every(text);
}

function boundedStringList(value, maximumValues, maximumLength) {
    return stringList(value)
        && value.length <= maximumValues
        && value.every((item) => [...item].length <= maximumLength);
}

function isbn13(value) {
    if (!/^97[89][0-9]{10}$/.test(value)) return false;
    const sum = [...value].slice(0, 12).reduce(
        (total, digit, index) => total + Number(digit) * (index % 2 === 0 ? 1 : 3),
        0
    );
    return (10 - (sum % 10)) % 10 === Number(value[12]);
}

function isbn10(value) {
    if (!/^[0-9]{9}[0-9X]$/.test(value)) return false;
    const sum = [...value].reduce((total, digit, index) => (
        total + (digit === "X" ? 10 : Number(digit)) * (10 - index)
    ), 0);
    return sum % 11 === 0;
}

function isbn10As13(value) {
    const payload = `978${value.slice(0, 9)}`;
    const sum = [...payload].reduce(
        (total, digit, index) => total + Number(digit) * (index % 2 === 0 ? 1 : 3),
        0
    );
    return `${payload}${(10 - (sum % 10)) % 10}`;
}

function normalizedQuery(type, value) {
    if (!boundedText(value, 100)) return false;
    if (type === "isbn") return isbn13(value);
    return value.replace(/\s+/gu, " ") === value;
}

function capabilities(value) {
    if (
        !exact(value, CAPABILITY_FIELDS)
        || typeof value.can_add_work_only !== "boolean"
        || typeof value.can_add_edition_specific !== "boolean"
    ) {
        throw new TypeError("The bibliographic discovery capabilities are invalid.");
    }
    return Object.freeze({ ...value });
}

function providerEvidence(value) {
    if (value === null) return null;
    if (
        !exact(value, EVIDENCE_FIELDS)
        || !/^[a-z][a-z0-9_]{0,63}$/.test(value.provider_key)
        || !boundedText(value.provider_record_id, 191)
        || !(value.provider_work_id === null || boundedText(value.provider_work_id, 191))
        || !boundedText(value.retrieved_at, 35)
        || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/.test(value.retrieved_at)
        || Number.isNaN(Date.parse(value.retrieved_at))
        || !MATCH_METHODS.has(value.match_method)
    ) {
        throw new TypeError("The bibliographic discovery evidence is invalid.");
    }
    return Object.freeze({ ...value });
}

function providerAttempt(value) {
    const noFailure = value?.status === "candidates" || value?.status === "miss";
    if (
        !exact(value, ATTEMPT_FIELDS)
        || !/^[a-z][a-z0-9_]{0,63}$/.test(value.provider_key)
        || !PROVIDER_STATUSES.has(value.status)
        || !(value.failure_reason === null || FAILURE_REASONS.has(value.failure_reason))
        || (noFailure ? value.failure_reason !== null : value.failure_reason === null)
    ) {
        throw new TypeError("The bibliographic discovery provider attempt is invalid.");
    }
    return Object.freeze({ ...value });
}

function attemptsMatchState(status, attempts) {
    if (attempts.length === 0) {
        return status !== "configuration_failure"
            && status !== "invalid_provider_response";
    }
    const statuses = attempts.map((attempt) => attempt.status);
    const candidateCount = statuses.filter((value) => value === "candidates").length;
    if (status === "results") {
        return candidateCount === 1 && statuses.at(-1) === "candidates";
    }
    if (candidateCount > 0) return false;
    if (status === "no_results") return statuses.every((value) => value === "miss");
    if (status === "configuration_failure") {
        return statuses.every((value) => value === "configuration_error");
    }
    if (status === "invalid_provider_response") {
        return statuses.includes("invalid_response");
    }
    return !statuses.includes("invalid_response")
        && !statuses.every((value) => value === "configuration_error")
        && statuses.some((value) => value !== "miss");
}

export function readBibliographicCandidate(value) {
    if (
        !exact(value, CANDIDATE_FIELDS)
        || !boundedText(value.candidate_id, 191)
        || !CANDIDATE_TYPES.has(value.type)
        || !(value.work_id === null || boundedText(value.work_id, 191))
        || !(value.edition_id === null || boundedText(value.edition_id, 191))
        || !boundedText(value.title, 512)
        || !(value.subtitle === null || boundedText(value.subtitle, 512))
        || !boundedStringList(value.contributors, 32, 255)
        || !boundedStringList(value.languages, 16, 16)
        || !boundedStringList(value.publishers, 16, 255)
        || !(value.publication_date === null || boundedText(value.publication_date, 64))
        || !(value.page_count === null || (Number.isInteger(value.page_count) && value.page_count > 0 && value.page_count <= 100000))
        || !(value.format === null || boundedText(value.format, 128))
        || !(value.isbn_10 === null || isbn10(value.isbn_10))
        || !(value.isbn_13 === null || isbn13(value.isbn_13))
        || (value.isbn_10 !== null && value.isbn_13 === null)
        || (value.isbn_10 !== null && isbn10As13(value.isbn_10) !== value.isbn_13)
        || !Number.isInteger(value.presentation_order)
        || value.presentation_order < 0
        || value.presentation_order > 10000
    ) {
        throw new TypeError("The bibliographic discovery result is invalid.");
    }

    const localWork = value.type === "local_work";
    const localEdition = value.type === "local_edition";
    const external = value.type.startsWith("external_");
    if (
        (localWork && (!text(value.work_id) || value.edition_id !== null))
        || (localEdition && (!text(value.work_id) || !text(value.edition_id)))
        || (external && (value.work_id !== null || value.edition_id !== null))
    ) {
        throw new TypeError("The bibliographic discovery identity is invalid.");
    }

    const decodedCapabilities = capabilities(value.capabilities);
    const evidence = providerEvidence(value.provider_evidence);
    if ((external && evidence === null) || (!external && evidence !== null)) {
        throw new TypeError("The bibliographic discovery source identity is invalid.");
    }

    const hasWorkBasis = evidence?.provider_work_id !== null
        || value.contributors.length > 0;
    const expectedCapabilities = localWork
        ? [true, false]
        : localEdition
            ? [true, true]
            : value.type === "external_work_candidate"
                ? [true, false]
                : [hasWorkBasis, value.isbn_13 !== null || value.isbn_10 !== null || hasWorkBasis];
    if (
        decodedCapabilities.can_add_work_only !== expectedCapabilities[0]
        || decodedCapabilities.can_add_edition_specific !== expectedCapabilities[1]
        || (value.type === "external_work_candidate" && evidence.provider_work_id === null)
    ) {
        throw new TypeError("The bibliographic discovery capabilities are inconsistent.");
    }

    if (
        !external
        && (
            value.subtitle !== null
            || value.languages.length > 0
            || value.publishers.length > 0
            || value.publication_date !== null
            || value.page_count !== null
            || value.format !== null
            || (localWork && (value.isbn_10 !== null || value.isbn_13 !== null))
        )
    ) {
        throw new TypeError("The local bibliographic presentation is inconsistent.");
    }

    return Object.freeze({
        ...value,
        contributors: Object.freeze([...value.contributors]),
        languages: Object.freeze([...value.languages]),
        publishers: Object.freeze([...value.publishers]),
        provider_evidence: evidence,
        capabilities: decodedCapabilities,
    });
}

export function readBibliographicDiscovery(value) {
    if (
        !exact(value, DISCOVERY_FIELDS)
        || !exact(value.query, QUERY_FIELDS)
        || !QUERY_TYPES.has(value.query.type)
        || !normalizedQuery(value.query.type, value.query.normalized)
        || !STATUSES.has(value.status)
        || !(value.discovery_id === null || /^lookup-[0-9a-f]{32}$/.test(value.discovery_id))
        || !Array.isArray(value.results)
        || !Array.isArray(value.provider_attempts)
    ) {
        throw new TypeError("The bibliographic discovery response is invalid.");
    }

    const results = value.results.map(readBibliographicCandidate);
    const attempts = value.provider_attempts.map(providerAttempt);
    const hasExternal = results.some((candidate) => candidate.type.startsWith("external_"));
    const hasLocal = results.some((candidate) => candidate.type.startsWith("local_"));
    const hasWrongQueryCandidate = results.some((candidate) => (
        value.query.type === "isbn"
            ? candidate.type !== "local_edition"
                && candidate.type !== "external_edition_candidate"
            : false
    ));
    const hasWrongIsbn = results.some((candidate) => (
        value.query.type === "isbn" && candidate.isbn_13 !== value.query.normalized
    ));
    const hasWrongMatchMethod = results.some((candidate) => (
        EXTERNAL_TYPES.has(candidate.type)
        && candidate.provider_evidence.match_method
            !== (value.query.type === "isbn" ? "exact_isbn" : "text_search")
    ));
    const hasWrongAttemptCount = value.query.type === "isbn"
        ? attempts.length !== 0
        : value.status === "results"
            ? (hasLocal ? attempts.length !== 0 : attempts.length < 1 || attempts.length > 2)
            : attempts.length !== 2;
    if (
        (hasExternal && !text(value.discovery_id))
        || (!hasExternal && value.discovery_id !== null)
        || (hasExternal && hasLocal)
        || hasWrongQueryCandidate
        || hasWrongIsbn
        || hasWrongMatchMethod
        || hasWrongAttemptCount
        || !attemptsMatchState(value.status, attempts)
        || (value.status === "results" && results.length === 0)
        || (value.status !== "results" && results.length > 0)
    ) {
        throw new TypeError("The bibliographic discovery state is invalid.");
    }

    return Object.freeze({
        ...value,
        query: Object.freeze({ ...value.query }),
        results: Object.freeze(results),
        provider_attempts: Object.freeze(attempts),
    });
}

export function readBibliographicMaterialization(value, intent) {
    if (
        !MATERIALIZATION_INTENTS.has(intent)
        || !exact(value, MATERIALIZATION_FIELDS)
        || !boundedText(value.work_id, 191)
        || !nullableText(value.edition_id)
        || typeof value.reused !== "boolean"
        || (intent === "work_only" && value.edition_id !== null)
        || (intent === "work_and_edition" && !boundedText(value.edition_id, 191))
    ) {
        throw new TypeError("The bibliographic materialization response is invalid.");
    }
    return Object.freeze({ ...value });
}
