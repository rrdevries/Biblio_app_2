import { mkdir } from "node:fs/promises";
import { expect, test } from "@playwright/test";

const PAGE = "/zoeken/";
const SEARCH_ROUTE = "**/wp-json/biblio/v1/me/bibliographic-searches";
const SHOTS = ".local/search-ui-01a-f1-visuals";

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
        display_name: index === 1
            ? "Ursula K. Le Guin"
            : "Ursula Kroeber Le Guin met een uitzonderlijk lange auteursnaam",
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
        title: index === 1
            ? "The Dispossessed"
            : "The Left Hand of Darkness: een bijzonder lange boektitel voor de responsive controle",
        authors: [{
            author_id: index === 1 ? "author-1" : null,
            display_name: index === 1 ? "Ursula K. Le Guin" : "Ursula Le Guin",
        }],
        series: index === 1
            ? [{ series_id: "series-1", display_name: "Hainish Cycle", position: "5" }]
            : [],
        ...overrides,
    };
}

function response({
    query = "Ursula Le Guin",
    authors = [author()],
    works = [work()],
    authorCursor = "author-cursor-1",
    workCursor = "work-cursor-1",
    authorAttempts = [attempt()],
    workAttempts = [attempt()],
} = {}) {
    return {
        data: {
            query,
            authors: {
                items: authors,
                next_cursor: authorCursor,
                provider_attempts: authorAttempts,
            },
            works: {
                items: works,
                next_cursor: workCursor,
                provider_attempts: workAttempts,
            },
        },
    };
}

async function routeSearch(page, handler) {
    await page.route(SEARCH_ROUTE, async (route) => {
        const request = route.request();
        expect(request.method()).toBe("POST");
        await handler(route, request.postDataJSON());
    });
}

async function open(page) {
    await page.goto(PAGE);
    await expect(page.getByRole("heading", { level: 1, name: "Zoeken" })).toBeVisible();
}

async function search(page, query = "Ursula Le Guin") {
    const field = page.getByRole("searchbox", { name: "Zoek op titel of auteur" });
    await field.fill(query);
    await field.press("Enter");
}

async function noHorizontalOverflow(page) {
    const dimensions = await page.evaluate(() => ({
        document: document.documentElement.scrollWidth,
        viewport: document.documentElement.clientWidth,
    }));
    expect(dimensions.document).toBeLessThanOrEqual(dimensions.viewport);
}

async function capture(page, name) {
    await mkdir(SHOTS, { recursive: true });
    await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: true });
}

test("mounts as a full App Shell page with truthful idle search", async ({ page }) => {
    await open(page);

    await expect(page.locator("[data-biblio-search-root] .biblio-ui__shell")).toBeVisible();
    await expect(page.getByRole("navigation", { name: "Hoofdnavigatie" })
        .getByRole("link", { name: "Zoeken" })).toHaveAttribute("aria-current", "page");
    await expect(page.getByRole("navigation", { name: "Zoekweergave" })).toBeHidden();
    await expect(page.getByRole("heading", { level: 2, name: "Waar wil je naar zoeken?" })).toBeVisible();
    await expect(page.getByText("Zoeken op ISBN wordt in een volgende stap aangesloten.")).toBeVisible();
    await expect(page.getByRole("dialog")).toHaveCount(0);
    await expect(page.getByText(/Series/)).toHaveCount(0);
});

test("keyboard submit renders separate Author and Book groups without exposing selectors", async ({ page }) => {
    const requests = [];
    await routeSearch(page, async (route, body) => {
        requests.push(body);
        await route.fulfill({ status: 200, json: response() });
    });
    await open(page);
    await search(page);

    await expect(page.getByRole("heading", { level: 2, name: "Auteurs" })).toBeVisible();
    await expect(page.getByRole("heading", { level: 2, name: "Boeken" })).toBeVisible();
    await expect(page.getByRole("heading", { level: 1, name: "Zoekresultaten" })).toBeVisible();
    await expect(page.getByText("Resultaten voor “Ursula Le Guin”")).toBeVisible();
    await expect(page.getByRole("tab", { name: "Alles" })).toHaveAttribute("aria-selected", "true");
    await expect(page.locator(".biblio-ui__author-result")).toHaveCount(1);
    await expect(page.locator(".biblio-ui__work-result")).toHaveCount(1);
    await expect(page.getByText("Hainish Cycle · deel 5")).toBeVisible();
    await expect(page.locator("body")).not.toContainText("private-author-selector");
    await expect(page.locator("body")).not.toContainText("private-work-selector");
    await expect(page.getByRole("button", { name: "Bekijk alle boeken" })).toBeVisible();
    await expect(page.getByRole("button", { name: "Bekijk alle auteurs" })).toBeVisible();
    await expect(page.getByRole("complementary", { name: "Zoekhulp en zoekstatus" })).toContainText("Zoektip");
    expect(requests).toEqual([{
        query: "Ursula Le Guin",
        author_cursor: null,
        work_cursor: null,
    }]);

    const coverRatio = await page.locator(".biblio-ui__search-cover").evaluate((node) => {
        const box = node.getBoundingClientRect();
        return box.height / box.width;
    });
    expect(coverRatio).toBeGreaterThan(1.45);
});

test("keeps the search context visible and announces a genuinely pending initial request", async ({ page }) => {
    let releaseResponse;
    let markStarted;
    const responseGate = new Promise((resolve) => { releaseResponse = resolve; });
    const requestStarted = new Promise((resolve) => { markStarted = resolve; });
    await routeSearch(page, async (route) => {
        markStarted();
        await responseGate;
        await route.fulfill({ status: 200, json: response() });
    });
    await open(page);

    await page.getByRole("searchbox", { name: "Zoek op titel of auteur" }).fill("Ursula Le Guin");
    await page.getByRole("searchbox", { name: "Zoek op titel of auteur" }).press("Enter");
    await requestStarted;
    await expect(page.locator(".biblio-ui__search-results")).toHaveAttribute("aria-busy", "true");
    await expect(page.getByText("Auteurs en boeken zoeken…")).toBeVisible();
    await expect(page.locator('form[role="search"]')).toBeVisible();

    releaseResponse();
    await expect(page.locator(".biblio-ui__search-results")).toHaveAttribute("aria-busy", "false");
    await expect(page.getByRole("heading", { level: 2, name: "Auteurs" })).toBeVisible();
});

test("Author pagination appends, preserves Books and moves focus predictably", async ({ page }) => {
    const requests = [];
    await routeSearch(page, async (route, body) => {
        requests.push(body);
        if (body.author_cursor === "author-cursor-1") {
            await route.fulfill({ status: 200, json: response({
                authors: [author(2)],
                works: [work(2)],
                authorCursor: null,
                workCursor: null,
            }) });
            return;
        }
        await route.fulfill({ status: 200, json: response() });
    });
    await open(page);
    await search(page);
    await page.getByRole("tab", { name: "Auteurs" }).click();
    const originalBook = page.locator(".biblio-ui__work-result").first();
    await page.getByRole("button", { name: "Meer auteurs" }).click();

    await expect(page.locator(".biblio-ui__author-result")).toHaveCount(2);
    await expect(page.locator(".biblio-ui__work-result")).toHaveCount(0);
    await expect(page.locator('[data-search-result-group="authors"][data-search-result-index="1"]')).toBeFocused();
    await expect(page.locator('[role="status"][aria-live="polite"]')).toHaveText("1 auteur toegevoegd.");
    await page.getByRole("tab", { name: "Alles" }).click();
    await expect(originalBook).toContainText("The Dispossessed");
    await expect(page.locator(".biblio-ui__author-result")).toHaveCount(2);
    expect(requests[1]).toEqual({
        query: "Ursula Le Guin",
        author_cursor: "author-cursor-1",
        work_cursor: null,
    });
});

test("Book pagination appends, preserves Authors and keeps cursors independent", async ({ page }) => {
    const requests = [];
    await routeSearch(page, async (route, body) => {
        requests.push(body);
        if (body.work_cursor === "work-cursor-1") {
            await route.fulfill({ status: 200, json: response({
                authors: [author(2)],
                works: [work(2)],
                authorCursor: null,
                workCursor: null,
            }) });
            return;
        }
        await route.fulfill({ status: 200, json: response() });
    });
    await open(page);
    await search(page);
    await page.getByRole("tab", { name: "Boeken" }).click();
    await page.getByRole("button", { name: "Meer boeken" }).click();

    await expect(page.locator(".biblio-ui__work-result")).toHaveCount(2);
    await expect(page.locator(".biblio-ui__author-result")).toHaveCount(0);
    await expect(page.locator('[data-search-result-group="works"][data-search-result-index="1"]')).toBeFocused();
    await page.getByRole("tab", { name: "Alles" }).click();
    await expect(page.locator(".biblio-ui__author-result")).toHaveCount(1);
    await expect(page.locator(".biblio-ui__work-result")).toHaveCount(2);
    expect(requests[1]).toEqual({
        query: "Ursula Le Guin",
        author_cursor: null,
        work_cursor: "work-cursor-1",
    });
});

test("pagination disables competing controls and restores focus after an empty page", async ({ page }) => {
    let releaseContinuation;
    let markContinuationStarted;
    const continuationGate = new Promise((resolve) => { releaseContinuation = resolve; });
    const continuationStarted = new Promise((resolve) => { markContinuationStarted = resolve; });
    await routeSearch(page, async (route, body) => {
        if (body.author_cursor === "author-cursor-1") {
            markContinuationStarted();
            await continuationGate;
            await route.fulfill({ status: 200, json: response({
                authors: [],
                works: [],
                authorCursor: null,
                workCursor: null,
            }) });
            return;
        }
        await route.fulfill({ status: 200, json: response() });
    });
    await open(page);
    await search(page);
    await page.getByRole("tab", { name: "Auteurs" }).click();

    await page.getByRole("button", { name: "Meer auteurs" }).click();
    await continuationStarted;
    await expect(page.getByRole("button", { name: "Auteurs laden…" })).toBeDisabled();
    await expect(page.getByRole("button", { name: "Meer boeken" })).toHaveCount(0);
    await expect(page.getByRole("tab", { name: "Boeken" })).toBeEnabled();
    await expect(page.getByRole("button", { name: "Zoeken" })).toBeEnabled();
    releaseContinuation();

    await expect(page.getByRole("heading", { level: 2, name: "Auteurs" })).toBeFocused();
    await expect(page.locator('[role="status"][aria-live="polite"]')).toHaveText("Geen nieuwe auteurs geladen.");
    await page.getByRole("tab", { name: "Alles" }).click();
    await expect(page.locator(".biblio-ui__work-result")).toHaveCount(1);
});

test("new submit resets old results and both continuations", async ({ page }) => {
    const requests = [];
    await routeSearch(page, async (route, body) => {
        requests.push(body);
        await route.fulfill({ status: 200, json: body.query === "Ursula Le Guin"
            ? response()
            : response({
                query: "Niemand gevonden",
                authors: [],
                works: [],
                authorCursor: null,
                workCursor: null,
                authorAttempts: [attempt({ status: "miss" })],
                workAttempts: [attempt({ status: "miss" })],
            }) });
    });
    await open(page);
    await search(page);
    await expect(page.getByRole("heading", { level: 2, name: "Boeken" })).toBeVisible();
    await search(page, "Niemand gevonden");

    await expect(page.getByRole("heading", { name: "Geen auteurs of boeken gevonden" })).toBeVisible();
    await expect(page.getByRole("heading", { name: "Auteurs", exact: true })).toHaveCount(0);
    await expect(page.getByRole("heading", { name: "Boeken", exact: true })).toHaveCount(0);
    expect(requests[1]).toEqual({
        query: "Niemand gevonden",
        author_cursor: null,
        work_cursor: null,
    });
});

test("renders Authors-only, Books-only, partial and full transport failure truthfully", async ({ page }) => {
    let mode = "authors";
    await routeSearch(page, async (route) => {
        if (mode === "failure") {
            await route.abort("failed");
            return;
        }
        if (mode === "session") {
            await route.fulfill({
                status: 403,
                json: {
                    code: "rest_cookie_invalid_nonce",
                    message: "private nonce detail",
                    data: { status: 403 },
                },
            });
            return;
        }
        const variants = {
            authors: response({ query: "Alleen auteur", works: [], authorCursor: null, workCursor: null }),
            works: response({ query: "Alleen boek", authors: [], authorCursor: null, workCursor: null }),
            partial: response({
                query: "Gedeeltelijk",
                authors: [],
                authorCursor: null,
                workCursor: null,
                authorAttempts: [attempt({
                    status: "unavailable",
                    failure_reason: "network",
                })],
            }),
        };
        await route.fulfill({ status: 200, json: variants[mode] });
    });
    await open(page);

    await search(page, "Alleen auteur");
    await expect(page.getByRole("heading", { name: "Auteurs" })).toBeVisible();
    await expect(page.getByRole("heading", { name: "Boeken" })).toHaveCount(0);

    mode = "works";
    await search(page, "Alleen boek");
    await expect(page.getByRole("heading", { name: "Auteurs" })).toHaveCount(0);
    await expect(page.getByRole("heading", { name: "Boeken" })).toBeVisible();

    mode = "partial";
    await search(page, "Gedeeltelijk");
    await expect(page.locator(".biblio-ui__search-partial")).toContainText("Externe resultaten konden niet volledig worden geladen.");
    await expect(page.getByRole("heading", { name: "Boeken" })).toBeVisible();
    await expect(page.locator('[role="status"][aria-live="polite"]')).toContainText("Externe resultaten konden niet volledig worden geladen.");

    mode = "session";
    await search(page, "Sessie vernieuwen");
    await expect(page.getByRole("heading", { name: "Zoeken is niet gelukt" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Sessie vernieuwen" })).toHaveAttribute("href", /\/zoeken\/$/);

    mode = "failure";
    await search(page, "Transportfout");
    await expect(page.getByRole("heading", { name: "Zoeken is niet gelukt" })).toBeVisible();
    await expect(page.getByRole("button", { name: "Opnieuw proberen" })).toBeVisible();
    await expect(page.locator("body")).not.toContainText(/HTTP|stack|open_library/i);
});

test("tabs use retained results, previews stay bounded and keyboard navigation sends no request", async ({ page }) => {
    const requests = [];
    const authors = Array.from({ length: 5 }, (_, index) => author(index + 1, {
        display_name: `Auteur ${index + 1}`,
    }));
    const works = Array.from({ length: 6 }, (_, index) => work(index + 1, {
        title: `Boek ${index + 1}`,
    }));
    await routeSearch(page, async (route, body) => {
        requests.push(body);
        await route.fulfill({ status: 200, json: response({
            authors,
            works,
            authorCursor: "author-next",
            workCursor: "work-next",
        }) });
    });
    await open(page);
    await search(page);

    await expect(page.locator(".biblio-ui__work-result")).toHaveCount(5);
    await expect(page.locator(".biblio-ui__author-result")).toHaveCount(4);
    await expect(page.getByRole("button", { name: "Meer boeken" })).toHaveCount(0);
    await page.getByRole("button", { name: "Bekijk alle boeken" }).click();
    await expect(page.getByRole("tab", { name: "Boeken" })).toBeFocused();
    await expect(page.locator(".biblio-ui__work-result")).toHaveCount(6);
    await expect(page.getByRole("button", { name: "Meer boeken" })).toBeVisible();

    await page.getByRole("tab", { name: "Boeken" }).press("ArrowRight");
    await expect(page.getByRole("tab", { name: "Auteurs" })).toBeFocused();
    await expect(page.locator(".biblio-ui__author-result")).toHaveCount(5);
    await page.getByRole("tab", { name: "Auteurs" }).press("Home");
    await expect(page.getByRole("tab", { name: "Alles" })).toBeFocused();
    await expect(page.locator(".biblio-ui__work-result")).toHaveCount(5);
    await expect(page.locator(".biblio-ui__author-result")).toHaveCount(4);
    expect(requests).toHaveLength(1);

    const tabs = page.getByRole("tab");
    await expect(tabs).toHaveCount(3);
    expect(await tabs.allTextContents()).toEqual(["Alles", "Boeken", "Auteurs"]);
    await expect(page.getByRole("tab", { name: "Series" })).toHaveCount(0);
    await expect(page.getByRole("tab", { name: "Collecties" })).toHaveCount(0);
    await expect(page.getByRole("combobox")).toHaveCount(0);
    await expect(page.getByText("Beste match")).toHaveCount(0);
});

test("duplicate Author names stay separate with truthful source context", async ({ page }) => {
    await routeSearch(page, async (route) => {
        await route.fulfill({ status: 200, json: response({
            query: "Peter King",
            authors: [
                author(1, { display_name: "Peter King" }),
                author(2, { display_name: "Peter King" }),
            ],
            works: [],
            authorCursor: null,
            workCursor: null,
        }) });
    });
    await open(page);
    await search(page, "Peter King");

    await expect(page.locator(".biblio-ui__author-result")).toHaveCount(2);
    await expect(page.getByText("In Biblio", { exact: true })).toBeVisible();
    await expect(page.getByText("Uit bibliografische bron", { exact: true })).toBeVisible();
});

test("responsive visual contract stays calm and overflow-free", async ({ page }) => {
    const visualTitles = [
        "The Dispossessed",
        "The Left Hand of Darkness",
        "A Wizard of Earthsea",
        "The Lathe of Heaven",
        "Always Coming Home",
    ];
    await routeSearch(page, async (route) => {
        await route.fulfill({ status: 200, json: response({
            authors: [author(), author(2)],
            works: visualTitles.map((title, index) => work(index + 1, { title })),
            authorCursor: null,
            workCursor: null,
        }) });
    });

    for (const viewport of [
        { name: "desktop-1440", width: 1440, height: 1000 },
        { name: "tablet-900", width: 900, height: 1000 },
        { name: "mobile-390", width: 390, height: 844 },
    ]) {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        await open(page);
        await search(page);
        await expect(page.locator(".biblio-ui__work-result")).toHaveCount(5);
        await noHorizontalOverflow(page);
        const placement = await page.locator(".biblio-ui__search-layout").evaluate((layout) => {
            const main = layout.querySelector(".biblio-ui__search-main").getBoundingClientRect();
            const rail = layout.querySelector(".biblio-ui__search-rail").getBoundingClientRect();
            return { mainRight: main.right, mainBottom: main.bottom, railLeft: rail.left, railTop: rail.top };
        });
        if (viewport.width === 1440) {
            expect(placement.railLeft).toBeGreaterThan(placement.mainRight);
        } else {
            expect(placement.railTop).toBeGreaterThanOrEqual(placement.mainBottom);
        }
        await capture(page, viewport.name);
    }
});
