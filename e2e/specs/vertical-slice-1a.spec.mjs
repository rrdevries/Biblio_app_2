import { execFileSync } from "node:child_process";
import { expect, test } from "@playwright/test";

const IDS = Object.freeze({
    actorLibrary: "e2e-library-actor",
    otherLibrary: "e2e-library-other",
    primaryItem: "e2e-item-primary",
    missingItem: "e2e-item-missing-metadata",
    conflictItem: "e2e-item-active-conflict",
    foreignItem: "e2e-item-foreign",
});

function libraryUrl(itemId = null, libraryId = IDS.actorLibrary) {
    const parameters = new URLSearchParams({ library_id: libraryId });

    if (itemId !== null) {
        parameters.set("item_id", itemId);
    }

    return `/mijn-bibliotheek/?${parameters.toString()}`;
}

function fixtureAction(action) {
    execFileSync("./scripts/e2e-fixture.sh", [action], {
        cwd: process.cwd(),
        stdio: "ignore",
    });
}

function definitionValue(page, label) {
    return page.locator("dt", { hasText: label })
        .locator("xpath=following-sibling::dd[1]");
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

test.describe.configure({ mode: "serial" });

test("primary flow logs in, starts reading once, rereads and survives reload", async ({ page }) => {
    await page.goto(libraryUrl());
    await expect(page.getByRole("heading", { name: "Mijn Bibliotheek" })).toBeVisible();
    await expect(page.getByText("E2E Privébibliotheek", { exact: true })).toBeVisible();
    expect(new URL(page.url()).searchParams.get("library_id")).toBe(IDS.actorLibrary);
    const cards = page.locator("[data-biblio-view='overview'] [data-biblio-item-id]");
    await expect(cards).toHaveCount(9);
    await expect(cards.locator("h3")).toHaveText([
        "Dagboek van een slecht jaar",
        "E2E Completed Flow",
        "E2E Idempotent Flow",
        "E2E Lifecycle Flow",
        "E2E Nonce Flow",
        "E2E Stale Flow",
        "E2E Stopped Flow",
        "The Secret Commonwealth",
        "Utopia Avenue",
    ]);

    await cards.filter({ hasText: "Dagboek van een slecht jaar" }).locator("a").click();
    await expect(page.locator("[data-biblio-view='detail'] h1")).toHaveText("Dagboek van een slecht jaar");
    expect(new URL(page.url()).searchParams.get("library_id")).toBe(IDS.actorLibrary);
    expect(new URL(page.url()).searchParams.get("item_id")).toBe(IDS.primaryItem);
    await expect(definitionValue(page, "Leesstatus")).toHaveText("Niet gelezen");

    let posts = 0;
    let detailRereads = 0;
    page.on("request", (request) => {
        if (request.method() === "POST" && request.url().endsWith(`/${IDS.primaryItem}/reading-rounds`)) {
            posts += 1;
        }
        if (request.method() === "GET" && request.url().endsWith(`/items/${IDS.primaryItem}`)) {
            detailRereads += 1;
        }
    });

    await page.getByRole("button", { name: "Lezen starten" }).click();
    const dateInput = page.locator("dialog input[type='date']");
    const localToday = await page.evaluate(() => {
        const now = new Date();
        return [
            now.getFullYear(),
            String(now.getMonth() + 1).padStart(2, "0"),
            String(now.getDate()).padStart(2, "0"),
        ].join("-");
    });
    await expect(dateInput).toHaveValue(localToday);

    const responsePromise = page.waitForResponse((response) => (
        response.request().method() === "POST"
        && response.url().endsWith(`/${IDS.primaryItem}/reading-rounds`)
    ));
    await page.locator("dialog").getByRole("button", { name: "Lezen starten" }).click();
    const response = await responsePromise;
    expect(response.status()).toBe(201);
    await expect(page.getByRole("status")).toHaveText("Lezen is gestart.");
    await expect(definitionValue(page, "Leesstatus")).toHaveText("Aan het lezen");
    await expect(definitionValue(page, "Actieve leesrondes")).toHaveText("1");
    expect(posts).toBe(1);
    expect(detailRereads).toBeGreaterThanOrEqual(1);

    await page.reload();
    expect(new URL(page.url()).searchParams.get("library_id")).toBe(IDS.actorLibrary);
    expect(new URL(page.url()).searchParams.get("item_id")).toBe(IDS.primaryItem);
    await expect(definitionValue(page, "Leesstatus")).toHaveText("Aan het lezen");
    await expect(definitionValue(page, "Actieve leesrondes")).toHaveText("1");
    await expect(page.getByRole("button", { name: "Lezen starten" })).toHaveCount(0);
});

test("foreign Item stays non-enumerating and leaks no foreign metadata", async ({ page }) => {
    const responsePromise = page.waitForResponse((response) => (
        response.request().method() === "GET"
        && response.url().endsWith(`/libraries/${IDS.actorLibrary}/items/${IDS.foreignItem}`)
    ));
    await page.goto(libraryUrl(IDS.foreignItem));
    const response = await responsePromise;
    expect(response.status()).toBe(404);
    expect(await response.json()).toMatchObject({
        code: "biblio_resource_not_available",
        data: { status: 404 },
    });
    await expect(page.getByRole("heading", { name: "Boek niet beschikbaar" })).toBeVisible();
    await expect(page.getByText("Dit boek bestaat niet of is niet meer toegankelijk.")).toBeVisible();
    await expect(page.locator("body")).not.toContainText("Ripper");
    await expect(page.locator("body")).not.toContainText(IDS.otherLibrary);
    await expect(page.locator(".biblio-ui__authors, .biblio-ui__metadata")).toHaveCount(0);
});

test("stale active-source submit maps 409, rereads and creates no duplicate", async ({ page }) => {
    fixtureAction("conflict-reset");
    await page.goto(libraryUrl(IDS.conflictItem));
    await expect(page.getByRole("button", { name: "Lezen starten" })).toBeVisible();
    fixtureAction("conflict-activate");

    let posts = 0;
    page.on("request", (request) => {
        if (request.method() === "POST" && request.url().endsWith(`/${IDS.conflictItem}/reading-rounds`)) {
            posts += 1;
        }
    });
    await page.getByRole("button", { name: "Lezen starten" }).click();
    const responsePromise = page.waitForResponse((response) => (
        response.request().method() === "POST"
        && response.url().endsWith(`/${IDS.conflictItem}/reading-rounds`)
    ));
    await page.locator("dialog").getByRole("button", { name: "Lezen starten" }).click();
    const response = await responsePromise;
    expect(response.status()).toBe(409);
    await expect(page.getByRole("status")).toHaveText("De leesstatus is gewijzigd.");
    await expect(definitionValue(page, "Actieve leesrondes")).toHaveText("1");
    await expect(page.getByRole("button", { name: "Lezen starten" })).toHaveCount(0);
    expect(posts).toBe(1);

    await page.reload();
    await expect(definitionValue(page, "Actieve leesrondes")).toHaveText("1");
});

test("invalid nonce is not retried, creates no round and refreshes safely", async ({ page }) => {
    await page.goto(libraryUrl(IDS.missingItem));
    let posts = 0;
    await page.route(`**/${IDS.missingItem}/reading-rounds`, async (route) => {
        posts += 1;
        await route.continue({
            headers: {
                ...route.request().headers(),
                "x-wp-nonce": "invalid-e2e-nonce",
            },
        });
    });

    await page.getByRole("button", { name: "Lezen starten" }).click();
    const responsePromise = page.waitForResponse((response) => (
        response.request().method() === "POST"
        && response.url().endsWith(`/${IDS.missingItem}/reading-rounds`)
    ));
    await page.locator("dialog").getByRole("button", { name: "Lezen starten" }).click();
    const response = await responsePromise;
    expect(response.status()).toBe(403);
    expect(await response.json()).toMatchObject({
        code: "rest_cookie_invalid_nonce",
        data: { status: 403 },
    });
    await expect(page.getByText("Je sessie moet worden vernieuwd.")).toBeVisible();
    expect(posts).toBe(1);

    await page.unroute(`**/${IDS.missingItem}/reading-rounds`);
    await page.getByRole("button", { name: "Sessie vernieuwen" }).click();
    await expect(definitionValue(page, "Leesstatus")).toHaveText("Niet gelezen");
    await expect(page.getByText("Actieve leesrondes")).toHaveCount(0);
    await expect(page.getByRole("button", { name: "Lezen starten" })).toBeVisible();
    expect(posts).toBe(1);
});

test("real-content responsive, target, keyboard and missing-metadata acceptance", async ({ page }) => {
    for (const viewport of [
        { width: 1440, height: 900 },
        { width: 768, height: 1024 },
        { width: 375, height: 812 },
    ]) {
        await page.setViewportSize(viewport);
        await page.goto(libraryUrl());
        await expect(page.locator("[data-biblio-view='overview']")).toBeVisible();
        await expectNoHorizontalOverflow(page);

        const cardLinks = page.locator(".biblio-ui__book-link");
        for (let index = 0; index < await cardLinks.count(); index += 1) {
            const box = await cardLinks.nth(index).boundingBox();
            expect(box?.height ?? 0).toBeGreaterThanOrEqual(44);
        }
    }

    await page.setViewportSize({ width: 375, height: 812 });
    const missingCard = page.locator(`[data-biblio-item-id='${IDS.missingItem}'] a`);
    await missingCard.focus();
    await expect(missingCard).toBeFocused();
    await page.keyboard.press("Enter");
    await expect(page.getByRole("heading", { name: "The Secret Commonwealth" })).toBeVisible();
    await expectNoHorizontalOverflow(page);
    await expect(page.locator(".biblio-ui__detail img")).toHaveCount(0);
    await expect(page.getByRole("heading", { name: "Uitgave" })).toHaveCount(0);
    await expect(page.getByRole("heading", { name: "Exemplaar" })).toHaveCount(0);

    const startButton = page.getByRole("button", { name: "Lezen starten" });
    const target = await startButton.boundingBox();
    expect(target?.height ?? 0).toBeGreaterThanOrEqual(44);
    await startButton.focus();
    await page.keyboard.press("Enter");
    await expect(page.locator("dialog input[type='date']")).toBeFocused();
    await page.keyboard.press("Escape");
    await expect(startButton).toBeFocused();
});

test("Deep Library shell, views, filters and Quick View recompose accessibly", async ({ page }, testInfo) => {
    await page.route("**/wp-json/biblio/v1/libraries/*/items**", async (route) => {
        if (route.request().method() !== "GET") {
            await route.continue();
            return;
        }

        const response = await route.fetch();
        const body = await response.json();
        const addCover = (record, index = 0) => ({
            ...record,
            cover_reference: {
                state: "known",
                value: "data:image/svg+xml," + encodeURIComponent(
                    `<svg xmlns="http://www.w3.org/2000/svg" width="296" height="444" viewBox="0 0 296 444">`
                    + `<rect width="296" height="444" fill="${index % 2 === 0 ? "#34425c" : "#866214"}"/>`
                    + `<rect x="22" y="22" width="252" height="400" fill="none" stroke="#f7f4ed"/>`
                    + `<text x="148" y="205" text-anchor="middle" fill="#f7f4ed" font-family="Georgia" font-size="24">Biblio</text>`
                    + `<text x="148" y="245" text-anchor="middle" fill="#f7f4ed" font-family="sans-serif" font-size="14">Testomslag</text>`
                    + "</svg>"
                ),
            },
        });

        if (Array.isArray(body?.data?.items)) {
            body.data.items = body.data.items.map(addCover);
        } else if (body?.data?.item_id) {
            body.data = addCover(body.data);
        }

        await route.fulfill({ response, json: body });
    });

    await page.setViewportSize({ width: 1440, height: 900 });
    await page.goto(libraryUrl());
    const shell = page.locator(".biblio-ui__shell");
    const sidebar = page.locator(".biblio-ui__sidebar");
    const workspace = page.locator(".biblio-ui__workspace");
    await expect(shell).toHaveAttribute("data-biblio-theme", "ink");
    await expect(shell).toHaveAttribute("data-biblio-appearance", "light");
    await expect(page.locator("[data-catalog-view='grid']")).toBeVisible();
    await expectNoHorizontalOverflow(page);
    expect(Math.round((await sidebar.boundingBox())?.width ?? 0)).toBe(224);

    const covers = page.locator(".biblio-ui__cover--overview");
    await expect(covers).toHaveCount(9);
    expect(Math.round((await covers.first().boundingBox())?.width ?? 0)).toBe(148);
    await page.screenshot({
        path: testInfo.outputPath("deep-library-desktop-grid.png"),
        fullPage: true,
    });

    await page.getByRole("button", { name: "Navigatie inklappen" }).click();
    await expect(shell).toHaveAttribute("data-sidebar-collapsed", "true");
    expect(Math.round((await sidebar.boundingBox())?.width ?? 0)).toBe(72);
    await page.reload();
    await expect(shell).toHaveAttribute("data-sidebar-collapsed", "true");
    await page.screenshot({
        path: testInfo.outputPath("deep-library-desktop-rail.png"),
        fullPage: true,
    });
    await page.getByRole("button", { name: "Navigatie uitklappen" }).click();

    await page.getByRole("button", { name: "Filters" }).click();
    await expect(page.getByText("Gedetailleerde filters")).toBeVisible();
    await expect(page.getByRole("searchbox", { name: "Zoeken" })).toBeDisabled();
    await expect(page.getByRole("combobox", { name: "Sorteren" })).toBeDisabled();
    await expect(page.getByText(/Library-REST-contract/)).toBeVisible();

    await page.getByRole("button", { name: "Lijst", exact: true }).click();
    await expect(page.locator("[data-catalog-view='list']")).toBeVisible();
    await page.getByRole("button", { name: "Boekenplank", exact: true }).click();
    await expect(page.getByRole("heading", { name: "Boekenplank" })).toBeVisible();
    await page.screenshot({
        path: testInfo.outputPath("deep-library-bookshelf-placeholder.png"),
        fullPage: true,
    });
    await page.getByRole("button", { name: "Terug naar Grid" }).click();

    const workspaceBefore = await workspace.boundingBox();
    const quickTrigger = page.getByRole("button", { name: /Snel bekijken:/ }).first();
    await quickTrigger.click();
    const quickView = page.locator("dialog.biblio-ui__quick-view");
    await expect(quickView).toBeVisible();
    await expect(quickView.getByRole("link", { name: "Volledige boekdetails" })).toBeVisible();
    const workspaceAfter = await workspace.boundingBox();
    expect(Math.round(workspaceAfter?.width ?? 0)).toBe(Math.round(workspaceBefore?.width ?? 0));
    await page.screenshot({
        path: testInfo.outputPath("deep-library-quick-view.png"),
        fullPage: true,
    });
    await page.keyboard.press("Escape");
    await expect(quickView).toHaveCount(0);
    await expect(quickTrigger).toBeFocused();

    await page.setViewportSize({ width: 900, height: 1000 });
    await expectNoHorizontalOverflow(page);
    await page.screenshot({
        path: testInfo.outputPath("deep-library-tablet.png"),
        fullPage: true,
    });

    await page.setViewportSize({ width: 375, height: 812 });
    await expectNoHorizontalOverflow(page);
    const menu = page.getByRole("button", { name: "Navigatie openen" });
    await expect(menu).toBeVisible();
    await menu.click();
    await expect(shell).toHaveAttribute("data-mobile-nav-open", "true");
    await expect(sidebar).toBeVisible();
    await page.keyboard.press("Escape");
    await expect(shell).toHaveAttribute("data-mobile-nav-open", "false");
    await expect(menu).toBeFocused();
    await page.screenshot({
        path: testInfo.outputPath("deep-library-mobile-grid.png"),
        fullPage: true,
    });
});
