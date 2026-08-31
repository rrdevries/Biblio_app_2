import { expect, test } from "@playwright/test";

const IDS = Object.freeze({
    actorLibrary: "e2e-library-other",
    historyWork: "e2e-work-history",
    historyItem: "e2e-item-history",
    sameEditionItem: "e2e-item-history-same-edition",
    otherEditionItem: "e2e-item-history-other-edition",
    zeroItem: "e2e-item-history-zero",
    activeItem: "e2e-item-history-active-only",
    endItem: "e2e-item-history-end",
    refreshItem: "e2e-item-history-refresh",
    rapidItem: "e2e-item-history-rapid",
});

const HISTORY_PATH = `/me/works/${IDS.historyWork}/reading-history`;

function libraryUrl(itemId = null) {
    const parameters = new URLSearchParams({ library_id: IDS.actorLibrary });

    if (itemId !== null) {
        parameters.set("item_id", itemId);
    }

    return `/mijn-bibliotheek/?${parameters.toString()}`;
}

function historyRegion(page) {
    return page.locator("[data-biblio-reading-history]");
}

function historyEntries(page) {
    return historyRegion(page).getByRole("listitem");
}

function definitionValue(page, label) {
    return page.locator("dt", { hasText: label })
        .locator("xpath=following-sibling::dd[1]");
}

function isHistoryRequest(request, workId = IDS.historyWork) {
    const url = new URL(request.url());

    return request.method() === "GET"
        && url.pathname.endsWith(`/me/works/${workId}/reading-history`);
}

function isDetailRequest(request, itemId) {
    const url = new URL(request.url());

    return request.method() === "GET"
        && url.pathname.endsWith(
            `/libraries/${IDS.actorLibrary}/items/${itemId}`
        );
}

function restError(code, status, message = "Veilige E2E-fout") {
    return {
        status,
        contentType: "application/json",
        body: JSON.stringify({ code, message, data: { status } }),
    };
}

async function expectNoHorizontalOverflow(page) {
    const dimensions = await page.evaluate(() => ({
        body: document.body.scrollWidth,
        document: document.documentElement.scrollWidth,
        viewport: window.innerWidth,
    }));
    expect(dimensions.body).toBeLessThanOrEqual(dimensions.viewport);
    expect(dimensions.document).toBeLessThanOrEqual(dimensions.viewport);
}

async function chooseOutcomeAndDate(page, outcome, date) {
    await page.getByRole("button", { name: "Leesronde afronden" }).click();
    const dialog = page.getByRole("dialog", { name: "Leesronde afronden" });
    await dialog.getByRole("radio", { name: outcome }).check();
    await dialog.getByLabel("Einddatum").fill(date);
    return dialog;
}

test.describe.configure({ mode: "serial" });

test("Work-wide ended history is private, newest-first and survives reload", async ({ page }) => {
    const responsePromise = page.waitForResponse((response) => (
        isHistoryRequest(response.request()) && response.status() === 200
    ));
    await page.goto(libraryUrl(IDS.historyItem));
    const response = await responsePromise;
    const payload = await response.json();

    await expect(page.getByRole("heading", { level: 1, name: "E2E Leesgeschiedenis" })).toBeVisible();
    await expect(page.getByRole("heading", { level: 2, name: "Leesgeschiedenis" })).toBeVisible();
    await expect(historyEntries(page)).toHaveCount(10);
    await expect(definitionValue(page, "Leesstatus")).toHaveText("Aan het lezen");
    await expect(historyEntries(page).first()).toContainText("Uitgelezen");
    await expect(historyEntries(page).first()).toContainText(
        "12 maart 2025 – 13 december 2025"
    );
    await expect(historyEntries(page).nth(1)).toContainText("Gestopt");
    await expect(historyEntries(page).nth(1)).toContainText(
        "1 december 2025 – 12 december 2025"
    );
    await expect(historyEntries(page).nth(2)).toContainText("11 december 2025");
    await expect(historyEntries(page).nth(3)).toContainText("10 december 2025");
    await expect(historyEntries(page).nth(4)).toContainText("Externe lening");
    await expect(historyRegion(page)).not.toContainText("1 januari 2026");
    await expect(historyRegion(page)).not.toContainText("e2e-");
    expect(payload.data.items).toHaveLength(10);
    expect(payload.data.next_cursor).toEqual(expect.any(String));
    expect(payload.data.items[0].finished_on).toEqual({
        year: 2025,
        month: 12,
        day: 13,
    });
    expect(JSON.stringify(payload.data)).not.toContain("reading_round_id");
    expect(JSON.stringify(payload.data)).not.toContain("2026-01-01");

    await page.reload();
    await expect(historyEntries(page)).toHaveCount(10);
    await expect(historyEntries(page).first()).toContainText("13 december 2025");
});

test("zero and active-only Works render no history section or controls", async ({ page }) => {
    for (const [itemId, expectedStatus] of [
        [IDS.zeroItem, "Niet gelezen"],
        [IDS.activeItem, "Aan het lezen"],
    ]) {
        await page.goto(libraryUrl(itemId));
        await expect(definitionValue(page, "Leesstatus")).toHaveText(expectedStatus);
        await expect(historyRegion(page)).toHaveAttribute("aria-busy", "false");
        await expect(page.getByRole("heading", { level: 2, name: "Leesgeschiedenis" })).toHaveCount(0);
        await expect(historyEntries(page)).toHaveCount(0);
        await expect(page.getByRole("button", { name: "Meer laden" })).toHaveCount(0);
    }
});

test("pagination appends one cursor page without duplicates and preserves precision", async ({ page }) => {
    const historyUrls = [];
    page.on("request", (request) => {
        if (isHistoryRequest(request)) {
            historyUrls.push(request.url());
        }
    });

    await page.goto(libraryUrl(IDS.historyItem));
    await expect(historyEntries(page)).toHaveCount(10);
    const button = page.getByRole("button", { name: "Meer laden" });
    await expect(button).toBeVisible();
    await button.click();
    await expect(historyEntries(page)).toHaveCount(13);
    await expect(page.getByRole("button", { name: "Meer laden" })).toHaveCount(0);
    await expect(page.getByRole("heading", { level: 2, name: "Leesgeschiedenis" })).toBeFocused();
    expect(historyUrls).toHaveLength(2);
    expect(new URL(historyUrls[0]).searchParams.get("cursor")).toBeNull();
    expect(new URL(historyUrls[1]).searchParams.get("cursor")).toEqual(expect.any(String));

    const text = await historyEntries(page).allTextContents();
    expect(new Set(text).size).toBe(13);
    expect(text[10]).toContain("maart 2025 – maart 2025");
    expect(text[10]).toContain("Historische registratie");
    expect(text[11]).toContain("Afgerond 2 februari 2025");
    expect(text[11]).not.toContain("31 december 2024");
    expect(text[12]).toContain("Afgerond 2024");
    expect(text[12]).toContain("Historische registratie");
    expect(text[12]).not.toContain("1 januari 2024");
    expect(text.join(" ")).not.toContain("Exemplaar");
    expect(text.join(" ")).not.toContain("Library");
});

test("initial history failure stays local and explicit retry does not reload detail", async ({ page }) => {
    let historyGets = 0;
    let detailGets = 0;
    await page.route(`**${HISTORY_PATH}?*`, async (route) => {
        historyGets += 1;

        if (historyGets === 1) {
            await route.fulfill(restError("biblio_core_unavailable", 503));
            return;
        }

        await route.continue();
    });
    page.on("request", (request) => {
        if (isDetailRequest(request, IDS.historyItem)) {
            detailGets += 1;
        }
    });

    await page.goto(libraryUrl(IDS.historyItem));
    await expect(page.getByRole("heading", { level: 1, name: "E2E Leesgeschiedenis" })).toBeVisible();
    await expect(definitionValue(page, "Leesstatus")).toHaveText("Aan het lezen");
    await expect(historyRegion(page).getByText("Leesgeschiedenis kon niet worden geladen.")).toBeVisible();
    await expect(page.getByRole("heading", { level: 2, name: "Leesgeschiedenis" })).toHaveCount(0);
    expect(historyGets).toBe(1);
    expect(detailGets).toBe(1);

    await historyRegion(page).getByRole("button", { name: "Opnieuw proberen" }).click();
    await expect(historyEntries(page)).toHaveCount(10);
    expect(historyGets).toBe(2);
    expect(detailGets).toBe(1);
});

test("pagination failure retains page one and retries the identical cursor once", async ({ page }) => {
    const cursorUrls = [];
    let failCursorOnce = true;
    await page.route(`**${HISTORY_PATH}?*`, async (route) => {
        const url = new URL(route.request().url());

        if (url.searchParams.has("cursor")) {
            cursorUrls.push(url.toString());

            if (failCursorOnce) {
                failCursorOnce = false;
                await route.fulfill(restError("biblio_internal_error", 500));
                return;
            }
        }

        await route.continue();
    });

    await page.goto(libraryUrl(IDS.historyItem));
    await expect(historyEntries(page)).toHaveCount(10);
    await page.getByRole("button", { name: "Meer laden" }).click();
    const retry = historyRegion(page).getByRole("button", { name: "Opnieuw proberen" });
    await expect(historyEntries(page)).toHaveCount(10);
    await expect(historyRegion(page).getByText("Meer leesgeschiedenis kon niet worden geladen.")).toBeVisible();
    await expect(retry).toBeFocused();
    await retry.click();
    await expect(historyEntries(page)).toHaveCount(13);
    expect(cursorUrls).toHaveLength(2);
    expect(new URL(cursorUrls[0]).searchParams.get("cursor"))
        .toBe(new URL(cursorUrls[1]).searchParams.get("cursor"));
});

test("invalid history nonce shows session recovery without a request loop", async ({ page }) => {
    let historyGets = 0;
    await page.route(`**${HISTORY_PATH}?*`, async (route) => {
        historyGets += 1;
        await route.fulfill(restError(
            "rest_cookie_invalid_nonce",
            403,
            "Cookie check failed"
        ));
    });

    await page.goto(libraryUrl(IDS.historyItem));
    await expect(page.getByRole("heading", { level: 1, name: "E2E Leesgeschiedenis" })).toBeVisible();
    await expect(historyRegion(page).getByText("Leesgeschiedenis kon niet worden geladen.")).toBeVisible();
    await expect(historyRegion(page).getByRole("button", { name: "Sessie vernieuwen" })).toBeVisible();
    expect(historyGets).toBe(1);
});

test("End Reading rereads detail then refreshes page one from server truth", async ({ page }) => {
    const sequence = [];
    let observe = false;
    let posts = 0;
    let holdRefresh = false;
    let releaseRefresh;
    let refreshIntercepted;
    const refreshGate = new Promise((resolve) => { releaseRefresh = resolve; });
    const refreshSeen = new Promise((resolve) => { refreshIntercepted = resolve; });

    page.on("request", (request) => {
        if (!observe) {
            return;
        }

        if (request.method() === "POST" && request.url().endsWith("/end")) {
            posts += 1;
            sequence.push("POST end");
        } else if (isDetailRequest(request, IDS.endItem)) {
            sequence.push("GET detail");
        } else if (isHistoryRequest(request, "e2e-work-history-end")) {
            sequence.push("GET history");
        }
    });
    await page.route("**/me/works/e2e-work-history-end/reading-history?*", async (route) => {
        if (!holdRefresh) {
            await route.continue();
            return;
        }

        refreshIntercepted();
        await refreshGate;
        await route.continue();
    });

    await page.goto(libraryUrl(IDS.endItem));
    await expect(historyEntries(page)).toHaveCount(1);
    await expect(historyEntries(page)).toContainText(["Afgerond 2023"]);
    observe = true;
    holdRefresh = true;
    const dialog = await chooseOutcomeAndDate(page, "Uitgelezen", "2026-08-20");
    await dialog.getByRole("button", { name: "Afronden" }).click();
    await refreshSeen;

    await expect(definitionValue(page, "Leesstatus")).toHaveText("Uitgelezen");
    await expect(historyEntries(page)).toHaveCount(1);
    await expect(historyRegion(page)).not.toContainText("20 augustus 2026");
    expect(sequence).toEqual(["POST end", "GET detail", "GET history"]);
    expect(posts).toBe(1);
    releaseRefresh();

    await expect(historyEntries(page)).toHaveCount(2);
    await expect(historyEntries(page).first()).toContainText(
        "4 januari 2026 – 20 augustus 2026"
    );
    await expect(page.getByRole("status")).toHaveText("De leesstatus is bijgewerkt.");
    await expect(page.getByRole("status")).toBeFocused();
    expect(posts).toBe(1);

    await page.reload();
    await expect(historyEntries(page)).toHaveCount(2);
    await expect(historyEntries(page).first()).toContainText("20 augustus 2026");
});

test("failed post-End history refresh preserves ended detail and retries only GET", async ({ page }) => {
    let failRefresh = false;
    let posts = 0;
    let historyGetsAfterEnd = 0;
    page.on("request", (request) => {
        if (request.method() === "POST" && request.url().endsWith("/end")) {
            posts += 1;
        }

        if (failRefresh && isHistoryRequest(request, "e2e-work-history-refresh")) {
            historyGetsAfterEnd += 1;
        }
    });
    await page.route("**/me/works/e2e-work-history-refresh/reading-history?*", async (route) => {
        if (failRefresh && historyGetsAfterEnd === 1) {
            await route.fulfill(restError("biblio_core_unavailable", 503));
            return;
        }

        await route.continue();
    });

    await page.goto(libraryUrl(IDS.refreshItem));
    await expect(historyEntries(page)).toHaveCount(1);
    failRefresh = true;
    const dialog = await chooseOutcomeAndDate(page, "Uitgelezen", "2026-08-21");
    await dialog.getByRole("button", { name: "Afronden" }).click();

    await expect(definitionValue(page, "Leesstatus")).toHaveText("Uitgelezen");
    await expect(historyRegion(page).getByText(
        "De leesstatus is bijgewerkt, maar de leesgeschiedenis kon niet worden vernieuwd."
    )).toBeVisible();
    await expect(historyEntries(page)).toHaveCount(1);
    expect(posts).toBe(1);
    expect(historyGetsAfterEnd).toBe(1);

    await historyRegion(page).getByRole("button", { name: "Opnieuw proberen" }).click();
    await expect(historyEntries(page)).toHaveCount(2);
    await expect(historyEntries(page).first()).toContainText("21 augustus 2026");
    expect(posts).toBe(1);
    expect(historyGetsAfterEnd).toBe(2);
});

test("deep links, back navigation and delayed stale responses keep Work history isolated", async ({ page }) => {
    let releaseHistory;
    let historyIntercepted;
    const release = new Promise((resolve) => { releaseHistory = resolve; });
    const intercepted = new Promise((resolve) => { historyIntercepted = resolve; });
    await page.route(`**${HISTORY_PATH}?*`, async (route) => {
        historyIntercepted();
        await release;

        try {
            await route.continue();
        } catch {
            // Navigation may have cancelled the deliberately stale request.
        }
    });

    await page.goto(libraryUrl());
    await page.locator(`[data-biblio-item-id='${IDS.historyItem}']`)
        .getByRole("link")
        .click();
    await intercepted;
    await expect(page.getByRole("heading", { level: 1, name: "E2E Leesgeschiedenis" })).toBeVisible();
    await page.getByRole("link", { name: "Terug naar bibliotheek" }).click();
    await expect(page.locator("[data-biblio-view='overview']")).toBeVisible();
    await page.locator(`[data-biblio-item-id='${IDS.rapidItem}']`)
        .getByRole("link")
        .click();
    await expect(page.getByRole("heading", { level: 1, name: "E2E Andere Geschiedenis" })).toBeVisible();
    await expect(historyEntries(page)).toHaveCount(1);
    await expect(historyEntries(page).first()).toContainText(
        "1 januari 2023 – 2 januari 2023"
    );
    releaseHistory();
    await expect(historyRegion(page)).not.toContainText("13 december 2025");

    await page.reload();
    await expect(historyEntries(page)).toHaveCount(1);
    await page.goBack();
    await expect(page.locator("[data-biblio-view='overview']")).toBeVisible();
    await page.goForward();
    await expect(historyEntries(page)).toHaveCount(1);
    await expect(historyRegion(page)).not.toContainText("13 december 2025");
});

test("live DOM remains semantic, keyboard-safe and overflow-free at all breakpoints", async ({ page }) => {
    for (const viewport of [
        { width: 390, height: 844 },
        { width: 900, height: 900 },
        { width: 1280, height: 900 },
    ]) {
        await page.setViewportSize(viewport);
        await page.goto(libraryUrl(IDS.historyItem));
        await expect(historyEntries(page)).toHaveCount(10);
        await expect(page.getByRole("heading", { level: 1 })).toHaveCount(1);
        await expect(page.getByRole("heading", { level: 2, name: "Leesgeschiedenis" })).toHaveCount(1);
        await expect(historyRegion(page).getByRole("list")).toHaveCount(1);
        await expect(historyRegion(page).locator("ul > li")).toHaveCount(10);
        await expect(historyRegion(page).locator("button button, button a, a button")).toHaveCount(0);
        const more = page.getByRole("button", { name: "Meer laden" });
        await expect(more).toHaveAttribute("type", "button");
        const target = await more.boundingBox();
        expect(target?.height ?? 0).toBeGreaterThanOrEqual(44);
        await expectNoHorizontalOverflow(page);

        const widths = await page.evaluate(() => {
            const region = document.querySelector("[data-biblio-reading-history]");
            const detail = document.querySelector(".biblio-ui__detail");

            return {
                region: region?.scrollWidth ?? 0,
                regionClient: region?.clientWidth ?? 0,
                detail: detail?.clientWidth ?? 0,
            };
        });
        expect(widths.region).toBeLessThanOrEqual(widths.regionClient);
        expect(widths.regionClient).toBeLessThanOrEqual(widths.detail);

        if (viewport.width === 390) {
            await more.focus();
            await page.keyboard.press("Enter");
            await expect(historyEntries(page)).toHaveCount(13);
            await expect(page.getByRole("heading", { level: 2, name: "Leesgeschiedenis" })).toBeFocused();
            await expectNoHorizontalOverflow(page);
        }
    }
});

test("loading and successful history updates are polite and never steal initial focus", async ({ page }) => {
    let releaseHistory;
    let historyIntercepted;
    const release = new Promise((resolve) => { releaseHistory = resolve; });
    const intercepted = new Promise((resolve) => { historyIntercepted = resolve; });
    await page.route(`**${HISTORY_PATH}?*`, async (route) => {
        historyIntercepted();
        await release;
        await route.continue();
    });

    await page.goto(libraryUrl(IDS.historyItem));
    await intercepted;
    const stableControl = page.getByRole("button", { name: "Leesronde afronden" });
    await stableControl.focus();
    await expect(historyRegion(page)).toHaveAttribute("aria-busy", "true");
    await expect(historyRegion(page).getByText("Leesgeschiedenis laden…"))
        .toHaveAttribute("aria-live", "polite");
    releaseHistory();
    await expect(historyEntries(page)).toHaveCount(10);
    await expect(stableControl).toBeFocused();
});
