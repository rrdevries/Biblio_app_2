import { expect, test } from "@playwright/test";
import { mkdir } from "node:fs/promises";

const LIBRARY_ID = "e2e-library-actor";
const LIBRARY_URL = `/mijn-bibliotheek/?library_id=${LIBRARY_ID}`;

async function expectNoHorizontalOverflow(page) {
    const widths = await page.evaluate(() => ({
        body: document.body.scrollWidth,
        document: document.documentElement.scrollWidth,
        viewport: window.innerWidth,
    }));
    expect(widths.body).toBeLessThanOrEqual(widths.viewport);
    expect(widths.document).toBeLessThanOrEqual(widths.viewport);
}

async function waitForFirstPage(page, action) {
    const response = page.waitForResponse((candidate) => {
        const url = new URL(candidate.url());
        return url.pathname.endsWith(`/libraries/${LIBRARY_ID}/catalog`)
            && !url.searchParams.has("cursor");
    });
    await action();
    expect((await response).ok()).toBe(true);
}

test("catalog search, filters, sort, URL state, archive and cursor stay server-driven", async ({ page }) => {
    const requests = [];
    page.on("request", (request) => {
        const url = new URL(request.url());
        if (url.pathname.endsWith(`/libraries/${LIBRARY_ID}/catalog`)) {
            requests.push(url);
        }
    });

    await page.goto(LIBRARY_URL);
    const search = page.getByRole("searchbox", { name: "Zoeken in deze bibliotheek" });
    const cards = page.locator("[data-biblio-view='overview'] [data-biblio-item-id]");
    await expect(cards).toHaveCount(24);

    await waitForFirstPage(page, () => search.pressSequentially("ZZZ Cursorboek", { delay: 10 }));
    await expect(cards).toHaveCount(24);
    await expect(search).toBeFocused();
    await expect(cards.locator("h3").first()).toHaveText("ZZZ Cursorboek 01");
    expect(new URL(page.url()).searchParams.get("catalog_search")).toBe("ZZZ Cursorboek");

    const loadMoreResponse = page.waitForResponse((candidate) => {
        const url = new URL(candidate.url());
        return url.pathname.endsWith(`/libraries/${LIBRARY_ID}/catalog`)
            && url.searchParams.has("cursor");
    });
    await page.getByRole("button", { name: "Meer laden" }).click();
    expect((await loadMoreResponse).ok()).toBe(true);
    await expect(cards).toHaveCount(25);
    const cursorRequest = requests.find((url) => url.searchParams.has("cursor"));
    expect(cursorRequest?.searchParams.get("search")).toBe("ZZZ Cursorboek");
    expect(cursorRequest?.searchParams.get("cursor")).toEqual(expect.any(String));

    const activeSearch = new URL(page.url()).searchParams.get("catalog_search");
    await page.getByRole("button", { name: "Lijst" }).click();
    await expect(page.locator("[data-biblio-view='overview'] [data-catalog-view]"))
        .toHaveAttribute("data-catalog-view", "list");
    expect(new URL(page.url()).searchParams.get("catalog_search")).toBe(activeSearch);
    await page.getByRole("button", { name: "Grid" }).click();

    await waitForFirstPage(page, () => search.fill("Dagboek"));
    await expect(cards).toHaveCount(1);
    await page.getByRole("button", { name: "Filters", exact: true }).click();
    const readingStatus = page.getByRole("group", { name: "Leesstatus" })
        .getByRole("checkbox", { name: "Niet gelezen" });
    await waitForFirstPage(page, () => readingStatus.check());
    await expect(readingStatus).toBeFocused();
    expect(new URL(page.url()).searchParams.get("catalog_reading_status")).toBe("not_read");
    const bookType = page.getByRole("group", { name: "Boeksoort" })
        .getByRole("checkbox", { name: "Leesboek" });
    await waitForFirstPage(page, () => bookType.check());
    expect(new URL(page.url()).searchParams.get("catalog_book_type")).toEqual(expect.any(String));

    const withoutCollection = page.getByRole("checkbox", { name: "Zonder collectie" });
    await waitForFirstPage(page, () => withoutCollection.check());
    await expect(withoutCollection).toBeFocused();
    expect(new URL(page.url()).searchParams.get("catalog_without_collection")).toBe("true");

    const sort = page.getByRole("combobox", { name: "Sorteren" });
    await sort.focus();
    await waitForFirstPage(page, () => sort.selectOption("author"));
    await expect(sort).toBeFocused();
    expect(new URL(page.url()).searchParams.get("catalog_sort")).toBe("author");

    const detailLink = cards.getByRole("link").first();
    await detailLink.click();
    await expect(page.locator("[data-biblio-view='detail'] h1")).toHaveText("Dagboek van een slecht jaar");
    expect(new URL(page.url()).searchParams.get("catalog_search")).toBe("Dagboek");
    await page.getByRole("link", { name: "Terug naar bibliotheek" }).click();
    await expect(search).toHaveValue("Dagboek");
    await expect(cards).toHaveCount(1);

    await waitForFirstPage(page, () => search.fill("Archived Catalog Evidence"));
    await expect(page.getByRole("heading", { name: "Geen boeken gevonden" })).toBeVisible();
    const archive = page.getByRole("checkbox", { name: "Ook in archief zoeken" });
    if (!await archive.isVisible()) {
        await page.getByRole("button", { name: /Filters/ }).click();
    }
    await waitForFirstPage(page, () => archive.check());
    await expect(cards).toHaveCount(1);
    await expect(cards).toContainText("Archief");
    await expect(cards.getByRole("link")).toHaveCount(0);
    expect(new URL(page.url()).searchParams.get("catalog_archive")).toBeNull();

    await page.reload();
    await expect(search).toHaveValue("Archived Catalog Evidence");
    await expect(page.getByRole("heading", { name: "Geen boeken gevonden" })).toBeVisible();
    await page.goto(LIBRARY_URL);
    await expect(search).toHaveValue("Archived Catalog Evidence");
    expect(new URL(page.url()).searchParams.get("catalog_archive")).toBeNull();
});

test("zero results, query failure and cursor failure expose distinct recovery", async ({ page }) => {
    let queryFailure = true;
    let cursorFailure = true;
    await page.route("**/catalog?**", async (route) => {
        const url = new URL(route.request().url());
        if (url.searchParams.get("search") === "Failure" && queryFailure) {
            queryFailure = false;
            await route.fulfill({
                status: 500,
                contentType: "application/json",
                body: JSON.stringify({ code: "server_error", message: "Test failure" }),
            });
            return;
        }
        if (url.searchParams.has("cursor") && cursorFailure) {
            cursorFailure = false;
            await route.fulfill({
                status: 500,
                contentType: "application/json",
                body: JSON.stringify({ code: "cursor_error", message: "Test cursor failure" }),
            });
            return;
        }
        await route.continue();
    });

    await page.goto(LIBRARY_URL);
    const search = page.getByRole("searchbox", { name: "Zoeken in deze bibliotheek" });
    await search.fill("bestaat beslist niet");
    await expect(page.getByRole("heading", { name: "Geen boeken gevonden" })).toBeVisible();
    await expect(page.getByText("Geen boeken passen bij deze zoekopdracht en filters.")).toBeVisible();

    await search.fill("Failure");
    await expect(page.getByRole("heading", { name: "Catalogus kon niet worden bijgewerkt" })).toBeVisible();
    await page.getByRole("button", { name: "Opnieuw proberen" }).click();
    await expect(page.getByRole("heading", { name: "Geen boeken gevonden" })).toBeVisible();

    await search.fill("ZZZ Cursorboek");
    const cards = page.locator("[data-biblio-view='overview'] [data-biblio-item-id]");
    await expect(cards).toHaveCount(24);
    await page.getByRole("button", { name: "Meer laden" }).click();
    await expect(page.getByRole("heading", { name: "Meer boeken konden niet worden geladen" })).toBeVisible();
    await expect(cards).toHaveCount(24);
    await page.getByRole("button", { name: "Opnieuw proberen" }).click();
    await expect(cards).toHaveCount(25);
});

test("catalog controls reflow at target widths and 200 percent zoom", async ({ page }, testInfo) => {
    await mkdir(".local/cat-ui-01-screenshots", { recursive: true });
    for (const viewport of [
        { width: 1440, height: 1000, label: "1440" },
        { width: 1024, height: 900, label: "1024" },
        { width: 768, height: 900, label: "768" },
        { width: 390, height: 844, label: "390" },
    ]) {
        await page.setViewportSize(viewport);
        await page.goto(LIBRARY_URL);
        await expect(page.getByRole("searchbox", { name: "Zoeken in deze bibliotheek" })).toBeVisible();
        await expectNoHorizontalOverflow(page);
        await page.screenshot({
            animations: "disabled",
            fullPage: true,
            path: `.local/cat-ui-01-screenshots/${viewport.label}.png`,
        });
    }

    await page.setViewportSize({ width: 768, height: 900 });
    const cdp = await page.context().newCDPSession(page);
    await cdp.send("Emulation.setPageScaleFactor", { pageScaleFactor: 2 });
    await expectNoHorizontalOverflow(page);
    await expect(page.getByRole("searchbox", { name: "Zoeken in deze bibliotheek" })).toBeVisible();
    await page.screenshot({
        animations: "disabled",
        fullPage: true,
        path: testInfo.outputPath("cat-ui-01-zoom-200.png"),
    });
    await cdp.detach();
});

test("approved desktop filter rail and mobile filter sheet preserve catalog controls", async ({ page }) => {
    await mkdir(".local/ui-mylib-01-screenshots", { recursive: true });
    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(LIBRARY_URL);
    const cards = page.locator("[data-biblio-view='overview'] [data-biblio-item-id]");
    await expect(cards).toHaveCount(24);
    const eye = cards.first().getByRole("button", { name: /Snel bekijken/ });
    await expect(eye).toHaveCSS("opacity", "0");
    await cards.first().locator(".biblio-ui__book-link").focus();
    await page.keyboard.press("Tab");
    await expect(eye).toBeFocused();
    await expect(eye).toHaveCSS("opacity", "1");
    expect(await eye.evaluate((node) => getComputedStyle(node).outlineStyle)).toBe("solid");
    await page.getByRole("heading", { name: "Mijn Bibliotheek" }).focus();
    await cards.first().hover();
    await expect(eye).toHaveCSS("opacity", "1");
    await page.screenshot({ animations: "disabled", fullPage: false, path: ".local/ui-mylib-01-screenshots/desktop-eye-hover.png" });
    await page.getByRole("button", { name: "Filters", exact: true }).click();
    const panel = page.locator("#biblio-filter-panel");
    await expect(panel).toBeVisible();
    const desktop = await page.evaluate(() => {
        const panel = document.querySelector("#biblio-filter-panel");
        const results = document.querySelector(".biblio-ui__catalog-main");
        return {
            panelLeft: panel.getBoundingClientRect().left,
            resultsRight: results.getBoundingClientRect().right,
            modal: panel.matches(":modal"),
            fit: getComputedStyle(document.querySelector(".biblio-ui__cover--overview")).objectFit,
            titleFont: getComputedStyle(document.querySelector(".biblio-ui__book-title")).fontFamily,
            bodyFont: getComputedStyle(document.querySelector("[data-biblio-view='overview']")).fontFamily,
            eyeIcon: getComputedStyle(document.querySelector("[data-biblio-view='overview'] [data-biblio-icon='eye']")).maskImage,
            railBackground: getComputedStyle(panel).backgroundColor,
            pageBackground: getComputedStyle(document.querySelector("[data-biblio-ui-root]")).backgroundColor,
            railRadius: getComputedStyle(panel).borderTopLeftRadius,
            railLeftBorder: getComputedStyle(panel).borderLeftWidth,
        };
    });
    expect(desktop.panelLeft).toBeGreaterThanOrEqual(desktop.resultsRight);
    expect(desktop.modal).toBe(false);
    expect(desktop.fit).toBe("contain");
    expect(desktop.titleFont).toContain("Biblio Catalog Cormorant Garamond");
    expect(desktop.bodyFont).toContain("Biblio Catalog Source Sans 3");
    expect(desktop.eyeIcon).toContain("tabler-eye.svg");
    expect(desktop.railBackground).toBe(desktop.pageBackground);
    expect(desktop.railRadius).toBe("0px");
    expect(desktop.railLeftBorder).toBe("1px");
    const loadedFonts = await page.evaluate(async () => {
        await document.fonts.ready;
        return Array.from(document.fonts)
            .filter((face) => face.family.startsWith("Biblio Catalog"))
            .map((face) => [face.family, face.status]);
    });
    expect(loadedFonts).toContainEqual(["Biblio Catalog Cormorant Garamond", "loaded"]);
    expect(loadedFonts).toContainEqual(["Biblio Catalog Source Sans 3", "loaded"]);
    await page.screenshot({ animations: "disabled", fullPage: true, path: ".local/ui-mylib-01-screenshots/desktop-rail.png" });
    const bookTypes = panel.getByRole("group", { name: "Boeksoort" });
    const conciseCount = await bookTypes.getByRole("checkbox").count();
    await bookTypes.getByRole("button", { name: "Meer lezen" }).click();
    expect(await bookTypes.getByRole("checkbox").count()).toBeGreaterThan(conciseCount);
    await bookTypes.getByRole("button", { name: "Minder tonen" }).click();

    await page.setViewportSize({ width: 390, height: 844 });
    await expect(panel).toBeVisible();
    expect(await panel.evaluate((node) => node.matches(":modal"))).toBe(true);
    await page.setViewportSize({ width: 1440, height: 900 });
    await expect(panel).toBeVisible();
    expect(await panel.evaluate((node) => node.matches(":modal"))).toBe(false);

    const reading = panel.getByRole("group", { name: "Leesstatus" })
        .getByRole("checkbox", { name: "Niet gelezen" });
    await expect(reading).toHaveCSS("appearance", "none");
    await reading.focus();
    expect(await reading.evaluate((node) => getComputedStyle(node).outlineStyle)).toBe("solid");
    await waitForFirstPage(page, () => reading.press("Space"));
    await expect(reading).toBeChecked();
    await expect(reading).toHaveCSS("background-color", "rgb(36, 59, 83)");
    await expect(page.locator(".biblio-ui__catalog-main .biblio-ui__filter-chips")).toBeVisible();
    await expect(page.locator("#biblio-filter-panel .biblio-ui__filter-chips")).toHaveCount(0);
    await page.getByRole("button", { name: "Alle filters wissen" }).click();
    await page.getByRole("button", { name: "Filters", exact: true }).click();

    await page.setViewportSize({ width: 390, height: 844 });
    await expectNoHorizontalOverflow(page);
    await expect(eye).toHaveCSS("opacity", "1");
    expect(await eye.evaluate((node) => node.getBoundingClientRect().width)).toBeGreaterThanOrEqual(44);
    await page.screenshot({ animations: "disabled", fullPage: false, path: ".local/ui-mylib-01-screenshots/mobile-books.png" });
    await page.getByRole("button", { name: "Filters", exact: true }).click();
    await expect(panel).toBeVisible();
    expect(await panel.evaluate((node) => node.matches(":modal"))).toBe(true);
    await expect(panel.getByRole("button", { name: "Filters sluiten" })).toBeFocused();
    await expect(cards).toHaveCount(24);
    const mobileReading = panel.getByRole("group", { name: "Leesstatus" })
        .getByRole("checkbox", { name: "Niet gelezen" });
    await waitForFirstPage(page, () => mobileReading.check());
    await expect(mobileReading).toBeChecked();
    await expect(mobileReading).toHaveCSS("background-image", /svg/);
    await page.screenshot({ animations: "disabled", fullPage: false, path: ".local/ui-mylib-01-screenshots/mobile-sheet.png" });
    await panel.press("Escape");
    await expect(panel).toHaveCount(0);
    await expect(page.getByRole("button", { name: /^Filters \(1\)$/ })).toBeFocused();
    await page.getByRole("button", { name: "Alle filters wissen" }).click();
    await page.getByRole("button", { name: "Filters", exact: true }).click();
    await panel.getByRole("button", { name: "Filters sluiten" }).click();
    await expect(panel).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Filters", exact: true })).toBeFocused();
    await page.getByRole("searchbox", { name: "Zoeken in deze bibliotheek" }).fill("Dagboek");
    await expect(page.locator(".biblio-ui__search-clear")).toBeVisible();
    const searchSpacing = await page.evaluate(() => ({
        padding: parseFloat(getComputedStyle(document.querySelector(".biblio-ui__search input")).paddingRight),
        clear: document.querySelector(".biblio-ui__search-clear").getBoundingClientRect().width,
    }));
    expect(searchSpacing.padding).toBeGreaterThan(searchSpacing.clear);
    await expectNoHorizontalOverflow(page);

    const touchContext = await page.context().browser().newContext({
        baseURL: process.env.BIBLIO_E2E_BASE_URL,
        storageState: await page.context().storageState(),
        ignoreHTTPSErrors: true,
        hasTouch: true,
        isMobile: true,
        viewport: { width: 390, height: 844 },
    });
    try {
        const touchPage = await touchContext.newPage();
        await touchPage.goto(LIBRARY_URL);
        const touchEye = touchPage.locator(".biblio-ui__catalog-list[data-catalog-view='grid'] .biblio-ui__quick-view-trigger").first();
        await expect(touchEye).toBeVisible();
        await expect(touchEye).toHaveCSS("opacity", "1");
        await touchEye.tap();
        await expect(touchPage.locator(".biblio-ui__quick-view")).toBeVisible();
    } finally {
        await touchContext.close();
    }
});
