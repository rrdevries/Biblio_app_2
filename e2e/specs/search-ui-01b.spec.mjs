import { mkdir } from "node:fs/promises";
import { expect, test } from "@playwright/test";

const PAGE = "/zoeken/";
const SEARCH_ROUTE = "**/wp-json/biblio/v1/me/bibliographic-searches";
const AUTHOR_WORKS_ROUTE = "**/wp-json/biblio/v1/me/bibliographic-author-works";
const WORK_EDITIONS_ROUTE = "**/wp-json/biblio/v1/me/bibliographic-work-editions";
const SHOTS = ".local/search-ui-01b-visuals";

function identifier(index, type) {
    return `search-${type}-${index.toString(16).padStart(64, "0")}`;
}

function attempt(overrides = {}) {
    return {
        provider_key: "open_library",
        status: "candidates",
        failure_reason: null,
        ...overrides,
    };
}

function author(index = 1, overrides = {}) {
    return {
        result_id: identifier(index, "author"),
        result_kind: index === 1 ? "local_canonical" : "external_candidate",
        author_id: index === 1 ? `author-${index}` : null,
        display_name: index === 1 ? "Stephen King" : `Stephen King ${index}`,
        author_selector: `private-author-selector-${index}`,
        ...overrides,
    };
}

function work(index = 1, overrides = {}) {
    return {
        result_id: identifier(index + 32, "work"),
        result_kind: index === 1 ? "local_canonical" : "external_candidate",
        work_id: index === 1 ? `work-${index}` : null,
        work_selector: `private-work-selector-${index}`,
        title: index === 1 ? "The Shining" : index === 2 ? "It" : `King-werk ${index}`,
        authors: [{
            author_id: index === 1 ? "author-1" : null,
            display_name: "Stephen King",
        }],
        series: index === 3
            ? [{ series_id: "series-1", display_name: "The Dark Tower", position: "1" }]
            : [],
        ...overrides,
    };
}

function authorWork(index = 2, overrides = {}) {
    return {
        ...work(index),
        provider_identity: index === 1
            ? null
            : { provider_key: "open_library", record_id: `/works/OL${index}W` },
        ...overrides,
    };
}

function edition(workResultId, index = 1, overrides = {}) {
    return {
        result_id: identifier(index + 64, "edition"),
        result_kind: index === 1 ? "local_canonical" : "external_candidate",
        edition_id: index === 1 ? `edition-${index}` : null,
        provider_identity: index === 1
            ? null
            : { provider_key: "open_library", record_id: `/books/OL${index}M` },
        provider_work_identity: index === 1
            ? null
            : { provider_key: "open_library", record_id: "/works/OL2W" },
        parent_work_result_id: workResultId,
        title: index === 1 ? "It" : `It — uitgave ${index}`,
        subtitle: index === 1 ? null : "A Novel",
        contributors: index === 1 ? [] : ["Stephen King"],
        languages: index === 1 ? [] : ["nld"],
        publishers: index === 1 ? [] : ["Luitingh-Sijthoff"],
        publication_date: index === 1 ? null : "2024",
        isbn_10: null,
        isbn_13: index === 1 ? null : "9789021042435",
        format: index === 1 ? null : "Paperback",
        page_count: index === 1 ? null : 1216,
        presentation_order: index - 1,
        requires_materialization: index !== 1,
        can_add_work_only: true,
        can_add_edition_specific: true,
        ...overrides,
    };
}

function searchResponse({
    query = "king",
    authors = [author()],
    works = [work()],
    authorCursor = null,
    workCursor = null,
    authorAttempts = [attempt()],
    workAttempts = [attempt()],
} = {}) {
    return {
        data: {
            query,
            authors: { items: authors, next_cursor: authorCursor, provider_attempts: authorAttempts },
            works: { items: works, next_cursor: workCursor, provider_attempts: workAttempts },
        },
    };
}

function pageResponse(items, { cursor = null, attempts = [attempt()] } = {}) {
    return { data: { items, next_cursor: cursor, provider_attempts: attempts } };
}

async function routeAll(page, { searchHandler, authorWorksHandler, editionsHandler }) {
    await page.route(SEARCH_ROUTE, async (route) => {
        expect(route.request().method()).toBe("POST");
        await searchHandler(route, route.request().postDataJSON());
    });
    await page.route(AUTHOR_WORKS_ROUTE, async (route) => {
        expect(route.request().method()).toBe("POST");
        await authorWorksHandler(route, route.request().postDataJSON());
    });
    await page.route(WORK_EDITIONS_ROUTE, async (route) => {
        expect(route.request().method()).toBe("POST");
        await editionsHandler(route, route.request().postDataJSON());
    });
}

async function openAndSearch(page, query = "king") {
    await page.goto(PAGE);
    const field = page.getByRole("searchbox", { name: "Zoek op titel of auteur" });
    await field.fill(query);
    await field.press("Enter");
    await expect(page.getByRole("heading", { level: 1, name: "Zoekresultaten" })).toBeVisible();
}

async function noHorizontalOverflow(page) {
    const dimensions = await page.evaluate(() => ({
        document: document.documentElement.scrollWidth,
        viewport: document.documentElement.clientWidth,
    }));
    expect(dimensions.document).toBeLessThanOrEqual(dimensions.viewport);
}

test("Author to pageable Works to pageable Editions restores both parent states", async ({ page }) => {
    const authorRequests = [];
    const editionRequests = [];
    await routeAll(page, {
        searchHandler: (route) => route.fulfill({ status: 200, json: searchResponse() }),
        authorWorksHandler: (route, body) => {
            authorRequests.push(body);
            return route.fulfill({
                status: 200,
                json: body.cursor === "author-works-cursor"
                    ? pageResponse([authorWork(3)], { cursor: null })
                    : pageResponse([authorWork(2)], { cursor: "author-works-cursor" }),
            });
        },
        editionsHandler: (route, body) => {
            editionRequests.push(body);
            return route.fulfill({
                status: 200,
                json: body.cursor === "edition-cursor"
                    ? pageResponse([edition(identifier(34, "work"), 2)], { cursor: null })
                    : pageResponse([edition(identifier(34, "work"))], { cursor: "edition-cursor" }),
            });
        },
    });
    await openAndSearch(page);

    await page.getByRole("button", { name: "Bekijk werken van Stephen King" }).click();
    await expect(page.getByRole("heading", { level: 2, name: "Stephen King" })).toBeFocused();
    await expect(page.getByRole("heading", { level: 2, name: "Werken" })).toBeVisible();
    await expect(page.getByText("It", { exact: true })).toBeVisible();
    expect(authorRequests[0]).toEqual({ author_selector: "private-author-selector-1", cursor: null });
    await page.getByRole("button", { name: "Meer werken" }).click();
    await expect(page.locator(".biblio-ui__author-work-results .biblio-ui__work-result")).toHaveCount(2);
    await expect(page.locator('[data-search-result-group="author-works"][data-search-result-index="1"]')).toBeFocused();
    expect(authorRequests[1]).toEqual({ author_selector: "private-author-selector-1", cursor: "author-works-cursor" });

    await page.getByRole("button", { name: "Bekijk uitgaven van It" }).click();
    await expect(page.getByRole("heading", { level: 2, name: "It", exact: true })).toBeFocused();
    await expect(page.getByRole("heading", { level: 2, name: "Uitgaven" })).toBeVisible();
    await expect(page.locator(".biblio-ui__edition-result")).toHaveCount(1);
    await expect(page.locator(".biblio-ui__edition-result")).not.toContainText("ISBN");
    expect(editionRequests[0]).toEqual({ work_selector: "private-work-selector-2", cursor: null });
    await page.getByRole("button", { name: "Meer uitgaven" }).click();
    await expect(page.locator(".biblio-ui__edition-result")).toHaveCount(2);
    await expect(page.getByText("Nederlands · 2024 · Luitingh-Sijthoff · Paperback")).toBeVisible();
    await expect(page.getByText("ISBN", { exact: true })).toBeVisible();
    await expect(page.getByText("9789021042435")).toBeVisible();
    expect(editionRequests[1]).toEqual({ work_selector: "private-work-selector-2", cursor: "edition-cursor" });

    await page.getByRole("button", { name: "← Terug" }).click();
    await expect(page.getByRole("heading", { level: 2, name: "Stephen King" })).toBeVisible();
    await expect(page.locator(".biblio-ui__author-work-results .biblio-ui__work-result")).toHaveCount(2);
    await expect(page.getByRole("button", { name: "Bekijk uitgaven van It" })).toBeFocused();
    expect(authorRequests).toHaveLength(2);
    await page.getByRole("button", { name: "← Terug naar zoekresultaten" }).click();
    await expect(page.getByRole("tab", { name: "Alles" })).toHaveAttribute("aria-selected", "true");
    await expect(page.getByRole("button", { name: "Bekijk werken van Stephen King" })).toBeFocused();
    await expect(page.locator("body")).not.toContainText(/private-(?:author|work)-selector/);
});

test("top-level Book uses the shared Editions flow and restores the active tab", async ({ page }) => {
    const requests = [];
    await routeAll(page, {
        searchHandler: (route) => route.fulfill({ status: 200, json: searchResponse() }),
        authorWorksHandler: (route) => route.fulfill({ status: 500 }),
        editionsHandler: (route, body) => {
            requests.push(body);
            return route.fulfill({ status: 200, json: pageResponse([edition(identifier(33, "work"))]) });
        },
    });
    await openAndSearch(page);
    await page.getByRole("tab", { name: "Boeken" }).click();
    await page.getByRole("button", { name: "Bekijk uitgaven van The Shining" }).click();
    await expect(page.getByRole("heading", { level: 2, name: "Uitgaven" })).toBeVisible();
    expect(requests).toEqual([{ work_selector: "private-work-selector-1", cursor: null }]);
    await page.getByRole("button", { name: "← Terug" }).click();
    await expect(page.getByRole("tab", { name: "Boeken" })).toHaveAttribute("aria-selected", "true");
    await expect(page.getByRole("button", { name: "Bekijk uitgaven van The Shining" })).toBeFocused();
});

test("empty, partial failure and stale selector states stay truthful", async ({ page }) => {
    let authorMode = "empty";
    let editionMode = "partial";
    let searchRequests = 0;
    let authorRequests = 0;
    await routeAll(page, {
        searchHandler: (route) => {
            searchRequests += 1;
            return route.fulfill({ status: 200, json: searchResponse() });
        },
        authorWorksHandler: (route) => {
            authorRequests += 1;
            return route.fulfill({ status: 200, json: pageResponse([], {
                attempts: [authorMode === "empty"
                    ? attempt({ status: "miss" })
                    : attempt({ status: "unavailable", failure_reason: "timeout" })],
            }) });
        },
        editionsHandler: (route) => {
            if (editionMode === "stale") {
                return route.fulfill({
                    status: 400,
                    json: {
                        code: "biblio_invalid_field_syntax",
                        message: "private mapping detail",
                        data: { status: 400 },
                    },
                });
            }
            if (editionMode === "mismatch") {
                return route.fulfill({
                    status: 200,
                    json: pageResponse([edition(identifier(999, "work"))]),
                });
            }
            return route.fulfill({
                status: 200,
                json: pageResponse([edition(identifier(33, "work"))], {
                    attempts: [attempt({ status: "unavailable", failure_reason: "network" })],
                }),
            });
        },
    });
    await openAndSearch(page);
    await page.getByRole("button", { name: "Bekijk werken van Stephen King" }).click();
    await expect(page.getByRole("heading", { name: "Geen werken gevonden" })).toBeVisible();
    await page.getByRole("button", { name: "← Terug naar zoekresultaten" }).click();
    authorMode = "failure";
    await page.getByRole("button", { name: "Bekijk werken van Stephen King" }).click();
    await expect(page.getByRole("heading", { name: "Werken konden niet volledig worden geladen" })).toBeVisible();
    await page.getByRole("button", { name: "Opnieuw proberen" }).click();
    await expect.poll(() => authorRequests).toBe(3);
    expect(searchRequests).toBe(1);
    await page.getByRole("button", { name: "← Terug naar zoekresultaten" }).click();

    await page.getByRole("button", { name: "Bekijk uitgaven van The Shining" }).click();
    await expect(page.locator(".biblio-ui__edition-result")).toHaveCount(1);
    await expect(page.locator(".biblio-ui__search-partial")).toContainText("Externe uitgaven konden niet volledig worden geladen.");
    await page.getByRole("button", { name: "← Terug" }).click();
    editionMode = "mismatch";
    await page.getByRole("button", { name: "Bekijk uitgaven van The Shining" }).click();
    await expect(page.getByRole("alert").getByText(/gegevens niet veilig lezen/)).toBeVisible();
    await expect(page.locator(".biblio-ui__edition-result")).toHaveCount(0);
    await page.getByRole("button", { name: "← Terug" }).click();
    editionMode = "stale";
    await page.getByRole("button", { name: "Bekijk uitgaven van The Shining" }).click();
    await expect(page.getByRole("alert").getByText(/Dit resultaat is niet meer actueel/)).toBeVisible();
    await expect(page.getByRole("button", { name: "Opnieuw proberen" })).toHaveCount(0);
    await expect(page.locator("body")).not.toContainText(/mapping|selector|private/i);
});

test("a new search aborts pending drill-down and prevents stale replacement", async ({ page }) => {
    let releaseAuthor;
    let markStarted;
    const gate = new Promise((resolve) => { releaseAuthor = resolve; });
    const started = new Promise((resolve) => { markStarted = resolve; });
    await routeAll(page, {
        searchHandler: (route, body) => route.fulfill({
            status: 200,
            json: body.query === "king"
                ? searchResponse()
                : searchResponse({
                    query: "niemand",
                    authors: [],
                    works: [],
                    authorAttempts: [attempt({ status: "miss" })],
                    workAttempts: [attempt({ status: "miss" })],
                }),
        }),
        authorWorksHandler: async (route) => {
            markStarted();
            await gate;
            try {
                await route.fulfill({ status: 200, json: pageResponse([authorWork(2)]) });
            } catch {
                // The browser may already have cancelled the deliberately stale request.
            }
        },
        editionsHandler: (route) => route.fulfill({ status: 500 }),
    });
    await openAndSearch(page);
    await page.getByRole("button", { name: "Bekijk werken van Stephen King" }).click();
    await started;
    const field = page.getByRole("searchbox", { name: "Zoek op titel of auteur" });
    await field.fill("niemand");
    await field.press("Enter");
    releaseAuthor();

    await expect(page.getByRole("heading", { name: "Geen auteurs of boeken gevonden" })).toBeVisible();
    await expect(page.getByRole("heading", { name: "Werken", exact: true })).toHaveCount(0);
    await expect(page.getByRole("tab", { name: "Alles" })).toHaveAttribute("aria-selected", "true");
});

test("Author and Edition drill-down remain accessible and overflow-free at 1440, 900 and 390", async ({ page }) => {
    await routeAll(page, {
        searchHandler: (route) => route.fulfill({ status: 200, json: searchResponse() }),
        authorWorksHandler: (route) => route.fulfill({ status: 200, json: pageResponse([
            authorWork(2),
            authorWork(3),
        ]) }),
        editionsHandler: (route) => route.fulfill({ status: 200, json: pageResponse([
            edition(identifier(34, "work")),
            edition(identifier(34, "work"), 2),
        ]) }),
    });
    await mkdir(SHOTS, { recursive: true });

    for (const viewport of [
        { name: "desktop-1440", width: 1440, height: 1000 },
        { name: "tablet-900", width: 900, height: 1000 },
        { name: "mobile-390", width: 390, height: 844 },
    ]) {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        await openAndSearch(page);
        await page.getByRole("button", { name: "Bekijk werken van Stephen King" }).click();
        await expect(page.getByRole("heading", { level: 2, name: "Stephen King" })).toBeFocused();
        await noHorizontalOverflow(page);
        await page.screenshot({ path: `${SHOTS}/${viewport.name}-author-works.png`, fullPage: true });
        await page.getByRole("button", { name: "Bekijk uitgaven van It" }).click();
        await expect(page.getByRole("heading", { level: 2, name: "It", exact: true })).toBeFocused();
        await noHorizontalOverflow(page);
        await page.screenshot({ path: `${SHOTS}/${viewport.name}-work-editions.png`, fullPage: true });
    }
});
