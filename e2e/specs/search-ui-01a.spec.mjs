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
        match_quality: "broader",
        name_group_id: `author-name-${index.toString(16).padStart(64, "0")}`,
        disambiguation: index === 1 ? {
            representative_work_title: "The Dispossessed",
            linked_work_count: 1,
            birth_year: null,
        } : {
            representative_work_title: "The Left Hand of Darkness",
            linked_work_count: null,
            birth_year: 1929,
        },
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

async function responsiveGeometry(page) {
    return page.locator(".biblio-ui__search-layout").evaluate((layout) => {
        const main = layout.querySelector(".biblio-ui__search-main").getBoundingClientRect();
        const rail = layout.querySelector(".biblio-ui__search-rail").getBoundingClientRect();
        const preview = layout.querySelector(".biblio-ui__work-results--preview").getBoundingClientRect();
        const cards = [...layout.querySelectorAll(".biblio-ui__work-results--preview .biblio-ui__work-result")];
        const cardRects = cards.map((card) => card.getBoundingClientRect());
        const firstRowTop = cardRects[0].top;
        const columns = cardRects.filter((rect) => Math.abs(rect.top - firstRowTop) < 1).length;
        const contentStaysInsideCards = cards.every((card, index) => {
            const cardRect = cardRects[index];
            return [...card.children].every((child) => {
                const childRect = child.getBoundingClientRect();
                return childRect.left >= cardRect.left - 0.5 && childRect.right <= cardRect.right + 0.5;
            });
        });

        return {
            columns,
            contentStaysInsideCards,
            mainRight: main.right,
            mainBottom: main.bottom,
            maxCardRight: Math.max(...cardRects.map((rect) => rect.right)),
            previewRight: preview.right,
            railLeft: rail.left,
            railTop: rail.top,
        };
    });
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
    const rightRail = page.getByRole("complementary", { name: "Zoekscope, zoekstatus en zoektip" });
    await expect(rightRail).toBeVisible();
    await expect(rightRail).toContainText("Zoeken in");
    await expect(rightRail).toContainText("Zoektip");
    await expect(page.getByRole("complementary", { name: "Zoekhulp en zoekstatus" })).toHaveCount(0);
    await expect(rightRail.getByRole("status")).toHaveCount(0);
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
            canonical_partial: response({
                query: "Canoniek ondanks bronstoring",
                works: [],
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

    mode = "canonical_partial";
    await search(page, "Canoniek ondanks bronstoring");
    await expect(page.getByRole("heading", { name: "Ursula K. Le Guin" })).toBeVisible();
    await expect(page.getByText("Auteur van The Dispossessed")).toBeVisible();
    await expect(page.locator(".biblio-ui__search-partial")).toContainText("Externe resultaten konden niet volledig worden geladen.");

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
    await expect(page.locator(".biblio-ui__author-result")).toHaveCount(3);
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
    await expect(page.locator(".biblio-ui__author-result")).toHaveCount(3);
    expect(requests).toHaveLength(1);

    const tabs = page.getByRole("tab");
    await expect(tabs).toHaveCount(3);
    expect(await tabs.allTextContents()).toEqual(["Alles", "Boeken", "Auteurs"]);
    await expect(page.getByRole("tab", { name: "Series" })).toHaveCount(0);
    await expect(page.getByRole("tab", { name: "Collecties" })).toHaveCount(0);
    await expect(page.getByRole("combobox")).toHaveCount(0);
    await expect(page.getByText("Beste match")).toHaveCount(0);
});

test("Author disclosure reveals loaded candidates before one-page continuation", async ({ page }) => {
    const requests = [];
    const sameNameGroup = `author-name-${"8".repeat(64)}`;
    const authors = [author(1, {
        display_name: "Stephen King",
        match_quality: "exact",
        name_group_id: sameNameGroup,
        disambiguation: {
            representative_work_title: "It",
            linked_work_count: 1,
            birth_year: null,
        },
    }), ...Array.from({ length: 9 }, (_, offset) => author(offset + 2, {
        display_name: offset < 4 ? "Stephen King" : `Stephen King ${offset + 2}`,
        match_quality: offset < 4 ? "exact" : "broader",
        name_group_id: offset < 4
            ? sameNameGroup
            : `author-name-${(offset + 20).toString(16).padStart(64, "0")}`,
        disambiguation: {
            representative_work_title: offset < 4 ? null : `Werk ${offset + 2}`,
            linked_work_count: null,
            birth_year: offset < 4 ? null : 1900 + offset,
        },
    }))];
    await routeSearch(page, async (route, body) => {
        requests.push(body);
        await route.fulfill({ status: 200, json: body.author_cursor === null
            ? response({
                query: "Stephen King",
                authors,
                works: [],
                authorCursor: "author-next",
                workCursor: null,
            })
            : response({
                query: "Stephen King",
                authors: [],
                works: [],
                authorCursor: "author-after-empty",
                workCursor: null,
            }) });
    });
    await open(page);
    await search(page, "Stephen King");
    await page.getByRole("tab", { name: "Auteurs" }).click();

    await expect(page.locator(".biblio-ui__author-result")).toHaveCount(4);
    await page.getByRole("button", { name: "Meer auteurs" }).click();
    await expect(page.locator(".biblio-ui__author-result")).toHaveCount(9);
    expect(requests).toHaveLength(1);
    await page.getByRole("button", { name: "Meer auteurs" }).click();
    await expect(page.locator(".biblio-ui__author-result")).toHaveCount(10);
    expect(requests).toHaveLength(1);

    await page.getByRole("button", { name: "Meer auteurs" }).click();
    await expect(page.getByRole("button", { name: "Meer auteurs" })).toBeFocused();
    await expect(page.locator('[role="status"][aria-live="polite"]')).toHaveText(
        "Nog geen nieuwe auteurs; er zijn meer resultaten beschikbaar."
    );
    expect(requests).toHaveLength(2);
    expect(requests[1]).toEqual({
        query: "Stephen King",
        author_cursor: "author-next",
        work_cursor: null,
    });
});

test("external-only Authors are primary results without an Other Authors frame", async ({ page }) => {
    await routeSearch(page, async (route) => {
        await route.fulfill({ status: 200, json: response({
            query: "Octavia Butler",
            authors: [author(2, {
                display_name: "Octavia Butler",
                match_quality: "exact",
                disambiguation: {
                    representative_work_title: "Kindred",
                    linked_work_count: null,
                    birth_year: 1947,
                },
            })],
            works: [],
            authorCursor: null,
            workCursor: null,
        }) });
    });
    await open(page);
    await search(page, "Octavia Butler");
    await page.getByRole("tab", { name: "Auteurs" }).click();

    await expect(page.getByText("Geboren 1947 · Auteur van Kindred")).toBeVisible();
    await expect(page.getByText("Andere auteurs", { exact: true })).toHaveCount(0);
});

test("same-name Authors stay separate with human context and no source labels", async ({ page }) => {
    const nameGroupId = `author-name-${"9".repeat(64)}`;
    await routeSearch(page, async (route) => {
        await route.fulfill({ status: 200, json: response({
            query: "Peter King",
            authors: [
                author(1, {
                    display_name: "Peter King",
                    match_quality: "exact",
                    name_group_id: nameGroupId,
                    disambiguation: {
                        representative_work_title: "Work Alpha",
                        linked_work_count: 1,
                        birth_year: null,
                    },
                }),
                author(2, {
                    display_name: "Peter King",
                    match_quality: "exact",
                    name_group_id: nameGroupId,
                    disambiguation: {
                        representative_work_title: "Work Beta",
                        linked_work_count: null,
                        birth_year: 1947,
                    },
                }),
            ],
            works: [],
            authorCursor: null,
            workCursor: null,
        }) });
    });
    await open(page);
    await search(page, "Peter King");

    await expect(page.locator(".biblio-ui__author-result")).toHaveCount(2);
    await expect(page.locator(".biblio-ui__author-result-context")).toHaveText([
        "Auteur van Work Alpha",
        "Geboren 1947 · Auteur van Work Beta",
    ]);
    await expect(page.locator(".biblio-ui__author-result", { hasText: "Biblio-catalogus" })).toHaveCount(0);
    await expect(page.locator(".biblio-ui__author-result", { hasText: "Externe bron" })).toHaveCount(0);
    await page.getByRole("tab", { name: "Auteurs" }).click();
    await expect(page.getByText("Meer mogelijke auteurs met deze naam")).toBeVisible();
    await expect(page.getByRole("button", { name: "Bekijk werken van Peter King, auteur van Work Alpha" })).toBeVisible();
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
        { name: null, width: 1800, height: 1000, columns: 5 },
        { name: "desktop-1440", width: 1440, height: 1000 },
        { name: null, width: 1200, height: 1000 },
        { name: "tablet-900", width: 900, height: 1000 },
        { name: "mobile-390", width: 390, height: 844, columns: 1 },
    ]) {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        await open(page);
        await search(page);
        await expect(page.locator(".biblio-ui__work-result")).toHaveCount(5);
        await noHorizontalOverflow(page);
        const geometry = await responsiveGeometry(page);
        expect(geometry.previewRight).toBeLessThanOrEqual(geometry.mainRight + 0.5);
        expect(geometry.maxCardRight).toBeLessThanOrEqual(geometry.mainRight + 0.5);
        expect(geometry.contentStaysInsideCards).toBe(true);
        if (viewport.columns) {
            expect(geometry.columns).toBe(viewport.columns);
        }
        if (viewport.width >= 1200) {
            expect(geometry.railLeft).toBeGreaterThan(geometry.mainRight);
            expect(geometry.maxCardRight).toBeLessThan(geometry.railLeft);
            if (viewport.width <= 1440) {
                expect(geometry.columns).toBeLessThanOrEqual(4);
            }
        } else {
            expect(geometry.railTop).toBeGreaterThanOrEqual(geometry.mainBottom);
        }
        if (viewport.name) {
            await capture(page, viewport.name);
        }
    }

    await page.setViewportSize({ width: 720, height: 900 });
    await open(page);
    await search(page);
    const cdp = await page.context().newCDPSession(page);
    await cdp.send("Emulation.setPageScaleFactor", { pageScaleFactor: 2 });
    await noHorizontalOverflow(page);
    const zoomGeometry = await responsiveGeometry(page);
    expect(zoomGeometry.columns).toBe(1);
    expect(zoomGeometry.previewRight).toBeLessThanOrEqual(zoomGeometry.mainRight + 0.5);
    expect(zoomGeometry.maxCardRight).toBeLessThanOrEqual(zoomGeometry.mainRight + 0.5);
    expect(zoomGeometry.contentStaysInsideCards).toBe(true);
    expect(zoomGeometry.railTop).toBeGreaterThanOrEqual(zoomGeometry.mainBottom);
    await expect(page.getByRole("searchbox", { name: "Zoek op titel of auteur" })).toBeEditable();
    await expect(page.getByRole("tab", { name: "Alles" })).toBeVisible();
    await expect(page.getByRole("button", { name: "Bekijk uitgaven" }).first()).toBeVisible();
    await expect(page.getByRole("button", { name: /Bekijk werken van/ }).first()).toBeVisible();
    await capture(page, "zoom-200-percent");
    await cdp.detach();
});
