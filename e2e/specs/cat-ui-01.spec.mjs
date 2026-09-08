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
    await page.getByRole("button", { name: "Filters" }).click();
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
